# Widget display: version 0.1.1 fix

Date: 2026-09-09. Tested with WordPress 6.8.2 and PHP 8.3.
This historical report covers the original fixture-based integration.

## Reproduction

At `http://127.0.0.1:8868/wp-admin/admin.php?page=dzen-chat-widgets&notice=saved`,
the widget was enabled and selected for the site. The public home page had
neither loader nor widget container. Cached project status had expired, and
WP-Cron was disabled locally. Visiting Widgets did not refresh status.

A second embed defect was found: the `#chat-chat` container required by the
Dzen loader was missing. The test API also used an invented fixture-widget code
without a browser loader; toggling enabled returned a response without saving.

## Changes

- Print the container before the footer loader, using
  [WordPress deferred loading](https://developer.wordpress.org/reference/functions/wp_enqueue_script/).
- Refresh missing status cache with a three-second timeout. Errors suspend
  insertion; another public check is allowed after one minute. Integration
  activity and widget_answers permission remain mandatory.
- Refresh status on Widgets and explain suspension or the absence of a selected
  widget. Toggling requires confirmation of id and is_enabled.
- The local MU fixture persists settings, checks versions and serves a test loader
  requiring the same container. Admin, launcher and panel identify test mode.
  Fixture files are excluded from installation ZIPs.

## Checks

`make test`: **65 checks**, including 17 new checks for insertion, expired
cache, bounded timeout, retries after errors, restrictions, disablement, page
exclusion, another page widget and moving the site.

Playwright CLI: **37 checks** — 21 existing history/source checks and 16 new
checks in tests/widget-browser-checks.js. The new scenario submits POSTs from
the actual WordPress admin and inspects the public page as a separate,
unauthenticated visitor: enabling, disabling, removing placement, selecting
again, reloading, opening/closing the panel and persisted welcome text.
No JavaScript errors were found.

The actual transient was also expired with DISABLE_WP_CRON=true. The next guest
home-page request refreshed status, loaded one script, created one container
and opened a visible panel. Screenshot:
`output/playwright/widget-public-after-expiry.png` (local, not in Git).

## Result boundary

The visible panel is an explicitly labelled **test fixture** without AI answers.
It verifies WordPress admin → API fixture → public embedding. It does not
verify real authorization, the Integration API, the actual Dzen interface,
answer generation or server billing restrictions. Normal plugin installations
continue to load /widget/{code} from chat.dzen.dev.

Updated archive: `dist/dzen-chat-0.1.1.zip`. No production deployment.
