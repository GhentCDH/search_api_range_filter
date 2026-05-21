# views_range_filter — TODO

## Known limitations

### SQL field type coverage

`RangeFilterSql` detects fields with Views filter IDs `numeric`, `date`, `datetime`,
and `daterange_filter`. For a Drupal date range field (`DateRangeItem`), the
individual start (`_value`) and end (`_end_value`) columns are registered in Views
data under the `datetime` filter ID and appear as separate options, so you can
select each independently.

### SQL INT timestamp columns

`RangeFilterSql` produces `YYYY-MM-DD HH:MM:SS` strings for the Year, Date, and
Datetime granularities, which work correctly with SQL DATE, DATETIME, and TIMESTAMP
column types. For INT columns storing Unix timestamps, use **Raw** granularity and
supply Unix timestamps directly.

### BCE / negative-year support

For SQL-backed views, the DATETIME column type in MySQL only supports years 1000–9999.
Years below 1000 require 4-digit zero-padded strings (e.g. `0800-01-01 00:00:00`),
which this module produces via `sprintf`. Negative (BCE) years are not supported by
the SQL filter's DATETIME output; use Raw granularity for databases that store BCE
dates as signed integers.

For Search API–backed views, BCE years produce large negative Unix timestamps; the
`search_api_db` backend stores these as signed integers and the comparison works on
64-bit PHP. Other backends (Solr, Elasticsearch) may or may not support BCE dates
depending on their date field configuration.

### Auto-range cache and configuration changes

The auto-range result (`SELECT MIN/MAX` or Search API query) is cached. After changing
which field a filter is configured against, run `drush cr` to clear the cache;
otherwise the old field's range may still be shown in the dropdown.

### SQL auto-range cache invalidation

The `SELECT MIN/MAX` result is cached for one hour with no cache tag invalidation
(unlike the Search API variant, which invalidates automatically on index update).
Content changes are only reflected after the cache expires.

### Search API backend coverage

`dateTimeToBackend()` handles `search_api_db` (Unix timestamp) and all other
backends (UTC ISO 8601 string). If a backend uses a different date storage
format, disable "Treat range as dates" and supply values in the format the
backend expects.

### Search API as a soft dependency

`search_api` is declared in `composer.json` but not in `views_range_filter.info.yml`.
Sites without Search API can still use `views_range_filter_sql`; attempting to
configure `views_range_filter_sapi` without Search API will produce a fatal error.

## Missing features

- **Automated tests**: no PHPUnit or Kernel tests exist.
- **Exposed filter validation**: no check that the "from" value is not greater than
  the "to" value.
- **BCE years in SQL**: the SQL filter does not support negative-year date values
  (before year 1 CE) because MySQL DATETIME has no BCE representation.
