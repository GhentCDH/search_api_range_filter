<?php

namespace Drupal\views_range_filter\Plugin\views\filter;

use Drupal\search_api\Entity\Index;
use Drupal\search_api\Plugin\views\filter\SearchApiFilterTrait;
use Drupal\search_api\Plugin\views\query\SearchApiQuery;

/**
 * Search API Views filter for range-overlap queries across two index fields.
 *
 * @ViewsFilter("views_range_filter_sapi")
 */
class RangeFilterSapi extends RangeFilterBase {

  use SearchApiFilterTrait;

  // ---------------------------------------------------------------------------
  // Field options
  // ---------------------------------------------------------------------------

  protected function getFieldOptions(): array {
    $index = $this->getIndex();
    if (!$index instanceof Index) {
      return [];
    }

    $range_types = ['date', 'integer', 'decimal', 'float'];
    $fields = [];

    foreach ($index->getFields() as $field_id => $field) {
      if (in_array($field->getType(), $range_types, TRUE)) {
        $fields[$field_id] = $field->getLabel() . ' [' . $field_id . ']';
      }
    }

    return $fields;
  }

  protected function getNoFieldsMessage(): string {
    return (string) $this->t(
      'No range-capable fields (date, integer, decimal, float) found in this index. '
      . 'Make sure the view is backed by a Search API index and the relevant fields are indexed.'
    );
  }

  // ---------------------------------------------------------------------------
  // Auto min/max via Search API queries
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   *
   * Runs two lightweight Search API queries (1 result each, sorted ASC/DESC)
   * to find the actual minimum and maximum values in the index.
   *
   * Results are cached for one hour, tagged with the index cache tags so they
   * invalidate automatically when the index is updated.
   */
  protected function resolveAutoMinMax(string $field_id): ?array {
    $index = $this->getIndex();
    if (!$index instanceof Index || !$field_id) {
      return NULL;
    }

    $cache_id  = 'views_range_filter:auto_minmax:' . $index->id() . ':' . $field_id;
    $cache_bin = \Drupal::cache('data');

    if ($cached = $cache_bin->get($cache_id)) {
      return $cached->data;
    }

    try {
      $result = $this->queryMinMax($index, $field_id);
    }
    catch (\Exception $e) {
      \Drupal::logger('views_range_filter')->warning(
        'Auto min/max query failed for field @field on index @index: @msg',
        ['@field' => $field_id, '@index' => $index->id(), '@msg' => $e->getMessage()]
      );
      return NULL;
    }

    if ($result !== NULL) {
      $cache_bin->set(
        $cache_id,
        $result,
        \Drupal::time()->getRequestTime() + 3600,
        $index->getCacheTags()
      );
    }

    return $result;
  }

  /**
   * Runs the actual min/max queries against the Search API index.
   *
   * Uses two separate queries (sorted ASC and DESC, limited to 1 result)
   * so this works with any Search API backend.
   *
   * @return array{min: mixed, max: mixed}|null
   */
  protected function queryMinMax(Index $index, string $field_id): ?array {
    $get_boundary = function (string $order) use ($index, $field_id): mixed {
      $query = $index->query();
      $query->range(0, 1);
      $query->sort($field_id, $order);
      // Bypass entity access so the range reflects all indexed content.
      $query->setOption('search_api_bypass_access', TRUE);
      // Ask the backend to return field values with results.
      $query->setOption('search_api_retrieved_field_values', [$field_id]);

      $results = $query->execute();
      $items   = $results->getResultItems();

      if (empty($items)) {
        return NULL;
      }

      $item   = reset($items);
      $field  = $item->getField($field_id);
      $values = $field ? $field->getValues() : [];

      return empty($values) ? NULL : reset($values);
    };

    $min = $get_boundary('ASC');
    $max = $get_boundary('DESC');

    if ($min === NULL || $max === NULL) {
      return NULL;
    }

    $index_field = $index->getField($field_id);

    return [
      'min'  => $min,
      'max'  => $max,
      'type' => $index_field ? $index_field->getType() : 'integer',
    ];
  }

  // ---------------------------------------------------------------------------
  // Query
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   *
   * Single-field mode: field >= from AND field <= to (no COALESCE needed).
   *
   * Two-field mode (overlap formula):
   *   (end >= from  OR  (end IS NULL AND start >= from))
   *   AND
   *   (start <= to  OR  (start IS NULL AND end <= to))
   */
  public function query(): void {
    $query = $this->getQuery();
    if (!$query instanceof SearchApiQuery) {
      return;
    }

    $start_field = $this->options['start_field'] ?? '';
    $end_field   = $this->options['end_field']   ?? '';

    if (!$start_field) {
      return;
    }

    $values   = is_array($this->value) ? $this->value : [];
    $from_val = $this->sanitizeRangeValue((string) ($values['from'] ?? ''));
    $to_val   = $this->sanitizeRangeValue((string) ($values['to']   ?? ''));

    if ($from_val === '' && $to_val === '') {
      return;
    }

    // Backend-aware date conversion for date-type fields with the year dropdown.
    $index = $this->getIndex();
    if ($index instanceof Index) {
      $field = $index->getField($start_field);
      if ($field && $field->getType() === 'date') {
        if ($from_val !== '' && is_numeric($from_val)) {
          $from_val = $this->convertYearForBackend($index, (int) $from_val, '>=');
        }
        if ($to_val !== '' && is_numeric($to_val)) {
          $to_val = $this->convertYearForBackend($index, (int) $to_val, '<=');
        }
      }
    }

    // Single-field mode: straightforward range on one field.
    if (!empty($this->options['single_field_mode']) || $start_field === $end_field) {
      if ($from_val !== '') {
        $query->addCondition($start_field, $from_val, '>=');
      }
      if ($to_val !== '') {
        $query->addCondition($start_field, $to_val, '<=');
      }
      return;
    }

    // Two-field overlap mode.
    $overlap = $query->createConditionGroup('AND');

    if ($from_val !== '') {
      $from_or = $query->createConditionGroup('OR');
      $from_or->addCondition($end_field, $from_val, '>=');

      $end_missing = $query->createConditionGroup('AND');
      $end_missing->addCondition($end_field, NULL, '=');
      $end_missing->addCondition($start_field, $from_val, '>=');
      $from_or->addConditionGroup($end_missing);

      $overlap->addConditionGroup($from_or);
    }

    if ($to_val !== '') {
      $to_or = $query->createConditionGroup('OR');
      $to_or->addCondition($start_field, $to_val, '<=');

      $start_missing = $query->createConditionGroup('AND');
      $start_missing->addCondition($start_field, NULL, '=');
      $start_missing->addCondition($end_field, $to_val, '<=');
      $to_or->addConditionGroup($start_missing);

      $overlap->addConditionGroup($to_or);
    }

    $query->addConditionGroup($overlap);
  }

  // ---------------------------------------------------------------------------
  // Backend-aware date conversion
  // ---------------------------------------------------------------------------

  /**
   * Converts a plain year integer to the correct value for the active backend.
   *
   * | Backend          | Storage format      | Returns         |
   * |------------------|---------------------|-----------------|
   * | search_api_db    | Unix timestamp      | int             |
   * | Elasticsearch    | ISO 8601 string     | string          |
   * | Solr             | ISO 8601 string     | string          |
   * | unknown          | ISO 8601 string     | string          |
   */
  protected function convertYearForBackend(Index $index, int $year, string $operator): int|string {
    $backend_id = '';

    try {
      $backend_id = $index->getServerInstance()->getBackendId();
    }
    catch (\Exception $e) {
      // Server unreachable; fall through to ISO 8601.
    }

    $is_lower = in_array($operator, ['>=', '>'], TRUE);

    if ($backend_id === 'search_api_db') {
      return $is_lower
        ? mktime(0, 0, 0, 1, 1, $year)
        : mktime(23, 59, 59, 12, 31, $year);
    }

    // Elasticsearch, Solr, unknown: ISO 8601.
    return $is_lower
      ? date('c', mktime(0, 0, 0, 1, 1, $year))
      : date('c', mktime(23, 59, 59, 12, 31, $year));
  }

}