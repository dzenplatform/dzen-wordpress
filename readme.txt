=== Dzen Chat ===
Contributors: dzenplatform
Requires at least: 6.8
Tested up to: 6.8.2
Requires PHP: 8.2
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Authorize your WordPress site in Dzen Chat using a one-time code and PKCE.

== Description ==

Dzen Chat is an external service at https://chat.dzen.dev. Connecting the plugin
opens its consent page to choose or create a project. WordPress exchanges the
one-time code server-to-server and stores encrypted credentials and the site
source identifier. Credentials are never exposed in page HTML or JavaScript.

Widget management, indexing, conversation history and source status screens are
planned for subsequent Dzen Chat API releases. This authorization-only release
does not start content synchronization or validate project billing status.

The plugin stores encrypted integration credentials in WordPress. It does not
store conversation text or source excerpts. The service must support /auth/add
and /auth/exchange/. Registration has been tested with the local Dzen Chat server.

== Installation ==

1. Upload the ZIP through Plugins > Add New > Upload Plugin and activate it.
2. Open Dzen Chat and connect your site. HTTPS and PHP sodium are required.
3. Choose or create a project at chat.dzen.dev and return to WordPress.
4. WordPress confirms authorization after exchanging the returned code.
5. Use Dzen Chat to manage the project while additional WordPress APIs are pending.

== Frequently Asked Questions ==

= Does hiding remove billing data? =
No. Planned conversation APIs will change visibility without deleting billing data.

= What happens when I deactivate or uninstall? =
Deactivation stops widget insertion and scheduled jobs. Uninstall removes local
plugin settings, credentials and queue only. Revoke the integration through the
service. Removing keys in WordPress does not revoke the remote API client.

= Are products synchronized? =
No. Products require a separate integration.

== Changelog ==

= 0.2.0 =
Use the implemented /auth/exchange/ endpoint and its actual credentials response.
Keep registration independent of the future management API and indexing queue.

= 0.1.1 =
Add the required widget mount container and refresh expired project status on
public requests. Clarify widget placement and expose unavailable project status.

= 0.1.0 =
WordPress client for the proposed Dzen Chat Integration API v1.
