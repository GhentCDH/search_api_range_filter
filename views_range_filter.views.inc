<?php

/**
 * @file
 * Views data for the views_range_filter module.
 */

function views_range_filter_views_data(): array {
  $data = [];

  $data['views']['range_filter_sapi'] = [
    'title'  => t('Range filter (two Search API fields)'),
    'help'   => t('Filters records whose [start, end] interval overlaps a user-supplied range. Works on any two date or numeric fields in a Search API-backed View.'),
    'filter' => [
      'id'    => 'views_range_filter_sapi',
      'title' => t('Range filter (two Search API fields)'),
    ],
  ];

  $data['views']['range_filter_sql'] = [
    'title'  => t('Range filter (two SQL fields)'),
    'help'   => t('Filters records whose [start, end] interval overlaps a user-supplied range. Works on any two numeric or date columns in a SQL-backed View.'),
    'filter' => [
      'id'    => 'views_range_filter_sql',
      'title' => t('Range filter (two SQL fields)'),
    ],
  ];

  return $data;
}
