# search_api_range_filter

A lightweight Drupal module that provides a Search API Views filter for range-overlap queries across two index fields.

> **Backend note**: the overlap logic works with any Search API backend. The only Elasticsearch-specific part is the year-to-date conversion used when combining a `date` type field with the dropdown widget — it produces ISO 8601 strings that match Elasticsearch's storage format. On other backends (e.g. database), use the text field widget for date fields, or stick to integer/decimal/float fields.

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

This handles records where the end field is empty (open-ended intervals still match if the start field satisfies the condition).

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
2. Click **Add** next to **Filter criteria** and select **Range filter (two fields)** under the group **Custom Global**.
3. Configure the filter:

| Option | Description |
|---|---|
| Start field | Index field holding the beginning of the range (e.g. `date_start`). |
| End field | Index field holding the end of the range (e.g. `date_end`). |
| Widget type | `Text field` or `Dropdown (consecutive integer range)`. |
| From / To labels | Customizable labels for the exposed filter inputs. |
| Min / Max (dropdown) | Value range for the dropdown widget, with optional "use current year" checkboxes. |

Date fields with the dropdown widget: year integers are automatically converted to ISO 8601 boundary strings (`>= year` → Jan 1 at 00:00:00; `<= year` → Dec 31 at 23:59:59).

## License

GPL-2.0-or-later.
