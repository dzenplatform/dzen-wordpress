<?php
/** Isolated contract fixture. Never included in the distributable ZIP. */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local') {
    return;
}

function dzen_fixture_widgets(): array
{
    return get_option('dzen_fixture_widgets', ['widget-one' => ['id' => 'widget-one',
        'code' => 'fixture-widget', 'name' => 'Помощник сайта',
        'is_enabled' => true, 'suggestions_enabled' => true,
        'trigger_templates' => [], 'appearance' => (object) []]]);
}

// Only this local MU plugin substitutes the public embed transport.
add_filter('script_loader_src', static function ($src, $handle) {
    if ($handle !== 'dzen-chat-widget') {
        return $src;
    }
    foreach (dzen_fixture_widgets() as $widget) {
        if ($src === 'https://chat.dzen.dev/widget/' . rawurlencode($widget['code'])) {
            return add_query_arg('dzen_fixture_widget', $widget['code'], home_url('/'));
        }
    }
    return $src;
}, 10, 2);

add_action('init', static function () {
    if (!isset($_GET['dzen_fixture_widget']) || !is_string($_GET['dzen_fixture_widget'])) {
        return;
    }
    nocache_headers();
    header('Content-Type: application/javascript; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    $code = wp_unslash($_GET['dzen_fixture_widget']);
    foreach (dzen_fixture_widgets() as $widget) {
        if ($widget['code'] === $code && $widget['is_enabled'] && get_option('dzen_fixture_scenario', 'normal') === 'normal') {
            $config = wp_json_encode(['name' => $widget['name'], 'welcome' => ['Чем помочь?']],
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            echo str_replace('/* DZEN_FIXTURE_CONFIG */', $config,
                file_get_contents(WP_PLUGIN_DIR . '/dzen-chat/tests/widget-preview.js'));
            exit;
        }
    }
    echo '/* Local fixture widget is unavailable. */';
    exit;
});

add_action('admin_notices', static function () {
    if (isset($_GET['page']) && is_string($_GET['page']) && str_starts_with($_GET['page'], 'dzen-chat')) {
        echo '<div class="notice notice-warning"><p>Локальный тестовый стенд: API и виджет демонстрационные. Реальный Dzen Chat не подключён; запросы не отправляются в сервис.</p></div>';
    }
});

add_filter('pre_http_request', static function ($pre, $args, $url) {
    if (!str_starts_with($url, 'https://chat.dzen.dev/api/widgets')) return $pre;
    $reply = static fn ($body, $status = 200, $headers = []) => ['headers' => $headers,
        'body' => wp_json_encode($body), 'response' => ['code' => $status, 'message' => 'Fixture'],
        'cookies' => [], 'filename' => null];
    if (($args['headers']['Authorization'] ?? '') !== 'Bearer chatid-fixture-12345.fixture-secret-for-tests-only-123456789') {
        return $reply(['error' => 'invalid bearer token'], 401);
    }
    $scenario = get_option('dzen_fixture_scenario', 'normal');
    if ($scenario === 'network') return new WP_Error('fixture_network', 'synthetic network failure');
    if ($scenario === 'revoked') return $reply(['error' => 'invalid bearer token'], 401);
    if (in_array($scenario, ['forbidden', 'blocked'], true)) return $reply(['error' => 'forbidden'], 403);
    if ($scenario === 'rate_limit') return $reply(['error' => 'rate limited'], 429, ['retry-after' => '120']);
    $path = wp_parse_url($url, PHP_URL_PATH);
    $method = $args['method'];
    $body = json_decode($args['body'] ?: '{}', true);
    $widgets = dzen_fixture_widgets();
    if ($path === '/api/widgets' && $method === 'GET') {
        if ($scenario === 'malformed') return $reply(['items' => [['id' => 'invalid']]]);
        return $reply(['items' => array_values($widgets)]);
    }
    if ($path === '/api/widgets' && $method === 'POST') {
        if (array_keys($body) !== ['name']) throw new RuntimeException('Create accepts name only');
        $id = 'widget-' . wp_generate_uuid4();
        $widgets[$id] = ['id' => $id, 'code' => 'fixture-' . wp_generate_uuid4(), 'name' => $body['name'],
            'is_enabled' => true, 'suggestions_enabled' => true, 'trigger_templates' => [], 'appearance' => (object) []];
        update_option('dzen_fixture_widgets', $widgets, false);
        return $reply($widgets[$id], 201);
    }
    $id = rawurldecode(substr($path, strlen('/api/widgets/')));
    if (!isset($widgets[$id])) return $reply(['error' => 'widget not found'], 404);
    if ($method === 'PATCH') {
        if (array_diff(array_keys($body), ['name', 'is_enabled', 'suggestions_enabled'])) {
            throw new RuntimeException('Unexpected widget field sent to the implemented API');
        }
        if ($scenario !== 'unconfirmed') {
            $widgets[$id] = array_replace($widgets[$id], $body);
            update_option('dzen_fixture_widgets', $widgets, false);
        }
    }
    $widget = $widgets[$id];
    if ($scenario === 'wrong_id') $widget['id'] = 'foreign-widget';
    if ($scenario === 'malformed') $widget['is_enabled'] = 'false';
    return $reply($widget);
}, 10, 3);

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
