# search_api_range_filter

A lightweight Drupal module that provides a Search API Views filter for range-overlap queries across two index fields.

> **Backends**: tested with the Search API Database backend (automated tests) and Elasticsearch (`elasticsearch_connector`). Date values are sent as UTC timestamps in numeric strings, which both backends understand.

## Purpose

Provides a Views filter (`@ViewsFilter("search_api_range_filter")`) that matches records whose stored `[start, end]` interval overlaps a user-supplied `[from, to]` range. Designed for date or numeric fields on a Search API index (e.g. filtering events by active period).

## Architecture overview

```
src/Plugin/views/filter/RangeFilter.php   — the Views filter plugin
search_api_range_filter.views.inc         — Views data integration
config/schema/                            — Views config schema for export
```

## How the overlap logic works

A record matches when its stored interval overlaps the filter range:

```
COALESCE(end, start) >= from   AND   COALESCE(start, end) <= to
```

Expanded into Search API condition groups:
- `(end >= from) OR (end IS NULL AND start >= from)`
- `(start <= to) OR (start IS NULL AND end <= to)`

By default, a record without an end value ends at its start value. With **Records without an end value: Are still running**, such records match every "from" value (as long as they have a start), which suits ongoing periods like "active since 1990".

When "from" is after "to", the two values are swapped.

## Date values

On `date` index fields, both widgets accept a year (`YYYY`); the text field also accepts a month (`YYYY-MM`) or a date (`YYYY-MM-DD`). "From" means the first second of that period and "to" its last second, in UTC. Historical and negative years work: `50` is the year 50, not 2050. Invalid input shows a validation message.

## Requirements

- Drupal 10 or higher
- `drupal:views` module
- `search_api:search_api` module

### Optional

- [`views_filters_summary`](https://www.drupal.org/project/views_filters_summary): when present, the active filter values are shown in a human-readable summary line (`≥ 2020`, `2018 – 2024`, etc.).

## Installation

### Via Composer from GitHub (recommended)

Add the repository to your project's `composer.json`:

```json
"repositories": [
  {
    "type": "vcs",
    "url": "https://github.com/GhentCDH/search_api_range_filter"
  }
]
```

Then require the module:

```bash
composer require drupal/search_api_range_filter
```

Enable the module:

```bash
drush en search_api_range_filter
```

### Manual installation

Clone or copy this repository into `web/modules/custom/search_api_range_filter/` and enable via Drush or the Drupal admin UI.

## Configuration

1. Open a View backed by a Search API index.
2. Click **Add** next to **Filter criteria** and select **Range filter (two fields)** in the index's group.
3. Configure the filter:

| Option | Description |
|---|---|
| Start field | Index field holding the beginning of the range (e.g. `date_start`). |
| End field | Index field holding the end of the range (e.g. `date_end`). Must have the same type as the start field. Leave empty to filter on a single date or number: records match when that value lies within the range. |
| Records without an end value | *End at their start value* (default) or *Are still running*. Only used with an end field. |
| Widget type | `Text field`, `Number field` (on date fields: a year) or `Dropdown (consecutive integer range)`. |
| From / To labels | Customizable labels for the exposed filter inputs. |
| Minimum / Maximum value (dropdown) | *Fixed value*, *Current year*, or *Lowest/Highest value in the results*. |

The filter is normally exposed. When it is not exposed, the from/to values entered in the filter settings are always applied; when it is exposed, they are the default values. Grouped filters and operators are not supported.

### Dropdown bounds from the results

With *Lowest value in the results* / *Highest value in the results*, the dropdown starts at the lowest start (or end) value and ends at the highest end (or start) value of the items the view shows **without its exposed filters**: fixed filters and contextual filters count, the visitor's input does not. On date fields, this is the year.

The values are looked up with one small search per field and cached until the index is updated or the view is saved. Access checks run as an anonymous user, so unpublished items do not widen the range. When there are no values, the fixed value is used.

With *Current year*, the dropdown's cached output expires on 1 January.

## Running the tests

The kernel tests use the Search API Database backend and need `drupal/search_api` installed:

```bash
cd web
SIMPLETEST_DB=sqlite://localhost//tmp/test.sqlite ../vendor/bin/phpunit -c core/phpunit.xml.dist modules/custom/search_api_range_filter/tests
```

## License

GPL-2.0-or-later.
