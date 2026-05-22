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
  protected function resolveAutoMinMax(string $field_key): ?array {
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
   * In date mode, each field receives a value converted according to its type:
   *   - date-type fields get an SQL datetime string (YYYY-MM-DD HH:MM:SS)
   *   - integer-type fields receive the year as a plain integer
   *
   * In numeric mode, values are compared as-is.
   *
   * Two-field overlap using explicit OR conditions (rather than COALESCE) so
   * that each side of the OR can receive a separately converted value:
   *   (end >= from_for_end OR (end IS NULL AND start >= from_for_start))
   *   AND
   *   (start <= to_for_start OR (start IS NULL AND end <= to_for_end))
   */
  public function query(): void {
    $start_key = $this->options['start_field'] ?? '';
    $end_key   = $this->options['end_field']   ?? '';

    if (!$start_key) {
      return;
    }

    $values   = is_array($this->value) ? $this->value : [];
    $from_raw = $this->sanitizeRangeValue((string) ($values['from'] ?? ''));
    $to_raw   = $this->sanitizeRangeValue((string) ($values['to']   ?? ''));

    if ($from_raw === '' && $to_raw === '') {
      return;
    }

    $granularity = $this->effectiveGranularity();

    // Converts a raw user value to the correct SQL format for one field.
    $convert = function (string $raw, bool $is_lower, string $field_key)
      use ($granularity): string {
      if ($raw === '' || $granularity === 'none') {
        return $raw;
      }
      $dt = $this->parseGranularityBoundary($raw, $granularity, $is_lower);
      if ($dt === NULL) {
        return $raw;
      }
      // Date fields get an SQL datetime string; integer fields receive the year.
      return $this->getFieldType($field_key) === 'date'
        ? $this->dateTimeToSql($dt)
        : (string) (int) $dt->format('Y');
    };

    // Compute per-field converted values for both directions.
    $from_start = $convert($from_raw, TRUE,  $start_key);
    $from_end   = $convert($from_raw, TRUE,  $end_key);
    $to_start   = $convert($to_raw,   FALSE, $start_key);
    $to_end     = $convert($to_raw,   FALSE, $end_key);

    // Unique placeholder suffix to avoid collisions across multiple filter instances.
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

      if ($from_start !== '') {
        $this->query->addWhereExpression(0, "$expr >= :range_from_$suffix", [":range_from_$suffix" => $from_start]);
      }
      if ($to_start !== '') {
        $this->query->addWhereExpression(0, "$expr <= :range_to_$suffix", [":range_to_$suffix" => $to_start]);
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

    if ($from_raw !== '') {
      // (end >= from_for_end) OR (end IS NULL AND start >= from_for_start)
      $this->query->addWhereExpression(
        0,
        "($e >= :from_e_$suffix OR ($e IS NULL AND $s >= :from_s_$suffix))",
        [":from_e_$suffix" => $from_end, ":from_s_$suffix" => $from_start]
      );
    }

    if ($to_raw !== '') {
      // (start <= to_for_start) OR (start IS NULL AND end <= to_for_end)
      $this->query->addWhereExpression(
        0,
        "($s <= :to_s_$suffix OR ($s IS NULL AND $e <= :to_e_$suffix))",
        [":to_s_$suffix" => $to_start, ":to_e_$suffix" => $to_end]
      );
    }
  }

  // ---------------------------------------------------------------------------
  // Field type detection
  // ---------------------------------------------------------------------------

  /**
   * Returns 'date' if the field uses a date filter plugin, otherwise 'integer'.
   */
  protected function getFieldType(string $field_key): string {
    if (!str_contains($field_key, '::')) {
      return 'integer';
    }
    [$table, $col] = explode('::', $field_key, 2);
    $field_data = Views::viewsData()->get($table)[$col] ?? [];
    return $this->isDateField($field_data, $table, $col) ? 'date' : 'integer';
  }

  /**
   * Returns TRUE when the field uses a date filter plugin.
   *
   * Uses the filter plugin class hierarchy: \Drupal\datetime\Plugin\views\filter\Date
   * and \Drupal\datetime_range\Plugin\views\filter\DateRange both extend the
   * core \Drupal\views\Plugin\views\filter\Date, so any contrib plugin that
   * also extends it is automatically included. Falls back to a known-ID check
   * if the class lookup fails.
   *
   * Note: entity fields use a generic field display plugin regardless of type,
   * so checking the field plugin class does not work — the filter plugin is the
   * authoritative indicator of date vs. numeric behaviour.
   */
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
    catch (\Exception $e) {
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
      catch (\Exception $e) {}
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
      catch (\Exception $e) {}
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

  // ---------------------------------------------------------------------------
  // SQL date formatting
  // ---------------------------------------------------------------------------

  /**
   * Formats a DateTimeImmutable as a SQL-compatible datetime string.
   *
   * Years below 1000 are zero-padded to four digits (MySQL requires this for
   * DATE and DATETIME columns).
   */
  protected function dateTimeToSql(\DateTimeImmutable $dt): string {
    return $dt->format('Y-m-d H:i:s');
  }

}
