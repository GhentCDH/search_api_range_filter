<?php

/**
 * @file
 * Views data for the views_range_filter module.
 */

/**
 * Implements hook_views_data().
 */
function views_range_filter_views_data(): array {
  // Filters are registered per view-type in hook_views_data_alter() below.
  return [];
}

/**
 * Implements hook_views_data_alter().
 *
 * Registers the SAPI filter on Search API index base tables and the SQL filter
 * on all other base tables, so each filter only appears in the Add dialog for
 * the view type it actually supports.
 */
function views_range_filter_views_data_alter(array &$data): void {
  foreach ($data as $table_name => &$table_data) {
    if (!is_array($table_data)) {
      continue;
    }

    if (str_starts_with($table_name, 'search_api_index_')) {
      $table_data['views_range_filter_sapi'] = [
        'title'  => t('Range filter'),
        'help'   => t('Filters records whose [start, end] interval overlaps a user-supplied range. Works on any two date or numeric fields in this index.'),
        'filter' => ['id' => 'views_range_filter_sapi'],
      ];
    }
    elseif (!empty($table_data['table']['base'])) {
      $table_data['views_range_filter_sql'] = [
        'title'  => t('Range filter'),
        'help'   => t('Filters records whose [start, end] interval overlaps a user-supplied range. Works on any two date or numeric columns.'),
        'filter' => ['id' => 'views_range_filter_sql'],
      ];
    }
  }
}
