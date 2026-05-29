<?php

namespace Drupal\views_range_filter\Plugin\views\filter;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\views\Views;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Abstract SQL Views filter for range-overlap queries.
 *
 * Field keys are stored as "table_name::column_name" internally.
 */
abstract class RangeFilterSql extends RangeFilterBase {

  protected Connection $database;
  protected CacheBackendInterface $cache;
  protected LoggerInterface $logger;
  protected EntityFieldManagerInterface $entityFieldManager;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, TimeInterface $time, Connection $database, CacheBackendInterface $cache, LoggerInterface $logger, EntityFieldManagerInterface $entity_field_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $time);
    $this->database           = $database;
    $this->cache              = $cache;
    $this->logger             = $logger;
    $this->entityFieldManager = $entity_field_manager;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('datetime.time'),
      $container->get('database'),
      $container->get('cache.data'),
      $container->get('logger.factory')->get('views_range_filter'),
      $container->get('entity_field.manager'),
    );
  }

  // ---------------------------------------------------------------------------
  // Field options
  // ---------------------------------------------------------------------------

  protected function getFieldOptions(): array {
    $view = $this->view;
    if (!$view) {
      return [];
    }

    $base_table = $view->storage->get('base_table');
    $all_data   = Views::viewsData()->getAll();
    $fields     = [];

    foreach ($all_data as $table_name => $table_data) {
      if (!is_array($table_data)) {
        continue;
      }

      $joins_base = isset($table_data['table']['join'][$base_table]);
      if ($table_name !== $base_table && !$joins_base) {
        continue;
      }

      $group = (string) ($table_data['table']['group'] ?? $table_name);

      foreach ($table_data as $field_id => $field_data) {
        if ($field_id === 'table' || !is_array($field_data)) {
          continue;
        }

        if ($this->mode === 'date') {
          if (!$this->isDateField($field_data, $table_name, $field_id)) {
            continue;
          }
        }
        else {
          $filter_id = $field_data['filter']['id'] ?? '';
          if ($filter_id !== 'numeric') {
            continue;
          }
          if ($this->isDateField($field_data, $table_name, $field_id)) {
            continue;
          }
          // Exclude entity reference fields — they store IDs, not numeric values.
          if ($this->getEntityFieldStorageType($table_name, $field_id) === 'entity_reference') {
            continue;
          }
        }

        $label        = (string) ($field_data['title'] ?? $field_id);
        $key          = $table_name . '::' . $field_id;
        $fields[$key] = $group . ': ' . $label . ' [' . $field_id . ']';
      }
    }

    asort($fields);
    return $fields;
  }

  protected function getNoFieldsMessage(): string {
    return $this->mode === 'date'
      ? (string) $this->t(
          'No date fields found for this view\'s base table. '
          . 'Ensure the view has a database base table with indexed date columns.'
        )
      : (string) $this->t(
          'No numeric fields found for this view\'s base table. '
          . 'Ensure the view has a database base table with indexed numeric columns.'
        );
  }

  protected function fieldLabel(string $key): string {
    return str_contains($key, '::') ? explode('::', $key, 2)[1] : $key;
  }

  // ---------------------------------------------------------------------------
  // Auto min/max via SELECT MIN/MAX
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   *
   * Runs a single SELECT MIN(col), MAX(col) query. Cached for one hour.
   */
  public function resolveAutoMinMax(string $field_key): ?array {
    if (!str_contains($field_key, '::')) {
      return NULL;
    }

    [$table, $col] = explode('::', $field_key, 2);

    $cache_id  = 'views_range_filter:auto_minmax_sql:' . $table . ':' . $col;
    $cache_bin = $this->cache;

    if ($cached = $cache_bin->get($cache_id)) {
      return $cached->data;
    }

    try {
      $query = $this->database->select($table, 't');
      $query->addExpression("MIN(t.$col)", 'min_val');
      $query->addExpression("MAX(t.$col)", 'max_val');
      $row = $query->execute()->fetchObject();

      if (!$row || $row->min_val === NULL) {
        return NULL;
      }

      $result = [
        'min'  => $row->min_val,
        'max'  => $row->max_val,
        'type' => $this->getFieldType($field_key),
      ];
    }
    catch (\Exception $e) {
      $this->logger->warning(
        'Auto min/max SQL query failed for @table.@col: @msg',
        ['@table' => $table, '@col' => $col, '@msg' => $e->getMessage()]
      );
      return NULL;
    }

    $cache_bin->set(
      $cache_id,
      $result,
      $this->time->getRequestTime() + 3600
    );

    return $result;
  }

  // ---------------------------------------------------------------------------
  // Query
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   *
   * In date mode, values are parsed via strtotime() and then formatted
   * according to each field's storage type:
   *   - 'timestamp': raw Unix timestamp integer
   *   - 'datetime':  SQL datetime string (YYYY-MM-DD HH:MM:SS)
   *   - 'integer':   year as plain integer
   *
   * In integer mode, raw numeric values are used directly.
   *
   * Two-field overlap using explicit OR conditions:
   *   (end >= min_for_end OR (end IS NULL AND start >= min_for_start))
   *   AND
   *   (start <= max_for_start OR (start IS NULL AND end <= max_for_end))
   */
  public function query(): void {
    $start_key = $this->options['start_field'] ?? '';
    $end_key   = $this->options['end_field']   ?? '';

    if (!$start_key) {
      return;
    }

    $values  = is_array($this->value) ? $this->value : [];
    $min_raw = $this->sanitizeRangeValue((string) ($values['min'] ?? ''));
    $max_raw = $this->sanitizeRangeValue((string) ($values['max'] ?? ''));

    if ($min_raw === '' && $max_raw === '') {
      return; // No filter values → filter inactive, show all records.
    }

    // For integer mode: use raw values directly without any date conversion.
    if ($this->mode !== 'date') {
      $this->applyConditions($start_key, $end_key, $min_raw, $min_raw, $max_raw, $max_raw);
      return;
    }

    // Date mode: parse via strtotime() and convert per field type.
    $value_type = $values['type'] ?? 'date';

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

    $min_start = $tsMin !== NULL ? $this->tsToSqlValue($tsMin, $start_key) : '';
    $min_end   = $tsMin !== NULL ? $this->tsToSqlValue($tsMin, $end_key)   : '';
    $max_start = $tsMax !== NULL ? $this->tsToSqlValue($tsMax, $start_key) : '';
    $max_end   = $tsMax !== NULL ? $this->tsToSqlValue($tsMax, $end_key)   : '';

    $this->applyConditions($start_key, $end_key, $min_start, $min_end, $max_start, $max_end);
  }

  /**
   * Converts a Unix timestamp to the appropriate SQL value for a given field.
   *
   * Storage formats (Drupal core, UTC):
   *   'timestamp' — plain Unix integer
   *   'datetime'  — 'Y-m-d\TH:i:s'  (DateTimeItemInterface::DATETIME_STORAGE_FORMAT)
   *   'date'      — 'Y-m-d'          (DateTimeItemInterface::DATE_STORAGE_FORMAT, date-only)
   *   default     — 4-digit year integer
   */
  protected function tsToSqlValue(int $ts, string $field_key): string {
    $type = $this->getFieldType($field_key);
    return match ($type) {
      'timestamp' => (string) $ts,
      'datetime'  => gmdate('Y-m-d\TH:i:s', $ts),
      'date'      => gmdate('Y-m-d', $ts),
      default     => (string) (int) gmdate('Y', $ts),
    };
  }

  /**
   * Applies the range-overlap WHERE conditions to the query.
   *
   * For date string fields (datetime/date storage), both the column and the
   * comparison value are wrapped with $this->query->getDateField() so each DB
   * backend normalises them to the same internal representation before
   * comparing (MySQL: string as-is; SQLite: strftime('%s',...) → Unix int;
   * PostgreSQL: TO_TIMESTAMP(...) → native timestamp).
   *
   * For timestamp (integer) and integer-mode fields, DatabaseCondition is used
   * directly — integer comparison is identical on all backends.
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
    string $start_key,
    string $end_key,
    string $min_start,
    string $min_end,
    string $max_start,
    string $max_end
  ): void {
    $record_null = $this->options['single_bound_behaviour'] ?? 'equal';
    $both_null   = $record_null !== 'exclude'
      ? ($this->options['missing_bounds_behaviour'] ?? 'exclude')
      : 'exclude';
    $single_mode = $start_key === $end_key;
    $group       = $this->options['group'];

    // -------------------------------------------------------------------------
    // Single-field mode.
    // -------------------------------------------------------------------------

    if ($single_mode) {
      if (!str_contains($start_key, '::')) {
        return;
      }
      [$start_table, $start_col] = explode('::', $start_key, 2);
      $alias = $this->query->ensureTable($start_table, $this->relationship);
      $raw   = "$alias.$start_col";

      if ($this->mode === 'date' && $this->getFieldType($start_key) !== 'timestamp') {
        $f = $this->query->getDateField($raw, TRUE);

        if ($record_null === 'exclude') {
          $this->query->addWhereExpression($group, "$raw IS NOT NULL");
        }
        if ($min_start !== '') {
          $v = $this->query->getDateField("'$min_start'", TRUE);
          $this->query->addWhereExpression($group,
            $record_null === 'open' ? "($f >= $v OR $raw IS NULL)" : "$f >= $v"
          );
        }
        if ($max_start !== '') {
          $v = $this->query->getDateField("'$max_start'", TRUE);
          $this->query->addWhereExpression($group,
            $record_null === 'open' ? "($f <= $v OR $raw IS NULL)" : "$f <= $v"
          );
        }
        return;
      }

      // Integer / timestamp: DatabaseCondition.
      $db = $this->query->getConnection();
      if ($record_null === 'exclude') {
        $this->query->addWhere($group, $raw, NULL, 'IS NOT NULL');
      }
      if ($min_start !== '') {
        if ($record_null === 'open') {
          $or = $db->condition('OR');
          $or->condition($raw, $min_start, '>=');
          $or->condition($raw, NULL, 'IS NULL');
          $this->query->addWhere($group, $or);
        }
        else {
          $this->query->addWhere($group, $raw, $min_start, '>=');
        }
      }
      if ($max_start !== '') {
        if ($record_null === 'open') {
          $or = $db->condition('OR');
          $or->condition($raw, $max_start, '<=');
          $or->condition($raw, NULL, 'IS NULL');
          $this->query->addWhere($group, $or);
        }
        else {
          $this->query->addWhere($group, $raw, $max_start, '<=');
        }
      }
      return;
    }

    // -------------------------------------------------------------------------
    // Two-field mode.
    // -------------------------------------------------------------------------

    if (!str_contains($start_key, '::') || !str_contains($end_key, '::')) {
      return;
    }

    [$start_table, $start_col] = explode('::', $start_key, 2);
    [$end_table,   $end_col]   = explode('::', $end_key,   2);

    $sa = $this->query->ensureTable($start_table, $this->relationship);
    $ea = $this->query->ensureTable($end_table,   $this->relationship);
    $s  = "$sa.$start_col";
    $e  = "$ea.$end_col";

    $use_date_expr = $this->mode === 'date'
      && $this->getFieldType($start_key) !== 'timestamp'
      && $this->getFieldType($end_key)   !== 'timestamp';

    if ($use_date_expr) {
      $sf = $this->query->getDateField($s, TRUE);
      $ef = $this->query->getDateField($e, TRUE);

      // NULL guards (always compare raw columns, not date-wrapped expressions).
      if ($record_null === 'exclude') {
        $this->query->addWhereExpression($group, "$s IS NOT NULL AND $e IS NOT NULL");
      }
      elseif ($record_null === 'open' && $both_null === 'exclude') {
        $this->query->addWhereExpression($group, "($s IS NOT NULL OR $e IS NOT NULL)");
      }

      if ($min_start !== '' || $min_end !== '') {
        $min_e  = $min_end   !== '' ? $min_end   : $min_start;
        $min_s  = $min_start !== '' ? $min_start : $min_end;
        $min_ev = $this->query->getDateField("'$min_e'", TRUE);
        $min_sv = $this->query->getDateField("'$min_s'", TRUE);

        if ($record_null === 'open') {
          $this->query->addWhereExpression($group, "($ef >= $min_ev OR $e IS NULL)");
        }
        elseif ($record_null === 'exclude') {
          $this->query->addWhereExpression($group, "$ef >= $min_ev");
        }
        elseif ($both_null === 'include') {
          $this->query->addWhereExpression($group,
            "($ef >= $min_ev OR ($e IS NULL AND $sf >= $min_sv) OR ($s IS NULL AND $e IS NULL))"
          );
        }
        else {
          $this->query->addWhereExpression($group,
            "($ef >= $min_ev OR ($e IS NULL AND $sf >= $min_sv))"
          );
        }
      }

      if ($max_start !== '' || $max_end !== '') {
        $max_s  = $max_start !== '' ? $max_start : $max_end;
        $max_e  = $max_end   !== '' ? $max_end   : $max_start;
        $max_sv = $this->query->getDateField("'$max_s'", TRUE);
        $max_ev = $this->query->getDateField("'$max_e'", TRUE);

        if ($record_null === 'open') {
          $this->query->addWhereExpression($group, "($sf <= $max_sv OR $s IS NULL)");
        }
        elseif ($record_null === 'exclude') {
          $this->query->addWhereExpression($group, "$sf <= $max_sv");
        }
        elseif ($both_null === 'include') {
          $this->query->addWhereExpression($group,
            "($sf <= $max_sv OR ($s IS NULL AND $ef <= $max_ev) OR ($s IS NULL AND $e IS NULL))"
          );
        }
        else {
          $this->query->addWhereExpression($group,
            "($sf <= $max_sv OR ($s IS NULL AND $ef <= $max_ev))"
          );
        }
      }
      return;
    }

    // Integer / timestamp: DatabaseCondition.
    $db = $this->query->getConnection();

    if ($record_null === 'exclude') {
      $this->query->addWhere($group, $s, NULL, 'IS NOT NULL');
      $this->query->addWhere($group, $e, NULL, 'IS NOT NULL');
    }
    elseif ($record_null === 'open' && $both_null === 'exclude') {
      $or = $db->condition('OR');
      $or->condition($s, NULL, 'IS NOT NULL');
      $or->condition($e, NULL, 'IS NOT NULL');
      $this->query->addWhere($group, $or);
    }

    if ($min_start !== '' || $min_end !== '') {
      $min_e = $min_end   !== '' ? $min_end   : $min_start;
      $min_s = $min_start !== '' ? $min_start : $min_end;

      if ($record_null === 'open') {
        $or = $db->condition('OR');
        $or->condition($e, $min_e, '>=');
        $or->condition($e, NULL, 'IS NULL');
        $this->query->addWhere($group, $or);
      }
      elseif ($record_null === 'exclude') {
        $this->query->addWhere($group, $e, $min_e, '>=');
      }
      else {
        $or       = $db->condition('OR');
        $null_end = $db->condition('AND');
        $null_end->condition($e, NULL, 'IS NULL');
        $null_end->condition($s, $min_s, '>=');
        $or->condition($e, $min_e, '>=');
        $or->condition($null_end);
        if ($both_null === 'include') {
          $bn = $db->condition('AND');
          $bn->condition($s, NULL, 'IS NULL');
          $bn->condition($e, NULL, 'IS NULL');
          $or->condition($bn);
        }
        $this->query->addWhere($group, $or);
      }
    }

    if ($max_start !== '' || $max_end !== '') {
      $max_s = $max_start !== '' ? $max_start : $max_end;
      $max_e = $max_end   !== '' ? $max_end   : $max_start;

      if ($record_null === 'open') {
        $or = $db->condition('OR');
        $or->condition($s, $max_s, '<=');
        $or->condition($s, NULL, 'IS NULL');
        $this->query->addWhere($group, $or);
      }
      elseif ($record_null === 'exclude') {
        $this->query->addWhere($group, $s, $max_s, '<=');
      }
      else {
        $or         = $db->condition('OR');
        $null_start = $db->condition('AND');
        $null_start->condition($s, NULL, 'IS NULL');
        $null_start->condition($e, $max_e, '<=');
        $or->condition($s, $max_s, '<=');
        $or->condition($null_start);
        if ($both_null === 'include') {
          $bn = $db->condition('AND');
          $bn->condition($s, NULL, 'IS NULL');
          $bn->condition($e, NULL, 'IS NULL');
          $or->condition($bn);
        }
        $this->query->addWhere($group, $or);
      }
    }
  }

  // ---------------------------------------------------------------------------
  // Field type detection
  // ---------------------------------------------------------------------------

  /**
   * Returns 'timestamp', 'datetime', 'date', or 'integer' for the given field key.
   *
   *   - 'timestamp': filter plugin is exactly 'date' (core timestamp fields).
   *   - 'datetime':  entity field stores full datetime strings (Y-m-d\TH:i:s).
   *   - 'date':      entity field stores date-only strings (Y-m-d), i.e. datetime_type='date'.
   *   - 'integer':   everything else (year-like integers, plain numerics).
   */
  protected function getFieldType(string $field_key): string {
    if (!str_contains($field_key, '::')) {
      return 'integer';
    }

    [$table, $col] = explode('::', $field_key, 2);
    $field_data    = Views::viewsData()->get($table)[$col] ?? [];
    $filter_id     = $field_data['filter']['id'] ?? '';

    // Exact 'date' filter plugin → timestamp storage.
    if ($filter_id === 'date') {
      return 'timestamp';
    }

    // Check whether the filter plugin extends core Date (but is not 'date').
    if ($filter_id) {
      try {
        $def = \Drupal::service('plugin.manager.views.filter')
          ->getDefinition($filter_id, FALSE);
        if ($def) {
          $base = 'Drupal\views\Plugin\views\filter\Date';
          if (class_exists($base) && is_a($def['class'], $base, TRUE)) {
            return $this->isDateOnlyEntityField($table, $col) ? 'date' : 'datetime';
          }
        }
      }
      catch (\Throwable $e) {}
    }

    // Entity storage type check.
    $entity_type = $this->getEntityFieldStorageType($table, $col);
    if (in_array($entity_type, ['datetime', 'daterange'], TRUE)) {
      return $this->isDateOnlyEntityField($table, $col) ? 'date' : 'datetime';
    }
    if ($entity_type === 'timestamp') {
      return 'timestamp';
    }

    return 'integer';
  }

  /**
   * Returns TRUE when the entity field stores date-only values (no time part).
   *
   * datetime/daterange fields with datetime_type='date' store 'Y-m-d';
   * those with datetime_type='datetime' store 'Y-m-d\TH:i:s'.
   */
  protected function isDateOnlyEntityField(string $table, string $col): bool {
    if (!str_contains($table, '__')) {
      return FALSE;
    }
    [$entity_type_id, $field_name] = explode('__', $table, 2);
    try {
      $definitions = $this->entityFieldManager
        ->getFieldStorageDefinitions($entity_type_id);
      $def = $definitions[$field_name] ?? NULL;
      return $def !== NULL && $def->getSetting('datetime_type') === 'date';
    }
    catch (\Throwable $e) {
      return FALSE;
    }
  }

  /**
   * Returns the Drupal field storage type for entity attachment table columns.
   *
   * Entity attachment tables are named {entity_type}__{field_name}. Only the
   * actual value columns ({field_name}_value, {field_name}_end_value) are
   * resolved — metadata columns (delta, entity_id, bundle, …) return NULL.
   */
  protected function getEntityFieldStorageType(string $table, string $col): ?string {
    if (!$table || !$col || !str_contains($table, '__')) {
      return NULL;
    }
    [$entity_type_id, $field_name] = explode('__', $table, 2);
    $known_suffixes = ['_value', '_end_value', '_target_id'];
    $matched = FALSE;
    foreach ($known_suffixes as $suffix) {
      if ($col === $field_name . $suffix) {
        $matched = TRUE;
        break;
      }
    }
    if (!$matched) {
      return NULL;
    }
    try {
      $definitions = $this->entityFieldManager
        ->getFieldStorageDefinitions($entity_type_id);
      return ($definitions[$field_name] ?? NULL)?->getType();
    }
    catch (\Throwable $e) {
      return NULL;
    }
  }

  /**
   * @param string $table  Table name, used to resolve the entity field type.
   * @param string $col    Column name, used to resolve the entity field type.
   */
  protected function isDateField(array $field_data, string $table = '', string $col = ''): bool {
    // Check filter plugin class hierarchy.
    $filter_id = $field_data['filter']['id'] ?? '';
    if ($filter_id) {
      try {
        $def = \Drupal::service('plugin.manager.views.filter')
          ->getDefinition($filter_id, FALSE);
        if ($def) {
          $base = 'Drupal\views\Plugin\views\filter\Date';
          if (class_exists($base) && is_a($def['class'], $base, TRUE)) {
            return TRUE;
          }
        }
      }
      catch (\Throwable $e) {}
    }

    // Check field plugin class hierarchy.
    $field_id = $field_data['field']['id'] ?? '';
    if ($field_id) {
      try {
        $def = \Drupal::service('plugin.manager.views.field')
          ->getDefinition($field_id, FALSE);
        if ($def) {
          $base = 'Drupal\views\Plugin\views\field\Date';
          if (class_exists($base) && is_a($def['class'], $base, TRUE)) {
            return TRUE;
          }
        }
      }
      catch (\Throwable $e) {}
    }

    // For entity attachment tables, check the actual Drupal field storage type.
    // Timestamp fields register a numeric Views filter but ARE date fields.
    $entity_type = $this->getEntityFieldStorageType($table, $col);
    if (in_array($entity_type, ['datetime', 'timestamp', 'daterange'], TRUE)) {
      return TRUE;
    }

    // Fallback to known IDs when plugin managers are unavailable.
    return in_array($filter_id, ['date', 'datetime', 'daterange_filter'], TRUE)
      || in_array($field_id, ['date', 'datetime'], TRUE);
  }

}
