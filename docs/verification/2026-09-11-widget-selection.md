# Widget selection list

Version: 0.6.0

## Result

The Widgets section lists the connected project's widgets in one table. A radio
choice selects the site-wide widget, and an explicit no-widget option removes
site-wide placement. Saving a choice confirms that the widget still exists and
is enabled. Page and post overrides are preserved.

The list shows the saved selection and each widget's service status. Disabled
widgets cannot be selected. A visible Add widget link leads to the creation
form. Creating a widget does not silently change the site's existing selection.
Appearance, messages and other widget settings are edited in Dzen Chat.

## Real local browser checks

Used the connected site at `https://local.dzenchat.com:8869/` and the real
Dzen Chat project, without the API fixture.

- Created `WordPress widget selection test` from the WordPress form and saw it
  alongside the original widget in both WordPress and Dzen Chat.
- Selected the new widget, saved and reloaded. The radio choice persisted.
  The public page loaded its real widget code and opened the chat with one
  mount and one loader.
- Selected no site-wide widget. The public page contained neither mount nor
  loader after reload.
- Restored the original site's widget and confirmed its original loader URL.
- Disabled the newly created test widget. Its radio control became unavailable;
  the original widget stayed selected. The test widget remains disabled in the
  local project for inspection.
- Checked English and Russian UI. Temporary profile-language changes were
  restored to the original Site Default setting.
- Checked viewport widths of 619 and 375 CSS pixels through browser emulation:
  the table stayed inside the viewport with no horizontal page overflow.
  Temporary viewport and device-metric overrides were reset.

## Automated checks

All 168 WordPress checks passed: 59 general, 47 widget, 36 registration and
26 internationalization checks. Widget checks cover the list selection,
unchanged selection after invalid submissions, validated editor URLs, public
placement, API failures, nonces and permissions. All 7 packaging checks passed.
The updated optional browser-check script passes JavaScript syntax validation;
the real browser acceptance above was performed through the in-app browser.

## Editor-link dependency

The current Dzen Chat API does not return a project ID or editor URL. The
plugin accepts the proposed `edit_url` field and validates its service origin,
path and widget ID. The fixture verifies those links, but the real list currently
opens the Dzen Chat dashboard. No guessed project identifiers are stored.

The exact editor routes were verified in the service UI. Returning those URLs
from the API remains a separate required server addition, described in the
[editor-link contract](../contracts/widget-editor-links.md). No Dzen Chat source
changes or production deployment were made in this iteration.
