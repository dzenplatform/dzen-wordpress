# WordPress toolbar compatibility

Version: 0.5.1

## Problem and change

On the public WordPress site, the service loader opened a full-screen chat at
the top of the viewport on narrow screens. The WordPress toolbar covered its
header and close button.

The plugin now loads a small CSS adapter when WordPress shows the toolbar.
The dialog reserves the toolbar's height and reduces its own height to keep
the message composer visible. Floating dialogs also reserve this space on
short desktop screens. The service loader and API are unchanged.

## Browser verification

Verified against the real service widget on the authenticated local WordPress
site at `https://local.dzenchat.com:8869/`.

| Viewport | Toolbar | Observed dialog bounds | Result |
| --- | --- | --- | --- |
| 619 × 863 | Visible, bottom at 46 px | Top 46 px, bottom 863 px | Header and composer visible; close button closes the chat. |
| 1024 × 480 | Visible, bottom at 32 px | Top 52 px, bottom 390 px | Floating dialog stays below the toolbar; close button works. |
| 375 × 667 | Hidden in the test user's profile | Top 0 px, bottom 667 px | No toolbar stylesheet loaded; full-screen chat and close button work. |
| 375 × 667 | Enabled, scrolled out of view | Top 46 px, bottom 667 px | Close button remains visible and works. |

At widths up to 600 px, WordPress lets the toolbar scroll out of view. The
adapter keeps its reserved space while the toolbar is enabled. The test user's
original toolbar preference was restored, as was the browser viewport.

## Automated verification

- PHP syntax checks passed.
- 161 WordPress contract checks passed in the separate fixture environment:
  59 general, 40 widget, 36 registration, and 26 internationalization checks.
- All 7 packaging checks passed.
- Russian translation format validation passed; no interface strings changed.

Browser checks used the real local WordPress integration. Fixture checks do
not establish production API availability. This change does not deploy the
plugin to an external WordPress site.
