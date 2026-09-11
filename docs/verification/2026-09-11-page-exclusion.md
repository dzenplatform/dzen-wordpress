# Page exclusion verification — 0.11.0

Verified locally on September 11, 2026. Production deployment was not performed.

## Behavior

The page/post editor has a **Remove from index and block updates** button with a
post-specific AJAX nonce and administrator/edit-post capability checks. It acts
without saving the editor, keeps the WordPress page intact and stores a persistent
indexing exclusion. Widget visibility remains independent.

The queue cancels older pending, blocked and failed content submissions. A worker
rechecks the exclusion while holding the same per-post database lock used by
the button and save hooks. Ordinary saves do not enqueue content updates. Publishing
a blocked draft or changing a public permalink enqueues only exclusion requests.
All previously submitted URLs recorded for the current connection are included.

`POST /api/pages/exclude` accepts `{"url": "https://site.example/page"}` and returns
HTTP 200 with the same URL, the assigned `source_id` and `index_status: excluded`.
The plugin validates all three fields before confirming remote removal. Dzen Chat
removes search chunks and saved triggers and keeps a manual-exclusion tombstone.
An unknown URL receives the same tombstone. `POST /api/update` rejects such URLs
with HTTP 409. Source assignment and the registered site's URL boundary apply.

An unavailable service leaves the local exclusion active. The editor distinguishes
pending/failed removal from confirmed removal and supports an explicit retry.
A draft with no previously public URL is blocked without sending a private URL.

## Automated checks

- 311 WordPress contract checks, including 26 exclusion checks and 36 locale checks.
- PHP syntax checks and distributable package checks.
- Dzen Chat PostgreSQL/API tests verify idempotence, unknown URLs, unassigned
  sources, revoked clients, removal of actual chunks and preservation of other
  source content. A concurrent worker transaction commits a late chunk before
  exclusion obtains its row lock; the response confirms removal only after that
  chunk is deleted. Late crawler errors, chunk indexing and embedding completion
  cannot clear the exclusion.
- The complete Dzen Chat test suite is also run by the repository's pre-push hook
  against the exact commit in an isolated checkout.

## Real local browser verification

Used the registration-connected WordPress at `https://local.dzenchat.com:8869`,
with actual local Dzen Chat HTTP endpoints and a temporary public page.

1. The editor showed **Ready in Dzen Chat**. A database read confirmed 737 content
   characters and three search chunks for that page.
2. Clicking the Russian exclusion button changed the editor to **Excluded from
   indexing**, with confirmation from Dzen Chat. The page remained published.
3. The database contained zero content characters and zero search chunks, with
   `excluded_ignored` preserved on the URL record.
4. Saving a content edit left the local event count unchanged (two events before
   and after). A direct attempt to reindex the same URL received HTTP 409.
5. The button was also inspected on the original Privacy Policy draft without
   activating it. At an 843 × 863 viewport, the panel was 280 px wide and had no
   horizontal overflow. English and Russian labels were checked.

The temporary WordPress page, its local events/object row and its Dzen Chat
tombstone were removed after verification. The original administrator locale was
restored. The Privacy Policy page remains a draft and was not excluded.

## Boundaries

Removal concerns future retrieval from the source. It does not erase existing
conversation history, responses already sent to visitors, or content independently
present in another source. Exclusion is per URL on the service and per post in
WordPress. The plugin's background queue requires WP-Cron or an external scheduler.
