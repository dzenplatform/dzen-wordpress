# Page indexing status in the editor

Version: 0.8.0. Verified locally on September 11, 2026.

The Dzen Chat meta box now displays indexing status above the existing widget
controls. Loading and refresh are asynchronous; a successful Gutenberg save
refreshes the status without reloading or discarding editor changes. A manual
refresh button and source overview link are available in both editor types.

The panel separates the last saved change's WordPress queue state from the
page's Dzen Chat indexing state. Draft/private/password-protected and explicitly
excluded content is identified without submitting or querying a private URL.
An unavailable API does not become an absent page or retain a success badge.

## Checks

- 225 WordPress checks passed: 59 general, 47 widgets, 24 aggregate indexing,
  29 per-page indexing, 36 registration and 30 internationalization checks.
- Per-page tests cover all five server statuses, exact URL/source matching,
  missing pages, malformed responses, network/revoked access, pending/accepted/
  failed submission, private and protected content, local exclusions, and
  independent widget visibility. AJAX tests reject missing/invalid nonces and
  users without administrator access before making an API request.
- Six focused Dzen Chat HTTP/OpenAPI tests passed. URL and source filters apply
  before the list limit and find older pages among more than 100 newer records.
  Foreign/unassigned sources cannot be inspected. Root URLs are normalized, and
  page status classification covers errors and every exclusion category.
- PHP syntax, Python type/lint and gettext catalog format checks passed.

## Real local WordPress

- The user's Privacy Policy editor (`post=3`) displays the unpublished state
  and explains that only published pages/posts are sent for indexing. It remains
  a draft; saving it did not publish it.
- The existing published integration test page (`post=5`) reports its real
  indexed state through the connected source. After saving it without content
  edits, the panel refreshed automatically and separately displayed that the
  saved changes were waiting for submission.
- A temporary service reload produced an error state; manual refresh recovered
  the actual server status without an editor reload.
- English and Russian messages render in the narrow editor sidebar at the
  supplied 843 × 863 viewport. Widget settings remain present. Temporary profile
  language changes are restored after verification.

The local API and WordPress connection are real; the separate disposable fixture
is used only for automated contracts. Production needs the matching API update
before using per-page lookup. No production deployment or tagged release was
performed as part of this change.
