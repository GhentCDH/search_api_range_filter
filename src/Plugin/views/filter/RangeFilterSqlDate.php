<?php

namespace Drupal\views_range_filter\Plugin\views\filter;

/**
 * SQL date range filter — works on date and datetime columns.
 *
 * @ViewsFilter("views_range_filter_sql_date")
 */
class RangeFilterSqlDate extends RangeFilterSql {

  protected string $mode = 'date';

  /**
   * Marks this filter as a date handler so that BEF DatePickers can detect it.
   *
   * @var mixed
   */
  public mixed $date_handler = TRUE;

}
