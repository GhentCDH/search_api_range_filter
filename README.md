# views_range_filter

A Drupal module that provides Views filters for range-overlap queries across two fields. Supports both Search API-backed Views (any backend) and regular SQL-backed Views.

## Purpose

Match records whose stored `[start, end]` interval overlaps a user-supplied `[from, to]` range. Designed for date or numeric fields — for example, filtering events by active period or filtering objects by a value range.

## Architecture

```
src/Plugin/views/filter/
  RangeFilterBase.php     — abstract base (options form, widget, sanitization)
  RangeFilterSapi.php     — Search API filter (@ViewsFilter("views_range_filter_sapi"))
  RangeFilterSql.php      — SQL filter       (@ViewsFilter("views_range_filter_sql"))
views_range_filter.views.inc   — Views data integration (registers both filters)
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

### Search API filter

1. Open a View backed by a Search API index.
2. Click **Add** next to **Filter criteria** and select **Range filter (two Search API fields)** under the **Global** group.
3. Configure the filter.

### SQL filter

1. Open a regular (SQL-backed) View.
2. Click **Add** next to **Filter criteria** and select **Range filter (two SQL fields)** under the **Global** group.
3. Configure the filter.

### Filter options

| Option | Description |
|---|---|
| Single field mode | Match records where one field falls within the range (no start/end pair). |
| Start field | Field holding the beginning of the range (e.g. `date_start`). |
| End field | Field holding the end of the range (e.g. `date_end`). Hidden in single-field mode. |
| Widget type | `Text field` for free-form input, or `Dropdown` for a consecutive integer range. |
| From / To labels | Customizable labels for the exposed filter inputs. |
| Auto-calculate range | (Dropdown only) Derive min/max from the actual data; cached 1 hour. |
| Min / Max | (Dropdown only) Manual bounds, with optional "use current year" checkboxes. |

### Date fields with the dropdown widget

When a **Search API** date field is combined with the dropdown widget, year integers are automatically converted to the correct boundary format for the active backend:

| Backend | Storage format | Conversion |
|---|---|---|
| `search_api_db` | Unix timestamp | `mktime()` |
| Elasticsearch / Solr / other | ISO 8601 string | `date('c', mktime(...))` |

`>= year` resolves to Jan 1 at 00:00:00; `<= year` resolves to Dec 31 at 23:59:59.

For **SQL** date columns with the dropdown widget, supply the values in the format your column uses (Unix timestamps or plain year integers).

## License

GPL-2.0-or-later.
