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
   * @return array{min: mixed, max: mixed, type: string}|null
   */
  protected function queryMinMax(Index $index, string $field_id): ?array {
    $get_boundary = function (string $order) use ($index, $field_id): mixed {
      $query = $index->query();
      $query->range(0, 1);
      $query->sort($field_id, $order);
      $query->setOption('search_api_bypass_access', TRUE);
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
   * In date mode, each field receives a value converted to its own format:
   *   - date-type fields get a Unix timestamp (search_api_db) or ISO 8601 string
   *   - integer-type fields receive the year as a plain integer
   *
   * In numeric mode, values are compared as-is.
   *
   * Two-field overlap formula (per field, with independent converted values):
   *   (end >= from_for_end  OR  (end IS NULL AND start >= from_for_start))
   *   AND
   *   (start <= to_for_start  OR  (start IS NULL AND end <= to_for_end))
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
    $from_raw = $this->sanitizeRangeValue((string) ($values['from'] ?? ''));
    $to_raw   = $this->sanitizeRangeValue((string) ($values['to']   ?? ''));

    if ($from_raw === '' && $to_raw === '') {
      return;
    }

    $date_mode   = !empty($this->options['date_mode']);
    $granularity = $this->effectiveGranularity();
    $index       = $this->getIndex();

    // Converts a raw user value to the correct backend format for one field.
    $convert = function (string $raw, bool $is_lower, string $field_id)
      use ($date_mode, $granularity, $index): string {
      if ($raw === '' || !$date_mode || $granularity === 'none' || !$index instanceof Index) {
        return $raw;
      }
      $dt = $this->parseGranularityBoundary($raw, $granularity, $is_lower);
      if ($dt === NULL) {
        return $raw;
      }
      $field = $index->getField($field_id);
      // Date fields get a backend-native value; integer fields receive the year.
      return (string) ($field && $field->getType() === 'date'
        ? $this->dateTimeToBackend($index, $dt)
        : (int) $dt->format('Y'));
    };

    // Compute per-field converted values for both directions.
    $from_start = $convert($from_raw, TRUE,  $start_field);
    $from_end   = $convert($from_raw, TRUE,  $end_field);
    $to_start   = $convert($to_raw,   FALSE, $start_field);
    $to_end     = $convert($to_raw,   FALSE, $end_field);

    // Single-field mode.
    if (!empty($this->options['single_field_mode']) || $start_field === $end_field) {
      if ($from_start !== '') {
        $query->addCondition($start_field, $from_start, '>=');
      }
      if ($to_start !== '') {
        $query->addCondition($start_field, $to_start, '<=');
      }
      return;
    }

    // Two-field overlap mode.
    $overlap = $query->createConditionGroup('AND');

    if ($from_raw !== '') {
      $from_or = $query->createConditionGroup('OR');
      $from_or->addCondition($end_field, $from_end, '>=');

      $end_missing = $query->createConditionGroup('AND');
      $end_missing->addCondition($end_field, NULL, '=');
      $end_missing->addCondition($start_field, $from_start, '>=');
      $from_or->addConditionGroup($end_missing);

      $overlap->addConditionGroup($from_or);
    }

    if ($to_raw !== '') {
      $to_or = $query->createConditionGroup('OR');
      $to_or->addCondition($start_field, $to_start, '<=');

      $start_missing = $query->createConditionGroup('AND');
      $start_missing->addCondition($start_field, NULL, '=');
      $start_missing->addCondition($end_field, $to_end, '<=');
      $to_or->addConditionGroup($start_missing);

      $overlap->addConditionGroup($to_or);
    }

    $query->addConditionGroup($overlap);
  }

  // ---------------------------------------------------------------------------
  // Backend-aware date conversion
  // ---------------------------------------------------------------------------

  /**
   * Converts a DateTimeImmutable boundary to the format the active backend expects.
   *
   * search_api_db stores dates as Unix timestamps (integers).
   * All other backends (Solr, Elasticsearch, …) receive UTC ISO 8601 strings.
   */
  protected function dateTimeToBackend(Index $index, \DateTimeImmutable $dt): int|string {
    try {
      $backend_id = $index->getServerInstance()->getBackendId();
    }
    catch (\Exception $e) {
      $backend_id = '';
    }

    return $backend_id === 'search_api_db'
      ? $dt->getTimestamp()
      : $dt->format('c');
  }

}
