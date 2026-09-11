<?php
/** Conversation display contract in the disposable fixture WordPress only. */
require __DIR__ . '/setup.php';
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks) {
    if (!$ok) throw new RuntimeException('FAILED: ' . $label);
    ++$checks;
    echo 'PASS ' . $label . "\n";
};
$credentials = new DzenChat\Credentials();
$api = new DzenChat\Api($credentials);
$admin = new DzenChat\Admin($credentials, $api, new DzenChat\Sync($credentials, $api));
wp_set_current_user(get_user_by('login', 'dzen_test')->ID);
$render = static function () use ($admin) {
    $_GET = ['page' => 'dzen-chat-history', 'chat' => 'chat-one'];
    ob_start();
    $admin->page();
    return ob_get_clean();
};
$renderList = static function (array $query = []) use ($admin) {
    $_GET = ['page' => 'dzen-chat-history'] + $query;
    ob_start();
    try { $admin->page(); return ob_get_contents(); }
    finally { ob_end_clean(); }
};
$chat = $api->conversation('chat-one');
$messages = $api->conversationMessages('chat-one');
$overrideChat = $overrideMessages = $overrideList = null;
$requests = [];
$modify = static function ($pre, $args, $url) use (&$overrideChat, &$overrideMessages, &$overrideList, &$requests) {
    $requests[] = ['url' => $url, 'method' => $args['method']];
    if ($overrideList !== null && wp_parse_url($url, PHP_URL_PATH) === '/api/chats') $pre['body'] = wp_json_encode(['items' => $overrideList]);
    if ($overrideChat !== null && str_ends_with($url, '/chats/chat-one')) $pre['body'] = wp_json_encode($overrideChat);
    if ($overrideMessages !== null && str_contains($url, '/chats/chat-one/messages')) $pre['body'] = wp_json_encode(['items' => $overrideMessages]);
    return $pre;
};
add_filter('pre_http_request', $modify, 11, 3);
try {
    $empty = array_replace($chat, ['id' => 'empty-chat']);
    $overrideList = [$chat, $empty];
    $requests = [];
    $html = $renderList();
    $check(str_contains($html, 'chat=empty-chat') && str_contains($html, 'chat=chat-one')
        && !str_contains($requests[0]['url'], 'hide_empty'), 'empty conversations remain visible until the filter is selected');
    $overrideList = [$chat];
    $requests = [];
    $html = $renderList(['hide_empty' => '1', 'q' => $chat['visitor'], 'widget_code' => $chat['widget_code'],
        'date_from' => '2026-09-11', 'date_to' => '2026-09-11']);
    $check($requests[0]['url'] === 'https://chat.dzen.dev/api/chats?hide_empty=1&limit=100'
        && str_contains($html, 'chat=chat-one') && !str_contains($html, 'chat=empty-chat'),
        'server-side empty filter combines with ID, date and widget filters');
    $check(str_contains($html, 'name="hide_empty" value="1" checked=') && str_contains($html, 'Hide empty conversations'),
        'empty filter stays checked after Apply');
    $check(count($requests) === 2 && !array_filter($requests, static fn ($request) => str_contains($request['url'], '/messages')),
        'filtering does not fetch every conversation history');
    $overrideList = [$chat, $empty];
    $requests = [];
    $check(str_contains($renderList(['hide_empty' => '0']), 'chat=empty-chat')
        && !str_contains($requests[0]['url'], 'hide_empty'), 'turning the filter off restores empty conversations');
    $overrideList = [];
    $check(str_contains($renderList(['hide_empty' => '1']), 'No results in the loaded set.'),
        'a list with no non-empty conversations shows the empty result state');
    $overrideList = null;
    $html = $render();
    $check(str_contains($html, 'class="dzen-conversation-heading"')
        && str_contains($html, 'href="https://chat.dzen.dev/projects/project-one/history/chat-one"')
        && str_contains($html, 'target="_blank" rel="noopener noreferrer"'), 'same conversation opens in the service web admin');
    $check(substr_count($html, 'class="dzen-suggestions"') === 1 && str_contains($html, 'Как изменить адрес?')
        && str_contains($html, 'Как проверить заказ?') && str_contains($html, 'Можно отменить заказ?'),
        'only assistant answers show their saved suggestions');
    $check(str_contains($html, 'class="dzen-suggestion-selected"') && substr_count($html, 'Selected by visitor') === 1,
        'saved visitor selection is marked');
    $check(!str_contains($html, '<form') && !str_contains($html, '<button') && !str_contains($html, '<script>'),
        'history suggestions do not send or modify messages');
    $check(!str_contains($html, 'fixture-secret') && !str_contains($html, 'Bearer '), 'history never exposes credentials');
    foreach (['none', 'failed'] as $status) {
        $overrideMessages = $messages;
        $overrideMessages[1] = array_replace($messages[1], ['suggested_actions' => [], 'selected_suggested_action' => null, 'suggestions_status' => $status]);
        $html = $render();
        $expected = $status === 'failed' ? 'Suggestions could not be generated for this answer.' : 'No suggestions were saved for this answer.';
        $check(str_contains($html, $expected) && !str_contains($html, 'dzen-suggestion-list'), 'saved suggestion state is explicit: ' . $status);
    }
    $overrideMessages = $messages;
    $overrideMessages[1]['suggested_actions'][0] = '<img src=x onerror="alert(1)">';
    $overrideMessages[1]['selected_suggested_action'] = $overrideMessages[1]['suggested_actions'][0];
    $html = $render();
    $check(str_contains($html, '&lt;img') && !str_contains($html, '<img') && !str_contains($html, '<script>'),
        'suggestions and selected text are escaped');
    foreach ([
        ['suggestions_status' => 'pending'], ['suggested_actions' => 'bad'], ['suggested_actions' => [null]],
        ['suggested_actions' => ['']], ['suggested_actions' => []], ['suggestions_status' => 'none'],
        ['selected_suggested_action' => 'not a saved suggestion'], ['selected_suggested_action' => ['text' => 'invalid']],
    ] as $changes) {
        $overrideMessages = $messages;
        $overrideMessages[1] = array_replace($messages[1], $changes);
        $check(is_wp_error($api->conversationMessages('chat-one')), 'invalid suggestion DTO rejected: ' . wp_json_encode($changes));
    }
    $overrideMessages = $messages;
    unset($overrideMessages[1]['suggestions_status']);
    $html = $render();
    $check(str_contains($html, 'invalid conversation messages') && !str_contains($html, '<article')
        && str_contains($html, 'Open conversation in Dzen Chat'), 'unconfirmed messages show an error while retaining the admin link');
    $overrideMessages = null;
    foreach ([
        'javascript:alert(1)', 'https://evil.test/projects/project-one/history/chat-one',
        'https://chat.dzen.dev.evil.test/projects/project-one/history/chat-one',
        'https://user:secret@chat.dzen.dev/projects/project-one/history/chat-one',
        'https://chat.dzen.dev/projects/project-one/history/other-chat',
        'https://chat.dzen.dev/projects/project-one/history/chat-one?token=secret',
        'https://chat.dzen.dev/projects/project-one/history/chat-one#fragment',
    ] as $url) {
        $overrideChat = array_replace($chat, ['details_url' => $url]);
        $check(is_wp_error($api->conversation('chat-one')) && !str_contains($render(), 'dzen-conversation-heading'),
            'unsafe or mismatched conversation link rejected');
    }
    $overrideChat = array_replace($chat, ['id' => 'other-chat']);
    $check(is_wp_error($api->conversation('chat-one')), 'foreign conversation DTO rejected');
    $overrideChat = null;
    foreach (['network', 'revoked', 'forbidden'] as $scenario) {
        update_option('dzen_fixture_scenario', $scenario, false);
        $check(!str_contains($render(), '<article'), 'unavailable history is not invented: ' . $scenario);
    }
    $check(!array_filter($requests, static fn ($request) => $request['method'] !== 'GET'), 'all history requests are read-only');
} finally {
    remove_filter('pre_http_request', $modify, 11);
    update_option('dzen_fixture_scenario', 'normal', false);
}
echo "History checks passed: $checks\n";
