<?php

/**
 * @file
 * Views integration for the Search API Range Filter module.
 */

/**
 * Implements hook_views_data_alter().
 *
 * Registers the "Range filter (two fields)" pseudo-field on every
 * Search API index table so it appears in the Views "Add filter" dialog
 * for any view backed by a Search API index.
 */
function search_api_range_filter_views_data_alter(array &$data): void {
  foreach ($data as $table_name => &$table) {
    if (!str_starts_with($table_name, 'search_api_index_')) {
      continue;
    }

    $table['search_api_range_filter'] = [
      'title' => t('Range filter (two fields)'),
      'help' => t('Filter by range overlap across two index fields (e.g. a start date and an end date). A record matches when its [start, end] interval overlaps the [from, to] values entered by the user.'),
      'filter' => [
        'id' => 'search_api_range_filter',
      ],
    ];
  }
}
