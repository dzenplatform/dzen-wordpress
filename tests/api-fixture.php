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
    if ($pre !== false || !str_starts_with($url, 'https://chat.dzen.dev/api/')) return $pre;
    $reply = static fn ($body, $status = 200, $headers = []) => ['headers' => $headers,
        'body' => wp_json_encode($body), 'response' => ['code' => $status, 'message' => 'Fixture'],
        'cookies' => [], 'filename' => null];
    if (($args['headers']['Authorization'] ?? '') !== 'Bearer chatid-fixture-12345.fixture-secret-for-tests-only-123456789') {
        return $reply(['error' => 'invalid bearer token'], 401);
    }
    $scenario = get_option('dzen_fixture_scenario', 'normal');
    if ($scenario === 'network') return new WP_Error('fixture_network', 'synthetic network failure');
    if ($scenario === 'revoked') return $reply(['error' => 'invalid bearer token'], 401);
    if ($scenario === 'forbidden') return $reply(['error' => 'forbidden'], 403);
    if ($scenario === 'rate_limit') return $reply(['error' => 'rate limited'], 429, ['retry-after' => '120']);
    if ($scenario === 'malformed_list') return $reply(['items' => 'invalid']);
    $path = wp_parse_url($url, PHP_URL_PATH);
    $method = $args['method'];
    $body = json_decode($args['body'] ?: '{}', true);
    parse_str(wp_parse_url($url, PHP_URL_QUERY) ?: '', $query);
    $source = ['id' => 'source-one', 'title' => 'Сайт WordPress', 'url' => trailingslashit(home_url()),
        'is_paused' => false, 'enable_triggers' => true, 'blocked_reason' => null, 'last_reindexed_at' => '2026-09-11T01:00:00Z'];
    $page = ['id' => 'page-one', 'title' => 'Доставка', 'url' => home_url('/delivery/'),
        'source_id' => 'source-one', 'status' => 'ready', 'status_error' => null, 'has_triggers' => true, 'updated_at' => '2026-09-11T01:00:00Z'];
    $file = ['id' => 'file-one', 'name' => 'Инструкция', 'content' => 'Тестовый текст файла <script>alert(1)</script>',
        'status' => 'ready', 'status_error' => null, 'size_bytes' => 100, 'updated_at' => '2026-09-11T01:00:00Z'];
    $chat = ['id' => 'chat-one', 'created_at' => '2026-09-11T01:00:00Z', 'updated_at' => '2026-09-11T01:01:00Z',
        'visitor' => 'visitor1234567890', 'widget_code' => 'fixture-widget', 'feedback' => null];
    if ($path === '/api/update' && $method === 'POST') {
        if (array_keys($body) !== ['url'] || !is_string($body['url'])) throw new RuntimeException('Update accepts URL only');
        if (!str_starts_with($body['url'], trailingslashit(home_url()))) return $reply(['error' => 'outside source'], 403);
        $updates = get_option('dzen_fixture_updates', []);
        $updates[] = $body['url'];
        update_option('dzen_fixture_updates', $updates, false);
        return $reply(['status' => 'ok', 'action' => 'queued', 'url' => $body['url'],
            'final_url' => null, 'message' => 'Page update queued'], 202);
    }
    if ($method !== 'GET') return $reply(['error' => 'Unsupported method'], 405);
    if ($path === '/api/sources') return $reply(['items' => [$source]]);
    if ($path === '/api/sources/source-one') return $reply($source);
    if ($path === '/api/pages') {
        if (array_diff(array_keys($query), ['limit'])) throw new RuntimeException('Page query does not support these fields');
        return $reply(['items' => [$page]]);
    }
    if ($path === '/api/pages/page-one') return $reply($page);
    if ($path === '/api/files') return $reply(['items' => [$file]]);
    if ($path === '/api/files/file-one') return $reply($file);
    if ($path === '/api/chats') {
        if (array_diff(array_keys($query), ['limit'])) throw new RuntimeException('Chat query does not support these fields');
        return $reply(['items' => [$chat]]);
    }
    if ($path === '/api/chats/chat-one') return $reply($chat);
    if ($path === '/api/chats/chat-one/messages') return $reply(['items' => [
        ['id' => 1, 'role' => 'user', 'text' => 'Можно ли изменить адрес?', 'created_at' => '2026-09-11T01:00:00Z',
            'vote' => null, 'guardrail_triggered' => false, 'guardrail_stage' => null],
        ['id' => 2, 'role' => 'assistant', 'text' => 'Изменить адрес можно. <script>alert(1)</script>', 'created_at' => '2026-09-11T01:00:01Z',
            'vote' => true, 'guardrail_triggered' => false, 'guardrail_stage' => null],
    ]]);
    return $reply(['error' => 'Object or API method not found'], 404);
}, 10, 3);
