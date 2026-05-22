<?php

namespace Drupal\views_range_filter\Plugin\views\filter;

/**
 * SQL numeric range filter — works on numeric columns.
 *
 * @ViewsFilter("views_range_filter_sql_int")
 */
class RangeFilterSqlInt extends RangeFilterSql {

  protected string $mode = 'integer';

}
