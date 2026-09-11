# WordPress client 0.1.0 verification

Date: 2026-09-09. Server task:
[Dzen Chat #14](https://github.com/xen/dzen.chat/issues/14).
This historical report concerns the proposed API and synthetic responses.
The current release uses the [implemented contract](../contracts/dzen-chat-api.md).

## Implemented at that date

The plugin in dzen-chat.php, src/ and assets/ calls Integration API v1.
History supports viewing, search, date/widget/visibility filters, message sources,
internal source viewing and external links. Hide/restore uses PATCH with an
explicit state and expected_version. Chat deletion and message editing are
blocked before HTTP.

Also prepared: PKCE connection and status checking, encrypted credentials, basic
widget management, a public page/post queue, trigger settings, document listing
and source status. Products are excluded from synchronization.

## Verified environment

- Separate Docker Compose: WordPress 6.8.2, PHP 8.3, MariaDB 11.7.
- Admin: `http://127.0.0.1:8868/wp-admin/`.
- Only this environment replaces the Dzen API with tests/api-fixture.php through
  pre_http_request. Credentials, conversations and sources are synthetic.
- Browser actions used Playwright CLI against actual WordPress.
- The environment does not change Dzen Chat or production data.

## Completed checks

`make test` passed: PHP lint and **48 WordPress checks**. Coverage includes
encryption, damaged ciphertext, autoload, signed method/path/body, dates/URLs,
history GETs, hide/restore/conflicts, DELETE/message-edit rejection,
403/429/network errors, source reading/ownership, publish/edit/password/trash,
product exclusion, event versions, HTTP 202 versus completed operations, site
moves and absence of history/source text in plugin options.

The then-current tests/browser-checks.js passed through Playwright CLI:

- Hidden chats leave the main list; search and widget filters find them among
  hidden chats; messages remain accessible and restoration works.
- Source text displays in WordPress and survives reload. Script content and
  javascript: links do not execute. API secrets are absent from HTML.
- Original URLs use _blank/noopener. External site content was not checked;
  fixture links point to demonstration addresses.
- Unavailable sources have a clear state without an external link.
- POST without a nonce and subscriber visibility reads/writes are rejected.
- Date filtering works and no delete button is present.

Verification found and fixed an internal-link defect: WordPress removes the
message parameter from admin URLs. The plugin used message_id and reference_id,
with a regression check for source persistence after reload.

Local screenshots, excluded from Git:
`output/playwright/chat-history.png` and
`output/playwright/source-in-wordpress.png`.

`make package` builds `dist/dzen-chat-0.1.0.zip` with one dzen-chat/ root.
The archive was inspected: bootstrap, uninstall, readme, src and assets, without
the test API, fixture credentials or documents. The plugin was activated from
a mounted directory. Installing the ZIP on a clean production-like site had
not yet been tested.

## Result boundary

This verifies a client for a future API, as requested. It does not verify real
code issuance/redemption at chat.dzen.dev, compatible server signatures,
billing, crawler/index/widget answers or server-side hiding. Those require the
implemented contract and separate E2E acceptance. Preserving server billing
history is an issue #14 requirement; the local fixture does not prove it.

Other WordPress/PHP versions, complete widget appearance/source editing,
network-wide multisite and a separate WordPress admin domain were outside this
iteration's verified scope. No WordPress.org publication or production deployment.
