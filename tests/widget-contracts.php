<?php
/** Public Widgets API and WordPress rendering/actions on the isolated fixture. */
if (wp_get_environment_type() !== 'local' || !function_exists('dzen_fixture_widgets')) {
    throw new RuntimeException('Use the isolated local API fixture');
}
ob_start();
final class DzenWidgetTestRedirect extends RuntimeException {}
final class DzenWidgetTestDie extends RuntimeException {}
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks) {
    if (!$ok) throw new RuntimeException('FAILED: ' . $label);
    ++$checks;
    echo 'PASS ' . $label . "\n";
};
$saved = [];
foreach (['dzen_chat_credentials', 'dzen_chat_verified', 'dzen_chat_widget', 'dzen_chat_widgets',
    'dzen_fixture_scenario', 'dzen_fixture_widgets', 'home'] as $key) {
    $saved[$key] = get_option($key, null);
}
$oldUser = get_current_user_id();
$oldGet = $_GET;
$oldPost = $_POST;
$oldRequest = $_REQUEST;
$credentials = new DzenChat\Credentials();
$api = new DzenChat\Api($credentials);
$widgets = new DzenChat\Widgets($credentials, $api);
$plugin = new DzenChat\Plugin();
$admin = new DzenChat\Admin($credentials, $api, new DzenChat\Sync($credentials, $api));
$requests = [];
$observe = static function ($pre, $args, $url) use (&$requests) {
    $requests[] = ['url' => $url, 'args' => $args];
    return $pre;
};
$redirect = static function ($location) { throw new DzenWidgetTestRedirect($location); };
$die = static fn () => static function ($message, $title = '', $args = []) {
    throw new DzenWidgetTestDie((string) $message, (int) ($args['response'] ?? 500));
};
add_filter('pre_http_request', $observe, 9, 3);
add_filter('wp_redirect', $redirect, -1000);
add_filter('wp_die_handler', $die, PHP_INT_MAX);
$submit = static function (array $data) use ($admin): array {
    $_POST = $data + ['_wpnonce' => wp_create_nonce('dzen_chat_action')];
    $_REQUEST = $_POST;
    try { $admin->action(); }
    catch (DzenWidgetTestRedirect $result) { return ['redirect' => $result->getMessage()]; }
    catch (DzenWidgetTestDie $result) { return ['error' => $result->getMessage(), 'status' => $result->getCode()]; }
    throw new RuntimeException('Expected action redirect or error');
};
$render = static function (int $post = 0) use ($plugin): array {
    $GLOBALS['wp_query'] = new WP_Query($post ? ['page_id' => $post] : ['post_type' => 'post']);
    $GLOBALS['wp_scripts'] = new WP_Scripts();
    $plugin->widget();
    $script = wp_scripts()->registered['dzen-chat-widget'] ?? null;
    ob_start();
    $plugin->widgetContainer();
    return ['script' => $script, 'html' => ob_get_clean()];
};
$clearCache = static function (bool $expire = false): void {
    global $wpdb;
    $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s",
        $wpdb->esc_like('_transient_dzen_chat_widget_') . '%'));
    foreach ($names as $name) {
        $key = substr($name, strlen('_transient_'));
        $expire ? update_option('_transient_timeout_' . $key, time() - 1) : delete_transient($key);
    }
};
$post = 0;
try {
    wp_set_current_user(get_user_by('login', 'dzen_test')->ID);
    $credentials->saveRegistration(['client_id' => 'chatid-fixture-12345',
        'client_secret' => 'fixture-secret-for-tests-only-123456789', 'site_source_id' => 'source-one'],
        DzenChat\Credentials::siteUrl(), DzenChat\Api::origin());
    $check($credentials->get()['auth_type'] === 'external_registration' && !get_option('dzen_chat_verified')
        && !isset($credentials->get()['integration_id']), 'widget tests use real registration-shaped credentials');
    $widget = ['id' => 'widget-one', 'code' => 'fixture-widget', 'name' => 'Widget regression',
        'is_enabled' => true, 'suggestions_enabled' => true, 'trigger_templates' => [], 'appearance' => (object) [],
        'edit_url' => 'https://chat.dzen.dev/projects/project-one/widgets/widget-one'];
    update_option('dzen_fixture_widgets', ['widget-one' => $widget], false);
    update_option('dzen_fixture_scenario', 'normal');
    update_option('dzen_chat_widget', 'widget-one', false);
    $clearCache();
    $output = $render();
    $check($output['script']?->src === 'https://chat.dzen.dev/widget/fixture-widget', 'registered client embeds through the implemented API');
    $check($output['html'] === '<div id="chat-chat"></div>', 'mount container accompanies the loader');
    $check(($output['script']->extra['strategy'] ?? '') === 'defer' && ($output['script']->extra['group'] ?? 0) === 1, 'loader runs after footer mount');
    $first = $requests[0];
    $check(count($requests) === 1 && $first['url'] === 'https://chat.dzen.dev/api/widgets/widget-one'
        && $first['args']['timeout'] === 3, 'public refresh uses actual endpoint and bounded timeout');
    $check($first['args']['headers']['Authorization'] === 'Bearer chatid-fixture-12345.fixture-secret-for-tests-only-123456789'
        && !isset($first['args']['headers']['X-Dzen-Signature']) && !isset($first['args']['headers']['Idempotency-Key'])
        && $first['args']['sslverify'] === true && $first['args']['redirection'] === 0 && $first['args']['cookies'] === [],
        'Bearer credentials use verified TLS without HMAC or redirects');
    $render();
    $check(count($requests) === 1, 'warm cache avoids another API call');
    $clearCache(true);
    $check($render()['script'] !== null && count($requests) === 2, 'expired widget cache refreshes without WP-Cron');
    update_option('dzen_chat_widget', '');
    $check($render() === ['script' => null, 'html' => ''] && count($requests) === 2, 'unselected site sends no request');
    update_option('dzen_chat_widget', 'widget-one');
    update_option('dzen_fixture_widgets', ['widget-one' => array_replace($widget, ['is_enabled' => false])], false);
    $clearCache(true);
    $check($render() === ['script' => null, 'html' => ''], 'remote disablement removes mount and script after refresh');
    update_option('dzen_fixture_widgets', ['widget-one' => $widget], false);
    $clearCache(true);
    $check($render()['script'] !== null, 'remote re-enablement appears without reopening admin');
    foreach (['revoked', 'forbidden', 'wrong_id', 'malformed', 'network'] as $scenario) {
        update_option('dzen_fixture_scenario', $scenario);
        $clearCache();
        $check($render() === ['script' => null, 'html' => ''], 'no public embed on ' . $scenario);
    }
    $afterFailure = count($requests);
    $render();
    $check(count($requests) === $afterFailure, 'failure is not retried for every visitor');
    update_option('dzen_fixture_scenario', 'normal');
    $clearCache(true);
    $check($render()['script'] !== null, 'public cache recovers after retry window');
    update_option('home', 'http://copied-site.invalid');
    $check($render()['script'] === null, 'copied site cannot reuse the cache');
    update_option('home', $saved['home']);
    $post = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Widget regression page']);
    update_post_meta($post, '_dzen_chat_widget_disabled', '1');
    $check($render($post) === ['script' => null, 'html' => ''], 'page exclusion suppresses the widget');
    delete_post_meta($post, '_dzen_chat_widget_disabled');
    $override = array_replace($widget, ['id' => 'override-widget', 'code' => 'override-code',
        'edit_url' => 'https://chat.dzen.dev/projects/project-one/widgets/override-widget']);
    update_option('dzen_fixture_widgets', ['widget-one' => $widget, 'override-widget' => $override], false);
    update_post_meta($post, '_dzen_chat_widget', 'override-widget');
    $check($render($post)['script']?->src === 'https://chat.dzen.dev/widget/override-code', 'page selection overrides the site widget');
    $all = $widgets->all();
    $check(count($all) === 2 && end($requests)['url'] === 'https://chat.dzen.dev/api/widgets', 'list uses complete non-paginated API');
    $check($all['widget-one']['edit_url'] === $widget['edit_url'], 'project editor URL is retained for browser navigation');
    foreach (['https://elsewhere.example/projects/project-one/widgets/widget-one',
        'https://chat.dzen.dev/projects/project-one/widgets/foreign-widget',
        $widget['edit_url'] . '?client_secret=unexpected', 'javascript:alert(1)'] as $unsafe) {
        update_option('dzen_fixture_widgets', ['widget-one' => array_replace($widget, ['edit_url' => $unsafe])], false);
        $check(!isset($widgets->get('widget-one')['edit_url']), 'unsafe or mismatched editor URL is omitted');
    }
    update_option('dzen_fixture_widgets', ['widget-one' => $widget, 'override-widget' => $override], false);
    $result = $submit(['operation' => 'widget_toggle', 'id' => 'widget-one', 'enabled' => '0', 'version' => '123']);
    $check(isset($result['redirect']) && json_decode(end($requests)['args']['body'], true) === ['is_enabled' => false], 'toggle sends only supported boolean');
    $check($render()['script'] === null, 'confirmed toggle immediately invalidates public enablement');
    $disabledSelection = $submit(['operation' => 'widget_select', 'id' => 'widget-one']);
    $check(isset($disabledSelection['error']), 'disabled widget cannot be newly placed');
    $submit(['operation' => 'widget_toggle', 'id' => 'widget-one', 'enabled' => '1']);
    $result = $submit(['operation' => 'widget_edit', 'id' => 'widget-one', 'name' => 'New widget name',
        'welcome' => 'Unsupported text', 'version' => '123']);
    $check(isset($result['redirect']) && json_decode(end($requests)['args']['body'], true)
        === ['name' => 'New widget name', 'suggestions_enabled' => false], 'edit sends name and suggestions only');
    $check($widgets->get('widget-one')['suggestions_enabled'] === false, 'suggestions change persists');
    $result = $submit(['operation' => 'widget_create', 'name' => 'New website assistant']);
    $created = end($requests);
    $check(isset($result['redirect']) && $created['args']['method'] === 'POST'
        && json_decode($created['args']['body'], true) === ['name' => 'New website assistant'], 'create sends minimal supported contract');
    $check(count($widgets->all()) === 3, 'created widget appears in fresh list');
    $result = $submit(['operation' => 'widget_select', 'id' => 'override-widget']);
    $check(isset($result['redirect']) && get_option('dzen_chat_widget') === 'override-widget', 'selection verifies the widget with server');
    $result = $submit(['operation' => 'widget_select']);
    $check(($result['status'] ?? 0) === 400 && get_option('dzen_chat_widget') === 'override-widget',
        'missing radio choice does not silently remove the selected widget');
    $result = $submit(['operation' => 'widget_select', 'id' => 'foreign-widget']);
    $check(isset($result['error']) && get_option('dzen_chat_widget') === 'override-widget', 'failed selection preserves previous widget');
    update_option('dzen_fixture_scenario', 'unconfirmed');
    $check(is_wp_error($widgets->save('widget-one', ['name' => 'Not confirmed'])), 'unconfirmed change is not reported as saved');
    update_option('dzen_fixture_scenario', 'normal');
    $before = count($requests);
    $result = $submit(['operation' => 'widget_toggle', 'id' => 'widget-one', 'enabled' => '0', '_wpnonce' => 'wrong']);
    $check(isset($result['error']) && count($requests) === $before, 'bad nonce cannot change widget');
    wp_set_current_user(get_user_by('login', 'dzen_subscriber')->ID);
    $result = $submit(['operation' => 'widget_create', 'name' => 'Forbidden']);
    $check(isset($result['error']) && count($requests) === $before, 'subscriber cannot manage widgets');
    wp_set_current_user(get_user_by('login', 'dzen_test')->ID);
    $result = $submit(['operation' => 'reconcile']);
    $check(isset($result['redirect']) && get_option('dzen_chat_reconcile_cursor') === 0 && count($requests) === $before,
        'reconciliation schedules work without blocking the admin action on crawling');
    $_GET = ['page' => 'dzen-chat-widgets'];
    ob_start();
    $admin->page();
    $html = ob_get_clean();
    $check(substr_count($html, 'type="radio"') === 4 && substr_count($html, 'class="dzen-widget-selected"') === 1
        && str_contains($html, 'value="override-widget" required checked='), 'all widgets and an explicit none option are shown with the saved choice');
    $check(str_contains($html, 'href="' . $override['edit_url'] . '" target="_blank" rel="noopener noreferrer"')
        && !str_contains($html, 'name="suggestions_enabled"'), 'widget settings open their project editor instead of an inline settings form');
    $check(!str_contains($html, 'fixture-secret') && !str_contains($html, 'Bearer '), 'admin does not expose credentials');
    ob_start();
    $plugin->metaBox(get_post($post));
    $meta = ob_get_clean();
    $check(str_contains($meta, '_dzen_chat_widget_disabled') && !str_contains($meta, 'name="_dzen_chat_triggers"')
        && !str_contains($meta, 'name="_dzen_chat_exclude"'), 'page controls defer unconnected APIs');
    $check(!get_option('dzen_chat_verified') && !array_filter($requests, static fn ($r) => str_contains($r['url'], '/api/v1/')),
        'widgets do not activate future APIs or query invented project status');
    delete_option('dzen_chat_credentials');
    $before = count($requests);
    $result = $submit(['operation' => 'widget_toggle', 'id' => 'widget-one', 'enabled' => '0']);
    $check(($result['status'] ?? 0) === 409 && count($requests) === $before,
        'stale widget form after disconnect asks to reconnect without an API request');
} finally {
    if ($post) wp_delete_post($post, true);
    $clearCache();
    foreach ($saved as $key => $value) $value === null ? delete_option($key) : update_option($key, $value, false);
    wp_set_current_user($oldUser);
    $_GET = $oldGet;
    $_POST = $oldPost;
    $_REQUEST = $oldRequest;
    remove_filter('pre_http_request', $observe, 9);
    remove_filter('wp_redirect', $redirect, -1000);
    remove_filter('wp_die_handler', $die, PHP_INT_MAX);
    ob_end_flush();
}
echo "Widget checks passed: $checks\n";
