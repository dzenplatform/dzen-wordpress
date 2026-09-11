=== Dzen Chat ===
Contributors: dzenplatform
Requires at least: 6.8
Tested up to: 6.8.2
Requires PHP: 8.2
Stable tag: 0.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Dzen Chat and manage AI chat widgets on your WordPress site.

== Description ==

Dzen Chat is an external service at https://chat.dzen.dev. Connecting the plugin
opens its consent page to choose or create a project. WordPress exchanges the
one-time code server-to-server and stores encrypted credentials and the site
source identifier. Credentials are never exposed in page HTML or JavaScript.

Create, rename, enable or disable widgets and configure follow-up suggestions
from WordPress. Choose a widget for the site, select a different widget for a
page or post, or hide it on that page. Widget settings apply to the Dzen Chat
project; placement settings apply to this WordPress site.

Indexing, conversation history and source status screens remain pending in the
plugin. This release does not start content synchronization or validate project
billing status; the current Widgets API does not provide billing state.

The plugin stores encrypted integration credentials in WordPress. It does not
store conversation text or source excerpts. The service must support /auth/add
and /auth/exchange/, plus GET/POST /api/widgets and GET/PATCH /api/widgets/{id}.
Widget API requests use the saved client credentials as a server-side Bearer
token over HTTPS. Only the public widget code is sent to the visitor's browser.

== Installation ==

1. Upload the ZIP through Plugins > Add New > Upload Plugin and activate it.
2. Open Dzen Chat and connect your site. HTTPS and PHP sodium are required.
3. Choose or create a project at chat.dzen.dev and return to WordPress.
4. WordPress confirms authorization after exchanging the returned code.
5. Open Dzen Chat > Widgets, enable a widget and choose Place on this site.
6. Open a public page to check the widget. Page/post overrides are in the editor.

== Frequently Asked Questions ==

= Why did enabling a widget not place it on my site? =
Enabling controls the widget for the whole Dzen Chat project. Choose Place on
this site to select it for WordPress. Page-level exclusions can hide the widget.
Changes made directly in Dzen Chat are refreshed by WordPress within five minutes.

= Does hiding remove billing data? =
No. Planned conversation APIs will change visibility without deleting billing data.

= What happens when I deactivate or uninstall? =
Deactivation stops widget insertion and scheduled jobs. Uninstall removes local
plugin settings, credentials and queue only. Revoke the integration through the
service. Removing keys in WordPress does not revoke the remote API client.

= Are products synchronized? =
No. Products require a separate integration.

== Changelog ==

= 0.3.0 =
Connect widget listing, creation, editing, enablement and follow-up suggestions
to the implemented public Bearer API. Add site and page placement with bounded
remote state refresh. Keep indexing and history deferred without invented
project-status responses or unsupported widget settings.

= 0.2.1 =
Add versioned GitHub release ZIPs with checksums and automated contract checks.
Identify the external update source to avoid collisions with WordPress.org plugins.
This release still provides authorization only; management APIs and automatic
updates inside WordPress are not yet connected.

= 0.2.0 =
Use the implemented /auth/exchange/ endpoint and its actual credentials response.
Keep registration independent of the future management API and indexing queue.

= 0.1.1 =
Add the required widget mount container and refresh expired project status on
public requests. Clarify widget placement and expose unavailable project status.

= 0.1.0 =
WordPress client for the proposed Dzen Chat Integration API v1.
