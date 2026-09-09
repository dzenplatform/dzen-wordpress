=== Dzen Chat ===
Contributors: dzenplatform
Requires at least: 6.8
Tested up to: 6.8.2
Requires PHP: 8.2
Stable tag: 0.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to Dzen Chat. Manage widgets, page triggers, document sync and conversation history.

== Description ==

Dzen Chat is an external service at https://chat.dzen.dev. Connecting the plugin
authorizes your WordPress site to use a selected project. Published pages and
posts send their public URL and lifecycle events for indexing. Products, private
content and drafts are excluded. Visitors interact with the Dzen Chat widget.

Chat history and billing records stay on the Dzen Chat server. Administrators can
search conversations, view cited sources inside WordPress or at their original
URL, and hide or restore conversations. Hiding never deletes messages.

The plugin stores encrypted integration credentials in WordPress. It does not
store conversation text or source excerpts. A supported Dzen Chat Integration API
v1 is required; this release implements the contract in docs/contracts. Server
availability must be verified separately before production use.

== Installation ==

1. Upload the ZIP through Plugins > Add New > Upload Plugin and activate it.
2. Open Dzen Chat and connect your site. HTTPS and PHP sodium are required.
3. Choose or create a project at chat.dzen.dev and return to WordPress.
4. Select the widget to display. Remove any manually installed duplicate script.
5. Check the content queue and project status. On low-traffic websites, configure
   the hosting scheduler to invoke WordPress cron.

== Frequently Asked Questions ==

= Does hiding remove billing data? =
No. It changes the project-wide visibility of a chat. Restore it from Hidden.

= What happens when I deactivate or uninstall? =
Deactivation stops widget insertion and scheduled jobs. Uninstall removes local
plugin settings, credentials and queue only. Revoke the integration through the
plugin before uninstalling, or revoke its API client in Dzen Chat afterward.

= Are products synchronized? =
No. Products require a separate integration.

== Changelog ==

= 0.1.1 =
Add the required widget mount container and refresh expired project status on
public requests. Clarify widget placement and expose unavailable project status.

= 0.1.0 =
WordPress client for the proposed Dzen Chat Integration API v1.
