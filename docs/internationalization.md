# Internationalization

Dzen Chat uses WordPress gettext functions with the `dzen-chat` text domain.
All source strings are English. The plugin registers its `languages/` directory
on `init`, before its own initialization jobs, and declares
`Domain Path: /languages` in the plugin header.

WordPress selects the locale. In the admin, a user's **Profile → Language**
preference takes priority; **Site Default** follows **Settings → General →
Site Language**. Public requests and background jobs use the site locale.
The plugin does not inspect the browser language or add a separate language setting.

## Included files

- `languages/dzen-chat.pot`: source template with references.
- `languages/dzen-chat-ru_RU.po`: editable Russian translation.
- `languages/dzen-chat-ru_RU.mo`: compiled Russian runtime catalog.

English needs no separate catalog. Other locales use English until a translation
is installed. WordPress language packs in `wp-content/languages/plugins/` retain
the normal WordPress priority over bundled catalogs. Do not store custom
translations inside the plugin directory if they must survive a plugin update.

The translation covers menus, buttons, forms, notices, validation and API error
messages, page/post widget settings, scheduler labels and plugin metadata.
User content and service responses such as widget names, source titles, chat
messages and diagnostic details retain their original text. The service controls
the language of its own pages and embedded widget.

## Update an existing translation

Docker Compose and its WordPress CLI image provide extraction and compilation;
neither command needs a running WordPress database.

~~~sh
make i18n-pot
~~~

Update `dzen-chat-ru_RU.po` from the new template using a gettext editor such as
Poedit, or GNU gettext:

~~~sh
msgmerge --update --backup=none --no-fuzzy-matching languages/dzen-chat-ru_RU.po languages/dzen-chat.pot
~~~

Translate every new entry, review changed entries, then compile:

~~~sh
make i18n-mo
~~~

With GNU gettext installed, also check catalog syntax and placeholders:

~~~sh
msgfmt --check --check-format -o /dev/null languages/dzen-chat-ru_RU.po
~~~

Commit the POT, PO and MO together. The installation ZIP includes all three and
packaging rejects a missing Russian catalog. Compilation is a development step;
site owners do not need gettext or WP-CLI.

## Add a language

Create `languages/dzen-chat-LOCALE.po` from the POT, using a WordPress locale
such as `de_DE`. Set the language and correct plural rules, translate the entries
and run `make i18n-mo`. Stage both PO and MO so the tracked-file packager includes
them. Check the actual WordPress profile language and the resulting interface.

## Writing translatable code

Use complete English strings with a literal `dzen-chat` text domain. Escape at
the output boundary with `esc_html__()`, `esc_attr__()` or the appropriate
WordPress escaping function. Use numbered placeholders and a `translators:`
comment when a sentence includes values; use WordPress plural functions for
count-dependent wording. Do not concatenate fragments of a sentence or translate
dynamic user content.

Register hooks during plugin loading; resolve translations on `init` or later.
Early translation loading can cause WordPress warnings and bypass the intended
user locale. The public 404 invitation renders translated markup in PHP; its
JavaScript only controls visibility and opens the widget. Any future strings
created directly in JavaScript need WordPress script translation support.

## Verification

Run the isolated WordPress suite:

~~~sh
COMPOSE_PROJECT_NAME=dzen-wordpress-widget-tests DZEN_WORDPRESS_PORT=8870 make test
make package-test
~~~

The locale checks compare PO and MO coverage, render admin screens and the page
meta box and 404 invitation in English and Russian, exercise translated errors, check English
fallback and restore the previous locale. Fixture content includes Russian text
to verify that user data is preserved and escaped.

For browser acceptance, install the Russian WordPress language pack on a local
site and check **Users → Profile → Language** on an English site. Verify English,
Russian and **Site Default** across Dzen Chat sections, then restore the original
preferences. A compiled catalog alone is not evidence of the actual admin locale.

References: [WordPress internationalization handbook](https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/)
and [load_plugin_textdomain](https://developer.wordpress.org/reference/functions/load_plugin_textdomain/).
