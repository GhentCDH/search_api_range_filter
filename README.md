# views_range_filter

A Drupal module that provides Views filters for range-overlap queries across two fields. Supports both Search API-backed Views (any backend) and regular SQL-backed Views.

## Purpose

Match records whose stored `[start, end]` interval overlaps a user-supplied `[from, to]` range. Designed for date or numeric fields — for example, filtering events by active period or filtering objects by a value range.

## Architecture

```
src/Plugin/views/filter/
  RangeFilterBase.php       — abstract base (options form, widget, sanitization)
  RangeFilterSapi.php       — Search API abstract base
  RangeFilterSapiDate.php   — SAPI date filter   (@ViewsFilter("views_range_filter_sapi_date"))
  RangeFilterSapiInt.php    — SAPI numeric filter (@ViewsFilter("views_range_filter_sapi_int"))
  RangeFilterSql.php        — SQL abstract base
  RangeFilterSqlDate.php    — SQL date filter     (@ViewsFilter("views_range_filter_sql_date"))
  RangeFilterSqlInt.php     — SQL numeric filter  (@ViewsFilter("views_range_filter_sql_int"))
views_range_filter.views.inc   — Views data integration (registers all four filters)
config/schema/                 — Views config schema for config export
```

## How the overlap logic works

A record matches when its stored interval overlaps the filter range:

```
COALESCE(end, start) >= from   AND   COALESCE(start, end) <= to
```

Expanded into condition groups to handle NULL fields:
- `(end >= from) OR (end IS NULL AND start >= from)`
- `(start <= to) OR (start IS NULL AND end <= to)`

Records where the end field is empty still match if the start field satisfies the condition (open-ended intervals).

**Single-field mode** (configurable per filter instance): simplifies to `field >= from AND field <= to` — useful when records carry a single date/value rather than a start+end pair.

## Requirements

- Drupal 10 or 11
- `drupal:views` module
- `drupal:search_api` module (required only for the Search API filter; the SQL filter works without it)

### Optional

- [`views_filters_summary`](https://www.drupal.org/project/views_filters_summary): shows the active filter values in a human-readable summary line (`≥ 2020`, `2018 – 2024`, etc.).

## Installation

### Via Composer from GitHub

Add the repository to your project's `composer.json`:

```json
"repositories": [
  {
    "type": "vcs",
    "url": "https://github.com/GhentCDH/views_range_filter"
  }
]
```

Then require and enable the module:

```bash
composer require drupal/views_range_filter
drush en views_range_filter
```

### Manual installation

Clone or copy this repository into `web/modules/custom/views_range_filter/` and enable via Drush or the Drupal admin UI.

## Configuration

1. Open a View — either a Search API view or a regular SQL-backed view.
2. Click **Add** next to **Filter criteria**. Two range filter entries appear under the view's base table group:
   - **Date range filter** — for date and datetime fields.
   - **Numeric range filter** — for integer, decimal, and float fields.
   In a Search API view these work against index fields; in a SQL view they work against database columns.
3. Configure the filter.

### Filter options

| Option | Description |
|---|---|
| Start field | Field holding the beginning of the range (e.g. `date_start`). |
| End field | Field holding the end of the range (e.g. `date_end`). Select the same field as Start to use single-field mode (`field >= from AND field <= to`). |
| Records with exactly one missing field | Controls how records with a NULL start or end are treated — open-ended, point, or excluded. |
| Records where both fields are missing | Whether to always include or exclude records with both fields NULL. |
| From / To labels | (Exposed only) Customizable labels for the filter inputs. |
| Auto-calculate range | (Dropdown widget only) Derive min/max from the actual data; cached 1 hour. |
| Min / Max | (Dropdown widget only) Manual bounds, with optional "use current year" checkboxes. |

### Date mode: boundary expansion

The **Date range filter** converts each user-supplied value to a period boundary before comparing. The input format determines precision:

| Input format | User enters | `>=` boundary | `<=` boundary |
|---|---|---|---|
| Year | `1492` | `1492-01-01 00:00:00` | `1492-12-31 23:59:59` |
| Month | `1492-03` | `1492-03-01 00:00:00` | `1492-03-31 23:59:59` |
| Day | `1492-03-15` | `1492-03-15 00:00:00` | `1492-03-15 23:59:59` |
| Hour | `1492-03-15 14` | `1492-03-15 14:00:00` | `1492-03-15 14:59:59` |
| Minute | `1492-03-15 14:30` | `1492-03-15 14:30:00` | `1492-03-15 14:30:59` |
| Second | `1492-03-15 14:30:45` | `1492-03-15 14:30:45` | `1492-03-15 14:30:45` |

The converted boundary is then formatted per field type:

| Field type | Search API (search_api_db) | Search API (Solr / ES / other) | SQL |
|---|---|---|---|
| Date field | Unix timestamp (int) | UTC ISO 8601 string | `YYYY-MM-DD HH:MM:SS` string |
| Integer field | Year as integer | Year as integer | Year as integer |

The **Dropdown widget** always emits consecutive integer year values, regardless of the input format above.

## License

GPL-2.0-or-later.
