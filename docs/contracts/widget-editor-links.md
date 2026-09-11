# Widget editor links

## Implemented service contract

The WordPress widget list uses a direct editor link for each widget.
Registration remains unchanged; the plugin does not infer a project from a
widget code or ask site owners to enter a project ID manually.

`edit_url` is returned on widget objects in `GET /api/widgets`. It identifies the
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

The same field is returned by widget detail, creation and update responses.
No new management route or change to registration is necessary.

## Plugin behavior

The plugin accepts editor URLs only on its configured service origin, with the
exact `/projects/{opaque-id}/widgets/{widget-id}` path for that widget and no
query or fragment. These are escaped browser links opened in a new tab with
`noopener noreferrer`; the plugin never requests these URLs with API credentials.

Responses without a valid editor URL still allow listing, creation and site
selection. The corresponding row links to the service dashboard. The local
service and fixture both supply editor URLs. See the
[0.7.0 verification report](../verification/2026-09-11-source-index-status.md) for
the local checks and production boundary.

## Service acceptance

- The widget list supplies the correct editor URL for every widget in the
  API client's project.
- A client cannot discover URLs for another project's widgets.
- URLs contain no credentials and follow the configured service origin.
- Opening a link from WordPress reaches that widget's editor after normal
  Dzen Chat login, when necessary.
