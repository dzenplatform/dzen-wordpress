# Task: Full WordPress integration with the Dzen Chat public API

## Goal

Connect the agreed WordPress sections to the real service and verify the widget,
conversations and public page updates on a local site.

## Context

- `src/Api.php`, `Admin.php`, `Sync.php`, `Widgets.php`, `Connection.php`.
- Running `https://local.dzenchat.com/api/openapi.json` and `chat/api/`
  implementation in Dzen Chat task `01a086d7-18f2-73e1-99b0-b59d123f2da5`.
- `compose.registration.yaml`: separate WordPress with real credentials.

## Current behavior

At the start, version 0.3.0 used real registration and widget APIs. Other screens
and the queue depended on the old proposed contract and were disabled.
Version 0.4 connects implemented methods; results and limits are in the
[verification report](../verification/2026-09-11-full-integration.md).

## Target shape

One server-side Bearer client for `/api/`, current DTOs, and index, source and
conversation screens. Submit public page/post changes through the existing
reindexing mechanism. Load the widget from Dzen Chat.

## Guard rails

- Keep credentials encrypted in WordPress, outside HTML and Git.
- Chats stay on the server; never delete messages or billing history.
- Do not submit products, drafts or protected content as public pages.
- Do not substitute proposed /api/v1, scopes or operation IDs for the real API.
- Isolate fixture responses from the site with the real connection.
- Leave production and unrelated Dzen Chat changes untouched.

## Iterations

1. Complete: inspect the running contract, WordPress and real widget.
2. Complete: index, source, file and conversation reads through one Bearer API.
3. Complete within /api/update: public URL queue, initial reconciliation, retries,
   permalink changes and notification of withdrawn publication. Guaranteed
   exclusion from retrieval remains a server dependency.
4. Awaiting server methods: hiding, answer sources, full pagination/search,
   trigger policies and project status. Missing operations are not simulated.
5. Local scenarios and packaging checked; commit and CI recorded at delivery.

## Verification

- Create and select a widget from WordPress; a public page loads the actual
  loader and iframe, and its submitted message appears in history.
- Index and Sources show server state; errors do not look like success.
- Test publication, editing and deletion against index behavior. HTTP 202 means
  request acceptance, not a completed index.
- Check permissions, nonce, site/project isolation, links and absence of secrets.
  Contract tests do not replace live verification.
- Record API gaps explicitly without simulating missing operations.

## Open questions

The inspected API lacks hiding, answer sources, page trigger management, project
status and pagination. Determine whether the API task will add these methods
or server work belongs in this iteration.

The verified /api/update recrawls a URL but does not confirm its exclusion.
Deleting or closing a previously public page requires an explicit server
contract. The entire original integration is not claimed complete before that
contract is implemented.
