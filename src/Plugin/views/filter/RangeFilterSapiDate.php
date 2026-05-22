<?php

namespace Drupal\views_range_filter\Plugin\views\filter;

/**
 * Search API date range filter — works on date fields only.
 *
 * @ViewsFilter("views_range_filter_sapi_date")
 */
class RangeFilterSapiDate extends RangeFilterSapi {

  protected string $mode = 'date';

  /**
   * Marks this filter as a date handler so that BEF DatePickers can detect it.
   *
   * @var mixed
   */
  public mixed $date_handler = TRUE;

}
