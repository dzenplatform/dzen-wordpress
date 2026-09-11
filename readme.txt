=== Dzen Chat ===
Contributors: dzenplatform
Requires at least: 6.8
Tested up to: 6.8.2
Requires PHP: 8.2
Stable tag: 0.13.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Dzen Chat, manage widgets, sync public content and view conversations.

== Description ==

Dzen Chat is an external service at https://chat.dzen.dev. Connecting the plugin
opens its consent page to choose or create a project. WordPress exchanges the
one-time code server-to-server and stores encrypted credentials and the site
source identifier. Credentials are never exposed in page HTML or JavaScript.

View all project widgets and create new ones from WordPress. Choose a widget
for the site, select a different widget for a page or post, or hide it on that
page. Open each widget's settings directly in Dzen Chat. Widget settings apply to
the Dzen Chat project; placement
settings apply to this WordPress site.

The selected site-wide widget offers help on 404 pages by default.
Visitors can dismiss the invitation or choose Start a conversation to open chat.
To disable it, clear Show chat on 404 pages in Dzen Chat > Widgets and save the
404 settings. The page keeps its HTTP 404 status and theme layout.

Public pages and posts are submitted for reindexing on connection, publication
and changes. A background queue retries temporary failures. WordPress sends
public URLs; Dzen Chat retrieves and processes the content. Drafts, initially
password-protected posts and products are not submitted as public content.
Previously public URLs are rechecked after unpublishing, deletion or a permalink
change. Rechecking does not guarantee removal from the service index.

View indexing progress for the connected site and additional assigned sources
on the Index screen, with counts covering all pages
and a link to detailed status in Dzen Chat. Refresh the Index screen to get current
counts. Source titles open their settings in Dzen Chat. Upload TXT and Markdown
documents (.txt, .md, .markdown) in UTF-8, up to 256 KiB or the WordPress upload
limit if lower. Uploads go to Dzen Chat without being added to the media library.
Check processing status and errors, read file contents in WordPress, and open
the document editor in Dzen Chat. PDF and Office uploads are not supported by
the current file API. The former Sources screen redirects to Index.
View conversation messages inside WordPress.
View saved suggestions, visitor selections and suggestion failures under assistant
answers. Open the same conversation in Dzen Chat from its heading.
Chat filters cover the latest 100 records; conversation details display up to the
first 100 messages. Conversation hiding, answer-source references, full-history
search, per-page trigger controls and
project billing status are not yet available through this integration.

The plugin stores encrypted integration credentials in WordPress. It does not
store conversation text or source excerpts. The service must support /auth/add
and /auth/exchange/, plus the public /api/ widget, page, source, file, chat and
URL-update methods. API requests use the saved client credentials as a server-side Bearer
token over HTTPS. Only the public widget code is sent to the visitor's browser.

The page/post editor shows indexing status above widget settings. Refresh it
manually or save the page to update it. Saved changes awaiting submission are
shown separately from the server's last processed page. Drafts, private content
and password-protected pages are not submitted as public content.
The same panel displays saved page triggers and explains when source settings or
URL rules prevent their use. Open the indexed page in Dzen Chat for details.

Use "Remove from index and block updates" to exclude a page without removing it
from your website. Pending updates are cancelled and all known public URLs are
excluded through POST /api/pages/exclude. Future saves do not send content updates.
A newly published or changed URL is sent only for exclusion. Drafts that have never
been public are blocked locally. If the service is unavailable, local blocking
remains active and the panel shows that remote removal has not been confirmed.
Refresh the status or retry removal after reconnecting. Widget visibility is independent.

The interface is English by default and includes a Russian (ru_RU) translation.
It follows the administrator's Users > Profile > Language preference. Site
Default uses Settings > General > Site Language. Install the Russian WordPress
language pack if it is not listed. Other languages fall back to English.
Widget names, site content and conversations retain their original language;
the Dzen Chat service controls the language of the embedded widget.

Dates and times use the WordPress date format, time format and timezone from
Settings > General. Month names follow the current interface language. Conversation
date filters use the same local calendar days as the displayed timestamps.

== Installation ==

1. Upload the ZIP through Plugins > Add New > Upload Plugin and activate it.
2. Open Dzen Chat and connect your site. HTTPS and PHP sodium are required.
3. Choose or create a project at chat.dzen.dev and return to WordPress.
4. WordPress confirms authorization after exchanging the returned code.
5. Open Dzen Chat > Widgets, select an enabled widget and click Save selection.
6. Open a public page to check the widget. Page/post overrides are in the editor.
7. Check Index and Conversations. Use Index > Add a document to upload text files.
   Scheduled synchronization needs
   working WP-Cron or a system scheduler running WordPress due events.

== Frequently Asked Questions ==

= Why did enabling a widget not place it on my site? =
Enabling controls the widget for the whole Dzen Chat project. Select it in
WordPress and click Save selection. Page-level exclusions can hide the widget.
Changes made directly in Dzen Chat are refreshed by WordPress within five minutes.

= Can I hide or delete conversations? =
This release provides read-only conversation history. Hiding awaits the service
API. Conversation deletion is not supported; billing history stays on the server.

= Does accepting an update mean the page is indexed? =
No. Acceptance confirms that Dzen Chat queued the public URL. Check its processing
status in Index. Removing or protecting a published WordPress page triggers a
recheck, but immediate removal from retrieval is not guaranteed by this API.

= What happens when I deactivate or uninstall? =
Deactivation stops widget insertion and scheduled jobs. Uninstall removes local
plugin settings, credentials and queue only. Revoke the integration through the
service. Removing keys in WordPress does not revoke the remote API client.

= Are products synchronized? =
No. Products require a separate integration.

== Changelog ==

= 0.13.1 =
This release includes all plugin improvements since the published 0.2.1 release.

* Connect widget, source, document and conversation screens to the implemented
  Dzen Chat public API.
* List and create project widgets in WordPress, select the site-wide widget,
  override or hide it per page/post, and open widget settings in Dzen Chat.
* Synchronize existing and newly published public pages and posts through a
  persistent background queue with retries for temporary failures.
* Combine the Index and Sources screens. Show indexing progress for the connected
  site, additional assigned sources and uploaded documents, with direct links to
  source settings in Dzen Chat.
* Show indexing status and saved triggers in the page/post editor. Remove a page
  from the index and block future updates, including after publishing a draft or
  changing its permalink.
* Upload UTF-8 TXT and Markdown documents (.txt, .md, .markdown), up to 256 KiB or
  the WordPress upload limit if lower. Show processing status and errors, read
  documents in WordPress, and open their editor in Dzen Chat.
* Display conversation history, saved follow-up suggestions and visitor
  selections, with filters and a direct link to each conversation in Dzen Chat.
* Keep the chat window below the WordPress toolbar. Add an opt-in, dismissible
  invitation to start a conversation on missing pages while preserving HTTP 404.
* Use English interface strings and documentation, with standard WordPress
  internationalization and a bundled Russian translation.
* Format service dates using WordPress date/time settings, locale and site
  timezone. Apply conversation filters to the displayed local calendar day.
  Show readable reindexing and document update dates, and a dash for missing or
  invalid timestamps. Preserve stored timestamps and user-written content.

Conversation history remains read-only: filters cover the latest 100 conversations
and details show up to the first 100 messages. Hiding conversations, answer-source
references, full-history search, page trigger controls and billing status are not
available through this integration. Products require a separate integration;
PDF and Office uploads are not supported by the current file API.

= 0.13.0 =
Merge Sources into Index. Show progress for the connected site, additional
assigned sources and uploaded documents, with links to their Dzen Chat settings.
Upload UTF-8 TXT and Markdown files through the existing Files API. Validate
format, size and encoding, keep files out of the public media library, and show
processing errors separately from upload acceptance. Include English and Russian UI.

= 0.12.0 =
Add an opt-in setting to show the selected widget on WordPress 404 pages with a
dismissible invitation to start a conversation. Keep the HTTP 404 status and
ordinary page behavior unchanged. Include English and Russian translations.

= 0.11.0 =
Add an editor button to remove a page from the Dzen Chat index and persistently
block content updates. Cancel earlier queued revisions, exclude known URLs through
the API and display pending, failed and confirmed removal states. Keep exclusions
when publishing drafts or changing permalinks. Include English and Russian UI.

= 0.10.0 =
Show saved page triggers in the page/post editor. Explain disabled sources,
unmatched rules and triggers that have not been generated. Add a link to page
details in Dzen Chat and refresh triggers with indexing status. Keep confirmed
indexing status visible if trigger loading fails. Include English and Russian
interface strings.

= 0.9.0 =
Display saved follow-up suggestions and visitor selections in conversation history.
Distinguish generation failures from answers without saved suggestions. Add a direct
link to the same conversation in Dzen Chat. Validate service data and browser links,
keep history read-only, and include English and Russian interface strings.

= 0.8.0 =
Show per-page indexing status in the editor with manual refresh and automatic
refresh after saving. Look up the exact public URL within the connected source.
Distinguish local submission from remote indexing, and explain draft/protected
content. Add English and Russian messages and protect status requests with
per-page nonces and administrator/edit permissions.

= 0.7.0 =
Replace the Index document table with a progress bar for the connected site source.
Show counts for all pages by status, including excluded pages separately. Add a
refresh action and a direct link to source details. Use the new source status API
and direct widget editor URLs. Include English and Russian interface strings.

= 0.6.0 =
List project widgets in a compact table with an explicit site-wide selection.
Add widgets without leaving WordPress. Open widget settings in Dzen Chat using
editor URLs supplied by the service; older responses open the service dashboard.
Keep page and post overrides and include English and Russian interface strings.

= 0.5.1 =
Keep the chat window below the WordPress toolbar so its close button stays
accessible. Reduce the full-screen chat height to keep the composer in view,
and reserve toolbar space for floating windows on short desktop screens.

= 0.5.0 =
Use English source strings throughout the WordPress interface. Add standard
WordPress internationalization and a bundled Russian translation, selected by
the administrator's language preference. Include translation catalogs in release
ZIPs. Translate repository documentation to English and document the translation
workflow. Preserve user content and the existing integration behavior.

= 0.4.0 =
Connect document, source, knowledge-file and conversation screens to the real
Bearer API. Submit public page and post URLs through /api/update using a durable
queue, initial synchronization and bounded retries. Show processing separately
from request acceptance. Remove the proposed versioned transport and simulated
history mutations. Keep missing service capabilities explicit.

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
