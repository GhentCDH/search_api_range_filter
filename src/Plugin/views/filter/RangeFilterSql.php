<?php

namespace Drupal\views_range_filter\Plugin\views\filter;

use Drupal\views\Views;

/**
 * Views filter for range-overlap queries on regular SQL-backed Views.
 *
 * Field keys are stored as "table_name::column_name" internally.
 *
 * @ViewsFilter("views_range_filter_sql")
 */
class RangeFilterSql extends RangeFilterBase {

  // ---------------------------------------------------------------------------
  // Field options
  // ---------------------------------------------------------------------------

  protected function getFieldOptions(): array {
    $view = $this->view;
    if (!$view) {
      return [];
    }

    $base_table       = $view->storage->get('base_table');
    $all_data         = Views::viewsData()->getAll();
    $range_filter_ids = ['numeric', 'date', 'datetime', 'daterange_filter'];
    $fields           = [];

    foreach ($all_data as $table_name => $table_data) {
      if (!is_array($table_data)) {
        continue;
      }

      // Accept the base table itself and tables that directly join to it
      // (covers entity field tables such as node__field_date_start).
      $joins_base = isset($table_data['table']['join'][$base_table]);
      if ($table_name !== $base_table && !$joins_base) {
        continue;
      }

      $group = (string) ($table_data['table']['group'] ?? $table_name);

      foreach ($table_data as $field_id => $field_data) {
        if ($field_id === 'table' || !is_array($field_data)) {
          continue;
        }

        $filter_id = $field_data['filter']['id'] ?? '';
        if (!in_array($filter_id, $range_filter_ids, TRUE)) {
          continue;
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
    return (string) $this->t(
      'No numeric or date fields found for this view\'s base table. '
      . 'Ensure the view has a database base table with indexed date or numeric columns.'
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

    $date_mode   = !empty($this->options['date_mode']);
    $granularity = $this->effectiveGranularity();

    // Converts a raw user value to the correct SQL format for one field.
    $convert = function (string $raw, bool $is_lower, string $field_key)
      use ($date_mode, $granularity): string {
      if ($raw === '' || !$date_mode || $granularity === 'none') {
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

    $single_mode = !empty($this->options['single_field_mode']) || $start_key === $end_key;

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
   * Returns 'date' when the field's registered Views filter is a date type,
   * otherwise 'integer'. Used to drive per-field value conversion.
   */
  protected function getFieldType(string $field_key): string {
    if (!str_contains($field_key, '::')) {
      return 'integer';
    }
    [$table, $col] = explode('::', $field_key, 2);
    $date_filter_ids = ['date', 'datetime', 'daterange_filter'];
    $field_data = Views::viewsData()->get($table)[$col] ?? [];
    $filter_id  = $field_data['filter']['id'] ?? '';
    return in_array($filter_id, $date_filter_ids, TRUE) ? 'date' : 'integer';
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
