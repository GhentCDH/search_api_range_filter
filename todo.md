# views_range_filter — TODO

## Known limitations

- **SQL date columns with the dropdown widget**: `RangeFilterSql` passes the selected
  integer value directly to the SQL query without date conversion. For Unix-timestamp
  date columns use the text field widget and supply timestamps manually, or use integer
  year columns instead.

- **SQL auto-range cache invalidation**: the `SELECT MIN/MAX` result is cached for one
  hour with no cache tag invalidation (unlike the Search API variant, which invalidates
  on index update). Content changes are only reflected after the cache expires.

- **Search API backend coverage**: the overlap logic uses Search API's condition group
  abstraction and is backend-agnostic. The `convertYearForBackend()` method in
  `RangeFilterSapi` explicitly handles `search_api_db`, Elasticsearch, and Solr.
  Other backends fall back to ISO 8601 strings; if that format does not match your
  backend's date storage, use the text field widget with native values instead.

- **Search API as a soft dependency**: `search_api` is declared in `composer.json`
  but not in `views_range_filter.info.yml`. Sites that install this module without
  Search API can still use `views_range_filter_sql`; attempting to configure
  `views_range_filter_sapi` without Search API will produce a fatal error.
  If you use only the SQL filter, remove the `search_api` requirement from
  `composer.json` to avoid the unnecessary dependency.

## Missing features

- **Automated tests**: no PHPUnit or Kernel tests exist.
- **Exposed filter validation**: there is no client- or server-side check that
  the "from" value is not greater than the "to" value.
