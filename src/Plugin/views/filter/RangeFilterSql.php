<?php

namespace Drupal\views_range_filter\Plugin\views\filter;

use Drupal\views\Views;

/**
 * Abstract SQL Views filter for range-overlap queries.
 *
 * Field keys are stored as "table_name::column_name" internally.
 */
abstract class RangeFilterSql extends RangeFilterBase {

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
    $cache_bin = \Drupal::cache('data');

    if ($cached = $cache_bin->get($cache_id)) {
      return $cached->data;
    }

    try {
      $query = \Drupal::database()->select($table, 't');
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
      \Drupal::logger('views_range_filter')->warning(
        'Auto min/max SQL query failed for @table.@col: @msg',
        ['@table' => $table, '@col' => $col, '@msg' => $e->getMessage()]
      );
      return NULL;
    }

    $cache_bin->set(
      $cache_id,
      $result,
      \Drupal::time()->getRequestTime() + 3600
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
      return;
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
      if ($value_type === 'offset') {
        $tsMin = time() + (int) strtotime($min_raw, 0);
      }
      else {
        $ts = strtotime($min_raw);
        $tsMin = ($ts !== FALSE) ? $ts : NULL;
      }
    }

    if ($max_raw !== '') {
      if ($value_type === 'offset') {
        $tsMax = time() + (int) strtotime($max_raw, 0);
      }
      else {
        $ts = strtotime($max_raw);
        $tsMax = ($ts !== FALSE) ? $ts : NULL;
      }
    }

    $min_start = $tsMin !== NULL ? $this->tsToSqlValue($tsMin, $start_key) : '';
    $min_end   = $tsMin !== NULL ? $this->tsToSqlValue($tsMin, $end_key)   : '';
    $max_start = $tsMax !== NULL ? $this->tsToSqlValue($tsMax, $start_key) : '';
    $max_end   = $tsMax !== NULL ? $this->tsToSqlValue($tsMax, $end_key)   : '';

    $this->applyConditions($start_key, $end_key, $min_start, $min_end, $max_start, $max_end);
  }

  /**
   * Converts a Unix timestamp to the appropriate SQL value for a given field.
   */
  protected function tsToSqlValue(int $ts, string $field_key): string {
    $type = $this->getFieldType($field_key);
    return match ($type) {
      'timestamp' => (string) $ts,
      'datetime'  => date('Y-m-d H:i:s', $ts),
      default     => (string) (int) date('Y', $ts),
    };
  }

  /**
   * Applies the range-overlap WHERE conditions to the query.
   */
  protected function applyConditions(
    string $start_key,
    string $end_key,
    string $min_start,
    string $min_end,
    string $max_start,
    string $max_end
  ): void {
    static $counter = 0;
    $suffix = ++$counter;

    $single_mode = $start_key === $end_key;

    if ($single_mode) {
      if (!str_contains($start_key, '::')) {
        return;
      }
      [$start_table, $start_col] = explode('::', $start_key, 2);
      $alias = $this->query->ensureTable($start_table, $this->relationship);
      $expr  = "$alias.$start_col";

      if ($min_start !== '') {
        $this->query->addWhereExpression(0, "$expr >= :range_min_$suffix", [":range_min_$suffix" => $min_start]);
      }
      if ($max_start !== '') {
        $this->query->addWhereExpression(0, "$expr <= :range_max_$suffix", [":range_max_$suffix" => $max_start]);
      }
      return;
    }

    if (!str_contains($start_key, '::') || !str_contains($end_key, '::')) {
      return;
    }

    [$start_table, $start_col] = explode('::', $start_key, 2);
    [$end_table,   $end_col]   = explode('::', $end_key,   2);

    $sa = $this->query->ensureTable($start_table, $this->relationship);
    $ea = $this->query->ensureTable($end_table,   $this->relationship);

    $s = "$sa.$start_col";
    $e = "$ea.$end_col";

    if ($min_start !== '' || $min_end !== '') {
      $min_e = $min_end   !== '' ? $min_end   : $min_start;
      $min_s = $min_start !== '' ? $min_start : $min_end;
      // (end >= min_for_end) OR (end IS NULL AND start >= min_for_start)
      $this->query->addWhereExpression(
        0,
        "($e >= :min_e_$suffix OR ($e IS NULL AND $s >= :min_s_$suffix))",
        [":min_e_$suffix" => $min_e, ":min_s_$suffix" => $min_s]
      );
    }

    if ($max_start !== '' || $max_end !== '') {
      $max_s = $max_start !== '' ? $max_start : $max_end;
      $max_e = $max_end   !== '' ? $max_end   : $max_start;
      // (start <= max_for_start) OR (start IS NULL AND end <= max_for_end)
      $this->query->addWhereExpression(
        0,
        "($s <= :max_s_$suffix OR ($s IS NULL AND $e <= :max_e_$suffix))",
        [":max_s_$suffix" => $max_s, ":max_e_$suffix" => $max_e]
      );
    }
  }

  // ---------------------------------------------------------------------------
  // Field type detection
  // ---------------------------------------------------------------------------

  /**
   * Returns 'timestamp', 'datetime', or 'integer' for the given field key.
   *
   *   - 'timestamp': filter plugin is exactly 'date' (core timestamp fields).
   *   - 'datetime':  filter plugin extends core Date but is not 'date' itself,
   *                  OR entity storage type is 'datetime'/'daterange'.
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
            return 'datetime';
          }
        }
      }
      catch (\Throwable $e) {}
    }

    // Entity storage type check.
    $entity_type = $this->getEntityFieldStorageType($table, $col);
    if (in_array($entity_type, ['datetime', 'daterange'], TRUE)) {
      return 'datetime';
    }
    if ($entity_type === 'timestamp') {
      return 'timestamp';
    }

    return 'integer';
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
      $definitions = \Drupal::service('entity_field.manager')
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
