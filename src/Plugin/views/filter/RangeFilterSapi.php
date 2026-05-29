<?php

namespace Drupal\views_range_filter\Plugin\views\filter;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Plugin\views\filter\SearchApiFilterTrait;
use Drupal\search_api\Plugin\views\query\SearchApiQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Abstract Search API Views filter for range-overlap queries.
 */
abstract class RangeFilterSapi extends RangeFilterBase {

  use SearchApiFilterTrait;

  protected CacheBackendInterface $cache;
  protected LoggerInterface $logger;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, TimeInterface $time, CacheBackendInterface $cache, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $time);
    $this->cache  = $cache;
    $this->logger = $logger;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('datetime.time'),
      $container->get('cache.data'),
      $container->get('logger.factory')->get('views_range_filter'),
    );
  }

  // ---------------------------------------------------------------------------
  // Field options
  // ---------------------------------------------------------------------------

  protected function getFieldOptions(): array {
    $index = $this->getIndex();
    if (!$index instanceof Index) {
      return [];
    }

    $range_types = $this->mode === 'date'
      ? ['date']
      : ['integer', 'decimal', 'float'];

    $fields = [];

    foreach ($index->getFields() as $field_id => $field) {
      if (in_array($field->getType(), $range_types, TRUE)) {
        $fields[$field_id] = $field->getLabel() . ' [' . $field_id . ']';
      }
    }

    return $fields;
  }

  protected function getNoFieldsMessage(): string {
    return $this->mode === 'date'
      ? (string) $this->t(
          'No date fields found in this index. '
          . 'Make sure the view is backed by a Search API index and date fields are indexed.'
        )
      : (string) $this->t(
          'No numeric fields (integer, decimal, float) found in this index. '
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
  public function resolveAutoMinMax(string $field_id): ?array {
    $index = $this->getIndex();
    if (!$index instanceof Index || !$field_id) {
      return NULL;
    }

    $cache_id  = 'views_range_filter:auto_minmax:' . $index->id() . ':' . $field_id;
    $cache_bin = $this->cache;

    if ($cached = $cache_bin->get($cache_id)) {
      return $cached->data;
    }

    try {
      $result = $this->queryMinMax($index, $field_id);
    }
    catch (\Exception $e) {
      $this->logger->warning(
        'Auto min/max query failed for field @field on index @index: @msg',
        ['@field' => $field_id, '@index' => $index->id(), '@msg' => $e->getMessage()]
      );
      return NULL;
    }

    if ($result !== NULL) {
      $cache_bin->set(
        $cache_id,
        $result,
        $this->time->getRequestTime() + 3600,
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
   * In date mode, values are parsed via strtotime() and then converted to the
   * backend-native format for each field:
   *   - search_api_db:      Unix timestamp integer
   *   - other backends:     UTC ISO 8601 string
   *
   * In integer mode, raw numeric values are used directly.
   *
   * Two-field overlap formula:
   *   (end >= min_for_end  OR  (end IS NULL AND start >= min_for_start))
   *   AND
   *   (start <= max_for_start  OR  (start IS NULL AND end <= max_for_end))
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

    $values  = is_array($this->value) ? $this->value : [];
    $min_raw = $this->sanitizeRangeValue((string) ($values['min'] ?? ''));
    $max_raw = $this->sanitizeRangeValue((string) ($values['max'] ?? ''));

    if ($min_raw === '' && $max_raw === '') {
      return; // No filter values → filter inactive, show all records.
    }

    // Integer mode: use raw values directly.
    if ($this->mode !== 'date') {
      $this->applyConditions($query, $start_field, $end_field, $min_raw, $min_raw, $max_raw, $max_raw);
      return;
    }

    // Date mode: parse via strtotime().
    $value_type = $values['type'] ?? 'date';
    $index      = $this->getIndex();

    $tsMin = NULL;
    $tsMax = NULL;

    if ($min_raw !== '') {
      $tsMin = $value_type === 'offset'
        ? $this->time->getRequestTime() + (int) strtotime($min_raw, 0)
        : $this->parseDateBound($min_raw, 'min');
    }

    if ($max_raw !== '') {
      $tsMax = $value_type === 'offset'
        ? $this->time->getRequestTime() + (int) strtotime($max_raw, 0)
        : $this->parseDateBound($max_raw, 'max');
    }

    $min_start = $tsMin !== NULL ? (string) $this->tsToBackend($index, $tsMin) : '';
    $min_end   = $tsMin !== NULL ? (string) $this->tsToBackend($index, $tsMin) : '';
    $max_start = $tsMax !== NULL ? (string) $this->tsToBackend($index, $tsMax) : '';
    $max_end   = $tsMax !== NULL ? (string) $this->tsToBackend($index, $tsMax) : '';

    $this->applyConditions($query, $start_field, $end_field, $min_start, $min_end, $max_start, $max_end);
  }

  /**
   * Applies range-overlap conditions to the Search API query.
   *
   * single_bound_behaviour controls how NULL start/end fields on records are
   * interpreted:
   *   'open'    — NULL = infinite (NULL end satisfies any >= check; NULL start
   *               satisfies any <= check).
   *   'equal'   — NULL = other field value (NULL end falls back to checking
   *               start, and vice versa).
   *   'exclude' — records with a NULL start or end are never returned.
   */
  protected function applyConditions(
    SearchApiQuery $query,
    string $start_field,
    string $end_field,
    string $min_start,
    string $min_end,
    string $max_start,
    string $max_end
  ): void {
    $record_null = $this->options['single_bound_behaviour'] ?? 'equal';
    $both_null   = $record_null !== 'exclude' ? ($this->options['missing_bounds_behaviour'] ?? 'exclude') : 'exclude';

    // Single-field mode.
    if ($start_field === $end_field) {
      if ($record_null === 'exclude') {
        $query->addCondition($start_field, NULL, '<>');
      }

      if ($min_start !== '') {
        if ($record_null === 'open') {
          $ge = $query->createConditionGroup('OR');
          $ge->addCondition($start_field, $min_start, '>=');
          $ge->addCondition($start_field, NULL, '=');
          $query->addConditionGroup($ge);
        }
        else {
          $query->addCondition($start_field, $min_start, '>=');
        }
      }
      if ($max_start !== '') {
        if ($record_null === 'open') {
          $le = $query->createConditionGroup('OR');
          $le->addCondition($start_field, $max_start, '<=');
          $le->addCondition($start_field, NULL, '=');
          $query->addConditionGroup($le);
        }
        else {
          $query->addCondition($start_field, $max_start, '<=');
        }
      }
      return;
    }

    // Two-field overlap mode.
    $overlap = $query->createConditionGroup('AND');

    if ($record_null === 'exclude') {
      $overlap->addCondition($start_field, NULL, '<>');
      $overlap->addCondition($end_field, NULL, '<>');
    }
    elseif ($record_null === 'open' && $both_null === 'exclude') {
      // 'open' mode includes both-NULL records via IS NULL; prevent that.
      $not_both_null = $query->createConditionGroup('OR');
      $not_both_null->addCondition($start_field, NULL, '<>');
      $not_both_null->addCondition($end_field, NULL, '<>');
      $overlap->addConditionGroup($not_both_null);
    }

    if ($min_start !== '' || $min_end !== '') {
      $min_e = $min_end   !== '' ? $min_end   : $min_start;
      $min_s = $min_start !== '' ? $min_start : $min_end;

      if ($record_null === 'open') {
        // NULL end = +∞, always satisfies end >= min.
        $from_or = $query->createConditionGroup('OR');
        $from_or->addCondition($end_field, $min_e, '>=');
        $from_or->addCondition($end_field, NULL, '=');
        $overlap->addConditionGroup($from_or);
      }
      elseif ($record_null === 'exclude') {
        // NULLs already blocked above; plain comparison suffices.
        $overlap->addCondition($end_field, $min_e, '>=');
      }
      else {
        // 'equal': treat NULL end as equal to start.
        $from_or = $query->createConditionGroup('OR');
        $from_or->addCondition($end_field, $min_e, '>=');
        $end_missing = $query->createConditionGroup('AND');
        $end_missing->addCondition($end_field, NULL, '=');
        $end_missing->addCondition($start_field, $min_s, '>=');
        $from_or->addConditionGroup($end_missing);
        if ($both_null === 'include') {
          // Both fields NULL → always include.
          $both_null_group = $query->createConditionGroup('AND');
          $both_null_group->addCondition($start_field, NULL, '=');
          $both_null_group->addCondition($end_field, NULL, '=');
          $from_or->addConditionGroup($both_null_group);
        }
        $overlap->addConditionGroup($from_or);
      }
    }

    if ($max_start !== '' || $max_end !== '') {
      $max_s = $max_start !== '' ? $max_start : $max_end;
      $max_e = $max_end   !== '' ? $max_end   : $max_start;

      if ($record_null === 'open') {
        // NULL start = -∞, always satisfies start <= max.
        $to_or = $query->createConditionGroup('OR');
        $to_or->addCondition($start_field, $max_s, '<=');
        $to_or->addCondition($start_field, NULL, '=');
        $overlap->addConditionGroup($to_or);
      }
      elseif ($record_null === 'exclude') {
        $overlap->addCondition($start_field, $max_s, '<=');
      }
      else {
        // 'equal': treat NULL start as equal to end.
        $to_or = $query->createConditionGroup('OR');
        $to_or->addCondition($start_field, $max_s, '<=');
        $start_missing = $query->createConditionGroup('AND');
        $start_missing->addCondition($start_field, NULL, '=');
        $start_missing->addCondition($end_field, $max_e, '<=');
        $to_or->addConditionGroup($start_missing);
        if ($both_null === 'include') {
          $both_null_group = $query->createConditionGroup('AND');
          $both_null_group->addCondition($start_field, NULL, '=');
          $both_null_group->addCondition($end_field, NULL, '=');
          $to_or->addConditionGroup($both_null_group);
        }
        $overlap->addConditionGroup($to_or);
      }
    }

    $query->addConditionGroup($overlap);
  }

  // ---------------------------------------------------------------------------
  // Backend-aware date conversion
  // ---------------------------------------------------------------------------

  /**
   * Converts a Unix timestamp to the format the active backend expects.
   *
   * search_api_db stores dates as Unix timestamps (integers).
   * All other backends (Solr, Elasticsearch, …) receive UTC ISO 8601 strings.
   */
  protected function tsToBackend(?Index $index, int $ts): int|string {
    if (!$index instanceof Index) {
      return $ts;
    }

    try {
      $backend_id = $index->getServerInstance()->getBackendId();
    }
    catch (\Exception $e) {
      $backend_id = '';
    }

    return $backend_id === 'search_api_db'
      ? $ts
      : (new \DateTimeImmutable('@' . $ts))->format('c');
  }

}
