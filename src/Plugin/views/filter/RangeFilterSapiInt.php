<?php

namespace Drupal\views_range_filter\Plugin\views\filter;

/**
 * Search API numeric range filter — works on integer, decimal, and float fields.
 *
 * @ViewsFilter("views_range_filter_sapi_int")
 */
class RangeFilterSapiInt extends RangeFilterSapi {

  protected string $mode = 'integer';

}
