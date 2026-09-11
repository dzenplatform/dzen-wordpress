# Unified Index and document uploads — 0.13.0

## Behavior

**Dzen Chat → Index** includes the connected WordPress site, additional website
sources assigned to the integration, and uploaded project documents. Website
sources have separate progress bars and counters; titles and settings buttons
open the corresponding source in Dzen Chat. Excluded pages remain outside the
progress bar. Files have aggregate progress and individual processing/error states.
The old Sources menu is removed, and its URL redirects to Index.

**Add a document** accepts one TXT or Markdown file (`.txt`, `.md`, `.markdown`)
in UTF-8, up to 256 KiB or the WordPress upload limit if lower. It uses the existing
JSON Files API, without creating a WordPress media attachment. Administrators can
read escaped document text inside WordPress and open its editor in Dzen Chat.
PDF and Office uploads are not supported by this API. Upload acceptance and
successful indexing are separate states.

## Automated checks

- `COMPOSE_PROJECT_NAME=dzen-wordpress-widget-tests DZEN_WORDPRESS_PORT=8870 make test`
  passed all 361 checks, including 29 source-index and 28 document checks.
- Coverage includes additional source identity and progress, partial failures,
  UTF-8/BOM handling, permitted extensions, size/binary/empty-file rejection,
  administrator and nonce checks, refusal to read arbitrary local paths,
  exact upload acknowledgments, escaped text, and indexing errors overriding `ready`.
- All source messages have matching Russian translations. The 40 locale checks
  include the upload form in English and Russian.
- After adjusting the legacy redirect to run before WordPress menu access checks,
  PHP lint and all 29 source-index checks passed again.
- Gettext format validation and `git diff --check` passed.
- `make package-test`: seven tests passed. The installable 0.13.0 ZIP contains
  21 runtime files, including the new file client and both translation catalogs;
  no test fixtures or development documents are included.

## Real local service verification

WordPress: `https://local.dzenchat.com:8869/wp-admin/admin.php?page=dzen-chat-documents`.
The registration Compose overlay uses the real API with TLS verification.

- The Index page displayed the connected site's nine pages: five pending and four
  ready. The same source's Dzen Chat settings showed nine discovered pages.
- This API client currently has one assigned website source. The additional
  sources section correctly displayed an empty state and the project sources link.
- Uploaded a synthetic 512-byte Markdown document through the browser file chooser
  and WordPress form. It appeared as Processing, then Ready with no indexing error.
- The WordPress detail view preserved the UTF-8 text, apostrophes and literal HTML
  example as escaped text. The corresponding Dzen Chat editor opened with the same
  filename and full content. A WordPress attachment lookup found no matching file.
- The source settings link resolved to the correct source settings page under
  the normal Dzen Chat web session.
- The former Sources URL redirected to Index successfully. The menu contains
  Overview, Widgets, Index and Conversations.
- The Index and upload controls were checked at the supplied 843-pixel viewport
  width. The document scroll width remained 843 pixels, without horizontal overflow.
- Removed only the synthetic test document through the existing API after checking
  its exact ID, filename and test marker. A subsequent GET returned HTTP 404.

## Isolated browser checks

On `http://127.0.0.1:8870`, temporary fixtures supplied a second source and a file
whose `status_error=too_big` overrides its raw `ready` status. The Russian page
showed three bars: current site, additional site and uploaded documents. Counts,
settings links, error explanation and upload controls were verified in the browser.

A synthetic `.pdf` upload was rejected by WordPress with the Russian supported-
format message. The fixture document count remained unchanged. Temporary source,
file and language options were removed after testing. The original site-wide
widget selection was restored, and the previous 404 setting was preserved.
The legacy Sources URL with a file ID also redirected to that document's Index
detail view successfully.

Additional-source and failure cases above are fixture verification, not evidence
of a second real assigned source. No production deployment or main Dzen Chat
code change is included. Large file lists remain subject to the API transport's
existing 2 MiB response cap; metadata-only listing and pagination are separate work.
