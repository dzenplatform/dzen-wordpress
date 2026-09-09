<?php
/** Isolated contract fixture. Never included in the distributable ZIP. */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local') {
    return;
}

add_filter('pre_http_request', static function ($pre, $args, $url) {
    if (!str_starts_with($url, 'https://chat.dzen.dev/api/v1/')) {
        return $pre;
    }
    $reply = static fn ($body, $status = 200, $headers = []) => ['headers' => $headers,
        'body' => wp_json_encode($body), 'response' => ['code' => $status, 'message' => 'Fixture'],
        'cookies' => [], 'filename' => null];
    $path = wp_parse_url($url, PHP_URL_PATH);
    $queryString = wp_parse_url($url, PHP_URL_QUERY) ?: '';
    parse_str($queryString, $query);
    $body = json_decode($args['body'] ?: '{}', true);
    $method = $args['method'];
    $headers = $args['headers'];
    if ($path === '/api/v1/integrations/exchange') {
        if (($body['code'] ?? '') !== 'fixture-one-time-code-123456789') {
            return $reply(['error' => ['code' => 'invalid_grant']], 400);
        }
        return $reply(['client_id' => 'chatid-fixture', 'client_secret' => 'fixture-secret-for-tests-only-123456789',
            'integration_id' => 'integration-fixture', 'site_url' => trailingslashit(home_url()),
            'project' => ['id' => 'project-fixture', 'title' => 'WordPress fixture']]);
    }
    $canonical = implode("\n", ['DZEN-HMAC-V1', $method, $path . ($queryString ? '?' . $queryString : ''),
        $headers['X-Dzen-Client-Id'] ?? '', $headers['X-Dzen-Timestamp'] ?? '', $headers['X-Dzen-Nonce'] ?? '',
        $headers['Idempotency-Key'] ?? '', hash('sha256', $args['body'])]);
    if (!hash_equals(hash_hmac('sha256', $canonical, 'fixture-secret-for-tests-only-123456789'), $headers['X-Dzen-Signature'] ?? '')) {
        return $reply(['error' => ['code' => 'bad_signature']], 401);
    }
    if ($method === 'DELETE' && str_contains($path, '/chats')) {
        throw new RuntimeException('Chat DELETE is forbidden');
    }
    $scenario = get_option('dzen_fixture_scenario', 'normal');
    if ($scenario === 'network') {
        return new WP_Error('fixture_network', 'synthetic network failure');
    }
    if ($scenario === 'forbidden' && $path !== '/api/v1/integration') {
        return $reply(['error' => ['code' => 'forbidden']], 403);
    }
    if ($scenario === 'rate_limit' && $path !== '/api/v1/integration') {
        return $reply(['error' => ['code' => 'rate_limited']], 429, ['retry-after' => '120']);
    }
    if ($path === '/api/v1/integration') {
        return $reply(['integration' => ['id' => 'integration-fixture', 'status' => 'active'],
            'project' => ['id' => 'project-fixture', 'title' => 'Тестовый проект', 'status' => $scenario === 'blocked' ? 'restricted' : 'active'],
            'site_url' => trailingslashit(home_url()), 'scopes' => $scenario === 'read_only' ? ['chats:read'] : ['chats:read', 'chats:visibility:update'],
            'allowed_operations' => ['widget_answers' => $scenario !== 'blocked', 'documents_upsert' => $scenario !== 'blocked', 'documents_delete' => true]]);
    }
    if ($path === '/api/v1/widgets') {
        return $reply($method === 'GET' ? ['items' => [['id' => 'widget-one', 'code' => 'fixture-widget', 'name' => 'Помощник сайта', 'version' => 1, 'is_enabled' => true, 'welcome_messages' => ['Чем помочь?']]], 'next_cursor' => null] : ['id' => 'widget-new']);
    }
    if (str_starts_with($path, '/api/v1/widgets/')) {
        return $reply(['id' => 'widget-one'] + $body);
    }
    if ($path === '/api/v1/sources') {
        return $reply(['items' => [['id' => 'source-one', 'title' => 'Сайт WordPress', 'status' => 'ready', 'document_count' => 12, 'last_indexed_at' => '2026-09-09T12:00:00Z'],
            ['id' => 'source-two', 'title' => 'База знаний', 'status' => 'paused', 'document_count' => 5, 'error_message' => 'Обновление приостановлено']], 'next_cursor' => null]);
    }
    if ($path === '/api/v1/documents') {
        return $reply(['items' => [['id' => 'document-one', 'external_id' => 'wp:post:2', 'title' => 'Условия доставки', 'url' => 'https://example.org/shipping', 'status' => 'ready', 'triggers_enabled' => true]], 'next_cursor' => null]);
    }
    if (str_starts_with($path, '/api/v1/page-policies/')) {
        return $reply(['mode' => $body['mode']]);
    }
    if ($path === '/api/v1/document-events') {
        // These are API fixture records, not plugin storage. No chat data is persisted.
        $events = get_option('dzen_fixture_document_events', []);
        $events[$body['event_id']] = $body;
        update_option('dzen_fixture_document_events', $events, false);
        return $reply(['operation_id' => 'op-' . $body['event_id']], 202);
    }
    if (str_starts_with($path, '/api/v1/operations/')) {
        return $reply(['status' => 'succeeded']);
    }
    $state = get_option('dzen_fixture_visibility', ['hidden' => false, 'version' => 1]);
    $chat = ['id' => 'chat-one', 'title' => 'Вопрос о доставке', 'preview' => 'Можно ли изменить адрес?',
        'hidden' => $state['hidden'], 'visibility_version' => $state['version'], 'message_count' => 2,
        'created_at' => '2026-09-09T10:00:00Z', 'widget_id' => 'widget-one'];
    if ($path === '/api/v1/chats') {
        $visible = $query['visibility'] ?? 'visible';
        $matches = ($visible === 'all' || ($visible === 'hidden') === $state['hidden'])
            && (empty($query['q']) || str_contains($chat['title'] . $chat['preview'], $query['q']))
            && (empty($query['widget_id']) || $query['widget_id'] === 'widget-one')
            && (empty($query['date_from']) || $query['date_from'] <= '2026-09-09')
            && (empty($query['date_to']) || $query['date_to'] >= '2026-09-09');
        return $reply(['items' => $matches ? [$chat] : [], 'next_cursor' => null]);
    }
    if ($path === '/api/v1/chats/chat-one') {
        return $reply($chat);
    }
    if ($path === '/api/v1/chats/chat-one/visibility' && $method === 'PATCH') {
        if ($scenario === 'conflict' || $body['expected_version'] !== $state['version']) {
            return $reply(['error' => ['code' => 'visibility_conflict']], 409);
        }
        $state = ['hidden' => $body['hidden'], 'version' => $state['version'] + 1];
        update_option('dzen_fixture_visibility', $state, false);
        return $reply(['id' => 'chat-one', 'hidden' => $state['hidden'], 'hidden_at' => $state['hidden'] ? '2026-09-09T12:00:00Z' : null, 'visibility_version' => $state['version']]);
    }
    $sources = [['id' => 'source-ref', 'title' => 'Правила доставки', 'url' => 'https://example.org/shipping', 'available' => true],
        ['id' => 'missing-ref', 'title' => 'Старый документ', 'url' => null, 'available' => false],
        ['id' => 'unsafe-ref', 'title' => '<script>window.sourceXss=1</script>', 'url' => 'javascript:alert(1)', 'available' => true]];
    if ($path === '/api/v1/chats/chat-one/messages') {
        return $reply(['items' => [['id' => 'msg-user', 'role' => 'user', 'text' => 'Можно ли изменить адрес?', 'created_at' => '2026-09-09T10:00:00Z', 'sources' => []],
            ['id' => 'msg-answer', 'role' => 'assistant', 'text' => 'Адрес можно изменить до передачи заказа перевозчику.', 'created_at' => '2026-09-09T10:00:01Z', 'vote' => true, 'sources' => $sources]], 'next_cursor' => null]);
    }
    if ($path === '/api/v1/chats/chat-one/messages/msg-answer/sources/source-ref') {
        return $reply(['id' => 'source-ref', 'title' => 'Правила доставки', 'url' => 'https://example.org/shipping', 'available' => true,
            'content' => "Изменить адрес можно до отправки заказа.\n<script>window.sourceXss=1</script>", 'content_kind' => 'excerpt', 'captured_at' => '2026-09-09T09:00:00Z']);
    }
    if ($path === '/api/v1/chats/chat-one/messages/msg-answer/sources/missing-ref') {
        return $reply(['id' => 'missing-ref', 'title' => 'Старый документ', 'available' => false, 'url' => null]);
    }
    return $reply(['error' => ['code' => 'not_found']], 404);
}, 10, 3);
