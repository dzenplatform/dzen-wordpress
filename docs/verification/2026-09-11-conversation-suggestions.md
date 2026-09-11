# Conversation suggestions and admin navigation: 0.9.0

## Verified behavior

The WordPress conversation heading now includes a right-aligned link to the same
conversation in Dzen Chat. Assistant answers show their saved follow-up suggestions
and mark a saved visitor selection. Generation failures and answers without saved
suggestions have distinct messages. The controls remain read-only.

The public API adds `details_url` to chat list/detail and feedback responses, and
`suggested_actions`, `selected_suggested_action`, `suggestions_status` to message
responses. System messages, internal context and raw provider diagnostics are not
included. See the [API contract](../contracts/dzen-chat-api.md).

## Real local service

Checked the reported WordPress conversation on September 11, 2026:

- WordPress: `https://local.dzenchat.com:8869/wp-admin/admin.php?page=dzen-chat-history&chat=01a08e25-3444-7664-bec1-fa88c10f2d24`.
- Dzen Chat: `https://local.dzenchat.com/projects/NIPkuXrwjx/history/01a08e25-3444-7664-bec1-fa88c10f2d24`.
- Both screens show the same saved delivery question and answer from a real local
  integration test. This is test content, not a production customer conversation.
- That answer has no saved suggestions. Its stored generation outcome is
  `provider_error`: the selected provider had no API key. WordPress now explains
  the failure; the Dzen Chat detail screen shows the diagnostic. Viewing either
  screen does not regenerate or replace the historical answer.
- The generated link contains the actual project and conversation identifiers,
  with no credentials. Its destination was opened in the in-app browser and
  verified against the same message text and generation error.
- Verified English and Russian rendering with the real API. At viewport widths
  of 843 and 390 CSS pixels, the page has no horizontal overflow. The link sits
  on the right and wraps below the identifier when needed. The administrator's
  original default-language preference and viewport overrides were restored.

There were no stored suggestion lists in this local test project. The successful
list/selection display was therefore checked separately in the isolated fixture
WordPress at port 8870; it is not evidence of a successful live model generation.
The fixture visibly identifies itself as a demonstration environment.

## Automated checks

- WordPress PHP syntax checks and 256 contract checks passed, including 29 history
  checks and 32 internationalization checks.
- Seven package checks passed, including reproducibility, matching version
  metadata, runtime-only contents and the bundled Russian catalog.
- Seven focused Dzen Chat public API tests passed against isolated PostgreSQL.
  They cover saved suggestions, selection, absent/failed generation, authorization,
  foreign-chat isolation, system/context exclusion and unchanged stored messages.
- The plugin package is `dist/dzen-chat-0.9.0.zip` with a SHA-256 companion file.

## Boundary

This is local integration verification. Provider credentials were not changed,
no old conversation was modified, and no production deployment or tagged GitHub
release was performed.
