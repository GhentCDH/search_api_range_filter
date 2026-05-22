<?php

/**
 * @file
 * Views data for the views_range_filter module.
 */

/**
 * Implements hook_views_data().
 */
function views_range_filter_views_data(): array {
  return [];
}

/**
 * Implements hook_views_data_alter().
 *
 * Registers date and numeric range filters on Search API index tables and SQL
 * base tables, so each filter only appears in the Add dialog for the view type
 * and field type it actually supports.
 */
function views_range_filter_views_data_alter(array &$data): void {
  foreach ($data as $table_name => &$table_data) {
    if (!is_array($table_data)) {
      continue;
    }

    if (str_starts_with($table_name, 'search_api_index_')) {
      $table_data['views_range_filter_sapi_date'] = [
        'title'  => t('Date range filter'),
        'help'   => t('Filters records whose [start, end] date interval overlaps a user-supplied range. Works on date fields in this Search API index.'),
        'filter' => ['id' => 'views_range_filter_sapi_date'],
      ];
      $table_data['views_range_filter_sapi_int'] = [
        'title'  => t('Numeric range filter'),
        'help'   => t('Filters records whose [start, end] numeric interval overlaps a user-supplied range. Works on integer, decimal, and float fields in this Search API index.'),
        'filter' => ['id' => 'views_range_filter_sapi_int'],
      ];
    }
    elseif (!empty($table_data['table']['base'])) {
      $table_data['views_range_filter_sql_date'] = [
        'title'  => t('Date range filter'),
        'help'   => t('Filters records whose [start, end] date interval overlaps a user-supplied range. Works on date and datetime columns.'),
        'filter' => ['id' => 'views_range_filter_sql_date'],
      ];
      $table_data['views_range_filter_sql_int'] = [
        'title'  => t('Numeric range filter'),
        'help'   => t('Filters records whose [start, end] numeric interval overlaps a user-supplied range. Works on numeric columns.'),
        'filter' => ['id' => 'views_range_filter_sql_int'],
      ];
    }
  }
}
