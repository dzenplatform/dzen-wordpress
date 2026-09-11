# WordPress 0.5.0: English source and Russian translation

September 11, 2026. The plugin and repository documentation now use English
source text. Russian is supplied through WordPress gettext catalogs rather
than hardcoded interface strings. The integration API remains unchanged.

## Implementation

- Text domain: dzen-chat; Domain Path: /languages.
- Register the bundled translation directory on init at priority 0.
- Translate menus, forms, buttons, notices, page settings, API/validation
  errors, scheduler labels and plugin metadata.
- Apply the existing translated processing labels to knowledge files as well
  as indexed pages.
- Include POT, Russian PO and compiled MO in the installable ZIP.
- Keep the normal WordPress user/site locale selection and language-pack
  priority. Unsupported languages fall back to English.
- Translate README, API contract, specifications and historical verification
  reports; mark superseded proposals as historical.

User content and server-rendered interfaces are outside this translation.
Existing widget names, source titles and messages retain their language.
The local indexed test page was not changed when its repository seed was
translated to English.

## Automated verification

WordPress 6.8.2 / PHP 8.3 in the isolated fixture:

- PHP lint and 161 checks passed: 59 general, 40 widget, 36 registration and
  26 internationalization checks.
- All 144 template entries have non-empty, non-fuzzy Russian translations;
  compiled MO entries match PO entries.
- English and Russian renderings cover all five admin sections, page settings,
  validation errors and API errors.
- Russian fixture content remains unchanged and escaped in both languages.
- An unsupported locale falls back to English; switching back from Russian
  restores English. The locale tests supply available language names without
  downloading core language packs in CI; WordPress performs catalog loading
  and locale switching itself.
- Seven packaging checks passed, including rejection of a missing MO catalog.
- GNU gettext syntax/format validation and relative documentation links passed.

~~~sh
COMPOSE_PROJECT_NAME=dzen-wordpress-widget-tests DZEN_WORDPRESS_PORT=8870 make test
make package-test
msgfmt --check --check-format -o /dev/null languages/dzen-chat-ru_RU.po
make package
~~~

The package contains 14 runtime files. Its MO was read directly from the ZIP
and resolved English source strings to Russian. Files excluded from the ZIP
include fixtures, development documentation and local certificates.

## Real WordPress browser verification

Used the existing real connection at https://local.dzenchat.com:8869 with
the local Dzen Chat service. Installed the official Russian WordPress 6.8.2
language pack while preserving credentials and site content.

1. With an English site and Site Default profile, Overview rendered in English.
2. Changed the test administrator's Profile language to Russian through the
   WordPress form. Widgets, Index, Conversations and Sources rendered in Russian,
   including processing states and action labels.
3. The installed-plugins screen showed the Russian Dzen Chat description and
   version 0.5.0.
4. Returning the profile to Site Default restored English.
5. Temporarily setting the site language to Russian also selected the Russian
   plugin interface for the Site Default profile.
6. Restored the original English site and Site Default user preferences.
   Visually inspected Widgets in both languages. The saved Russian widget name
   remained unchanged, while surrounding controls switched correctly.

The browser check used the mounted repository, not a clean ZIP installation.
Package checks establish ZIP contents and catalog compilation separately.
No production deployment, new release tag or changes to the Dzen Chat service
were performed in this iteration. Earlier API limits remain documented in the
[current contract](../contracts/dzen-chat-api.md).
