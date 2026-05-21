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
    $range_filter_ids = ['numeric', 'date'];
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

        $label          = (string) ($field_data['title'] ?? $field_id);
        $key            = $table_name . '::' . $field_id;
        $fields[$key]   = $group . ': ' . $label . ' [' . $field_id . ']';
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

      $result = ['min' => $row->min_val, 'max' => $row->max_val];
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
   * Single-field mode: simple range on one column.
   * Two-field mode: COALESCE overlap expressions.
   */
  public function query(): void {
    $start_key = $this->options['start_field'] ?? '';
    $end_key   = $this->options['end_field']   ?? '';

    if (!$start_key) {
      return;
    }

    $values   = is_array($this->value) ? $this->value : [];
    $from_val = $this->sanitizeRangeValue((string) ($values['from'] ?? ''));
    $to_val   = $this->sanitizeRangeValue((string) ($values['to']   ?? ''));

    if ($from_val === '' && $to_val === '') {
      return;
    }

    // Unique placeholder suffix to avoid collisions with multiple filter instances.
    static $counter = 0;
    $suffix = ++$counter;

    // Single-field mode (or same field configured for both).
    $single_mode = !empty($this->options['single_field_mode']) || $start_key === $end_key;

    if ($single_mode) {
      if (!str_contains($start_key, '::')) {
        return;
      }

      [$start_table, $start_col] = explode('::', $start_key, 2);
      $alias = $this->query->ensureTable($start_table, $this->relationship);
      $expr  = "$alias.$start_col";

      if ($from_val !== '') {
        $this->query->addWhereExpression(0, "$expr >= :range_from_$suffix", [":range_from_$suffix" => $from_val]);
      }
      if ($to_val !== '') {
        $this->query->addWhereExpression(0, "$expr <= :range_to_$suffix",   [":range_to_$suffix"   => $to_val]);
      }
      return;
    }

    // Two-field overlap mode.
    if (!str_contains($start_key, '::') || !str_contains($end_key, '::')) {
      return;
    }

    [$start_table, $start_col] = explode('::', $start_key, 2);
    [$end_table,   $end_col]   = explode('::', $end_key,   2);

    $start_alias = $this->query->ensureTable($start_table, $this->relationship);
    $end_alias   = $this->query->ensureTable($end_table,   $this->relationship);

    $s = "$start_alias.$start_col";
    $e = "$end_alias.$end_col";

    if ($from_val !== '') {
      // COALESCE(end, start) >= from
      $this->query->addWhereExpression(
        0,
        "COALESCE($e, $s) >= :range_from_$suffix",
        [":range_from_$suffix" => $from_val]
      );
    }

    if ($to_val !== '') {
      // COALESCE(start, end) <= to
      $this->query->addWhereExpression(
        0,
        "COALESCE($s, $e) <= :range_to_$suffix",
        [":range_to_$suffix" => $to_val]
      );
    }
  }

}