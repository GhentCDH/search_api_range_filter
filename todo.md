# views_range_filter — TODO

## Known limitations

### SQL field type coverage

`RangeFilterSql` detects fields with Views filter IDs `numeric`, `date`, `datetime`,
and `daterange_filter`. For a Drupal date range field (`DateRangeItem`), the
individual start (`_value`) and end (`_end_value`) columns are registered in Views
data under the `datetime` filter ID and appear as separate options, so you can
select each independently.

### SQL INT timestamp columns

The Date range filter detects whether a field stores timestamps (integer), datetime
strings, or date-only strings, and formats the comparison value accordingly.
For integer columns that store Unix timestamps the filter passes the raw Unix
integer — no string conversion is applied.

For integer columns that are not timestamps and not year-only values (e.g. a custom
numeric date encoding), use the **Numeric range filter** instead and supply values
in whatever format the column actually stores.

### Integer fields in the Date range filter

When the Date range filter cannot determine that an integer column stores Unix
timestamps, it falls back to extracting the year from the parsed date and
comparing that integer directly. This is correct for "year of publication" style
fields (e.g. `1492`, `2024`), but wrong for integer columns that store something
other than a year. Use the **Numeric range filter** for any integer field whose
values are not year numbers.

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

## Search API database backend (`search_api_db`)

The SAPI filter plugins have only been tested with Solr/Elasticsearch backends.
The first confirmed test against a `search_api_db` (database) backed index exposes
the following potential issues.

### NULL handling in `search_api_db`

`search_api_db` stores indexed field values in separate per-field tables.  When a
field has no value for an item, there is simply **no row** in that table — the value
is not stored as SQL `NULL`.  As a result, SAPI conditions that test `field IS NULL`
(generated for `single_bound_behaviour = 'open'` and `'equal'`) will never match
those items.

Practical effect: with the default `single_bound_behaviour = 'equal'` setting and
a two-field overlap, records where only one bound field is indexed will disappear
from results when they should still match.

Possible fix: detect the `search_api_db` backend in `applyConditions()` and skip
the NULL-fallback branches, or restructure the overlap logic so NULL conditions are
not needed for the DB case.

### Auto-range via `queryMinMax` for `search_api_db`

`search_api_db` does not populate indexed field values in search result items —
only item IDs are returned.  `queryMinMax()` therefore falls back to
`$index->loadItemsMultiple()` to retrieve the actual field value from the item.
This adds one extra load per boundary (min and max) when auto-range is used with
a DB-backed index.

### Date value type for `search_api_db` conditions

`tsToBackend()` returns a PHP `int` (Unix timestamp) for `search_api_db`, which is
then cast to `string` before being passed to `addCondition()`.  The DB backend's
query translator may handle this correctly via SQL type coercion, but it has not
been verified.  If comparisons silently fail, consider passing the raw `int` to
`addCondition()` instead of a string.

### Integration test coverage

Neither a Kernel test nor a functional test exists for any SAPI backend.  The SAPI
filter code path is entirely untested via automated tests.

## Missing features

- **Automated tests**: no PHPUnit or Kernel tests exist.
- **Exposed filter validation**: no check that the "from" value is not greater than
  the "to" value.
- **BCE years in SQL**: the SQL filter does not support negative-year date values
  (before year 1 CE) because MySQL DATETIME has no BCE representation.
