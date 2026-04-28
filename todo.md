# search_api_range_filter — TODO

## Known limitations

- **Backend portability**: the filter uses Search API's condition group
  abstraction and is theoretically backend-agnostic, but has only been tested
  with Elasticsearch. NULL conditions (`end IS NULL`) are correctly rewritten
  by the Elasticsearch connector to native `exists` queries. On other backends,
  NULL handling and the ISO 8601 date conversion in `convertYearToDateString()`
  (which targets Elasticsearch's date format) may need adjustment.

## Missing features

- **Tests**: no automated tests exist.
