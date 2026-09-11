# Widget editor links

## Required service addition

The WordPress widget list needs a direct editor link for each widget. Existing
`/auth/exchange/` and `/api/widgets` responses contain neither the project's
opaque ID nor the editor URL. The plugin must not infer a project from a widget
code or ask site owners to enter a project ID manually.

Add `edit_url` to the widget objects in `GET /api/widgets`. It identifies the
existing authenticated editor:

~~~json
{
  "id": "widget-example",
  "name": "Website assistant",
  "code": "public-widget-code",
  "is_enabled": true,
  "suggestions_enabled": true,
  "trigger_templates": [],
  "appearance": {},
  "edit_url": "https://chat.dzen.dev/projects/project-example/widgets/widget-example"
}
~~~

Build the address from the configured public service origin and the widget's
actual project and widget short IDs. Reuse current API authorization and
project membership checks. Do not include API tokens, authorization codes,
secrets or signed query parameters. The editor still requires a Dzen Chat user
session; the link does not grant access.

The same field can be returned by widget detail, creation and update responses.
No new management route or change to registration is necessary.

## Plugin behavior

The plugin accepts editor URLs only on its configured service origin, with the
exact `/projects/{opaque-id}/widgets/{widget-id}` path for that widget and no
query or fragment. These are escaped browser links opened in a new tab with
`noopener noreferrer`; the plugin never requests these URLs with API credentials.

Responses without a valid editor URL still allow listing, creation and site
selection. The corresponding row links to the service dashboard. The local
fixture includes editor URLs to test the new field, but this does not establish
that the running Dzen Chat service implements it.

## Service acceptance

- The widget list supplies the correct editor URL for every widget in the
  API client's project.
- A client cannot discover URLs for another project's widgets.
- URLs contain no credentials and follow the configured service origin.
- Opening a link from WordPress reaches that widget's editor after normal
  Dzen Chat login, when necessary.
