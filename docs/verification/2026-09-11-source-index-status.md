# Source indexing status and widget editor links

Version: 0.7.0. Checked locally on September 11, 2026.

## Implemented behavior

- Index shows the connected WordPress source, total pages and five status
  counts. The progress bar includes errors, pending, processing and ready;
  excluded pages have a separate counter. Document tables and filters are removed.
- Counts come from `GET /api/sources/{site_source_id}/status`, without a
  100-document limit. The API shares classification with the Sources screen,
  enforces project/source assignment, and returns the source's browser URL.
- Refresh requests current counts. Empty, excluded-only, paused, restricted,
  invalid-response and unavailable-service states are handled explicitly.
- Widget responses include `edit_url`; WordPress opens each widget's actual
  editor on the configured service origin.

## Automated verification

- 21 focused Dzen Chat tests passed, covering HTTP/OpenAPI, source authorization,
  all five groups, 119 source pages, an empty source, foreign/unassigned sources,
  revoked clients and parity with the Sources UI aggregation. Widget list,
  detail, creation and update responses return matching editor links.
- WordPress: 59 general, 47 widget, 24 indexing, 36 registration and 26
  internationalization checks passed. The indexing checks cover invalid counts,
  mismatched totals/source IDs, unsafe detail URLs, query overrides, escaped
  titles, unavailable API responses and empty/excluded-only states.
- PHP syntax and translation format checks passed. English source strings and
  Russian PO/MO translations include the indexing interface and plural counts.
- All 7 packaging checks passed. The versioned runtime-only ZIP and its SHA-256
  checksum were built as `dist/dzen-chat-0.7.0.zip` and `.zip.sha256`.

## Real local browser verification

The real WordPress instance at `https://local.dzenchat.com:8869/` used its saved
registration against `https://local.dzenchat.com`, without the API fixture.

- Index showed 9 pages: 5 pending, 4 ready and zero in the other groups. The
  connected project's Sources screen showed the same values.
- The detail URL opened the connected source settings, showing 9 discovered
  pages. Refresh returned the current counts.
- English and Russian interfaces rendered correctly. At the supplied 619 × 863
  viewport and at 375 × 812, the Russian controls remained visible with no
  horizontal overflow. Temporary viewport and user-language changes were reset.
- Both project widgets supplied their own editor links. The selected widget's
  URL opened its actual Dzen Chat editor with the matching widget name.

The separately supplied reference project `kermF8jzqC` was blocked by the browser
with `ERR_BLOCKED_BY_CLIENT`; it was not accessed through another environment.
The functional comparison above used the actual connected WordPress source.

This report covers local implementation and verification. No production service
deployment or tagged release publication was performed.
