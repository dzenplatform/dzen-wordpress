# Editor page triggers: 0.10.0

## Result

The Dzen Chat page/post meta box displays saved page-specific triggers beneath
indexing status and above widget placement. It shows explicit states for a source
with triggers disabled, a URL outside the trigger rules, and triggers that have
not been generated. A direct link opens the indexed page in Dzen Chat.

The list refreshes on editor load, through **Refresh status**, and after a
successful Gutenberg save. Trigger text is rendered as text, with no action that
starts a chat. A trigger API failure does not erase an already confirmed indexing
status. The interface and errors have English and Russian translations.

## Real local verification

On September 11, 2026, checked the reported editor in the in-app browser:
`https://local.dzenchat.com:8869/wp-admin/post.php?post=1&action=edit`.

The public URL resolves to indexed page `kWYOrmF8jz` in source `RRSqQpRoj6`.
That source has triggers disabled. WordPress correctly shows that state alongside
**Ready in Dzen Chat** and preserves the widget selector. The direct link opens
`https://local.dzenchat.com/projects/NIPkuXrwjx/pages/kWYOrmF8jz`, verified as the
same Hello world! page in Dzen Chat.

Checked both English and Russian on the real editor. Source settings and the
real post were not changed. The administrator's original default-language
preference was restored.

## Isolated list and save checks

Used a temporary published page with the `/delivery/` URL in the separate fixture
WordPress on port 8870. It showed the two saved test triggers from the fixture API.
At an 843 × 863 CSS-pixel viewport, the trigger panel fits the editor sidebar:
panel width and scroll width are both 232 pixels, and long text wraps.

To verify refresh after saving, changed the fixture API to its revoked-client
scenario, then clicked **Save** on the test page. The trigger panel updated from
the visible list to an unavailable state and removed the old items. Restored the
normal fixture API and clicked **Refresh status**; both items returned. The
temporary fixture page and its local queue records were removed after testing.

This fixture proves list rendering and refresh behavior, not successful live
trigger generation. No trigger generation was requested on the real source.

## API and automated validation

Added the authenticated, read-only `GET /api/pages/{page_id}/triggers` endpoint.
It verifies the client's project and source assignment, shares the existing
source URL rules and trigger normalization, and exposes neither response caches
nor signed widget page tokens. It does not discover, crawl or generate pages.
See the [API contract](../contracts/dzen-chat-api.md).

- WordPress syntax and 283 contract checks passed, including 54 editor status and
  trigger checks and 34 internationalization checks.
- Seven packaging checks passed, including reproducibility and translation files.
- Dzen Chat's complete pre-push suite passed: 1105 tests, 3 skipped. The focused
  trigger test covers all source-policy states, foreign/unassigned pages,
  revoked clients, private-field exclusion and unchanged stored triggers.
- Built `dist/dzen-chat-0.10.0.zip` and its SHA-256 companion file.

No production deployment or tagged GitHub release was performed.
