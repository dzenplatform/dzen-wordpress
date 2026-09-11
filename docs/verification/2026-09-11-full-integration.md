# WordPress 0.4.0: real integration verification

September 11, 2026. The implemented plugin features were tested against the
running local Dzen Chat service. **This does not claim completion of the entire
original specification**: missing server methods are listed below. The main
project and production were not changed.

## Environment

- WordPress 6.8.2, PHP 8.3, separate dzen-wordpress-registration Compose project.
- Site and admin: https://local.dzenchat.com:8869/.
- Real service/API: https://local.dzenchat.com/, /api-docs/.
- Existing encrypted connection preserved. The real overlay trusts the local
  CA without substituting responses. TLS verification stays enabled.
- Plugin code mounted directly from the repository into running WordPress.
  The cron service runs only dzen_chat_worker, not other jobs.
- Fixtures run separately on port 8870 without real credentials.

## Browser verification

The tested content and widget names were Russian. Names below are translated
for this report; the original values and control codes were not changed.

1. The real API created a widget from WordPress, named “WordPress assistant —
   integration test”, and selected it for the site.
   Loader: https://local.dzenchat.com/widget/OoIUGvQYZdo.
   The public page had one mount and one launcher, opening the real iframe.
2. A message was sent to Dzen Chat and received an AI answer. The same
   conversation was opened through the history API inside WordPress, showing
   the visitor message and answer.
3. Published test page ID 5, “Dzen Chat test: sample delivery”.
   The original seed was tests/live-page.html. It contains fictional terms,
   without a real shop, purchases or services. That repository seed was
   subsequently translated to English; the live test used Russian content.
4. Publication automatically created a queue event. After the job ran, the page
   appeared in the real index as 2BuTKDapw9 without processing errors.
   The assistant answered 350 rubles and control code МАЯК-47, linking the page.
5. Content changed to 450 rubles and МАЯК-48. An event appeared automatically
   at 01:45:47 UTC. The background scheduler submitted it without manually
   running the worker. The index detail showed 01:45:55 UTC, and a new
   conversation answered using 450 rubles and МАЯК-48.
6. WordPress filtering found the new conversation,
   01a08e25-3444-7664-bec1-fa88c10f2d24. Its detail showed the same updated answer.
7. From Index, opened document/source details, original links and the WordPress
   editor. Searching the Russian word for “sample” found the test page.
   Manual reindexing returned “Request accepted” and an intermediate crawl state.
8. Disabling the widget through WordPress removed the loader, mount and launcher
   from the home page. Re-enabling restored exactly one real script embed.
9. Saved “Hide widget” in the test page editor. The launcher disappeared on
   that page but remained on the home page. Clearing the setting restored it.
   The test page and working connection were left available for review.

Live verification found that the API normalizes a home-page URL by adding a
trailing slash. Strict string comparison incorrectly reported failure after a
successful request. Comparison was fixed using WordPress's Requests URL
normalization. A repeated home-page request was confirmed in the browser.

## Automated checks

PHP lint and **135 contract checks**:

- 59: transport, list DTOs, encryption, invalid credentials, access limits,
  safe rendering, queue, initial reconciliation after upgrade, retries,
  rate limits, permalink/client changes and site cloning.
- 40: real widget API contract, management, placement, cache and failures.
- 36: registration, PKCE, state/session/TTL, code replay, callback and credentials.

**Six packaging checks** also passed: runtime contents, checksum,
reproducibility, metadata, changelog, tags and symlink rejection.

~~~sh
COMPOSE_PROJECT_NAME=dzen-wordpress-widget-tests DZEN_WORDPRESS_PORT=8870 make test
make package-test
make package
~~~

Package: dist/dzen-chat-0.4.0.zip and its adjacent SHA-256 file.
Fixtures and contract checks do not establish the behavior of nonexistent
methods. The old browser-checks.js that tested simulated hiding was removed;
0.1 results remain historical reports only.

## Incomplete original requirements

- Hide/restore conversations and individual answer sources:
  [server issue #14](https://github.com/xen/dzen.chat/issues/14) remains open.
- Server search, history/index pagination and message continuation.
  Current filters cover only the received 100 objects.
- Page trigger management and separate project activity/billing status.
- Confirmed removal/exclusion from retrieval after publication is withdrawn.
  POST /api/update means recrawling, not guaranteed deletion.

These limits are visible in the interface and listed in the
[contract](../contracts/dzen-chat-api.md). Billing is not inferred from a
successful request. There is no local hiding or simulated deletion.
Live verification used localhost, without production deployment.
Exchange codes, real client_id/client_secret values and signed conversation
URLs are excluded from this report and Git.
