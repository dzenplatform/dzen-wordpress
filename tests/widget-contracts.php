<?php
/** Public embed regression tests in the isolated, connected Compose installation. */
if (wp_get_environment_type() !== 'local' || !function_exists('dzen_fixture_widgets')) {
    throw new RuntimeException('Use the isolated local API fixture');
}
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks) {
    if (!$ok) {
        throw new RuntimeException('FAILED: ' . $label);
    }
    ++$checks;
    echo 'PASS ' . $label . "\n";
};
$keys = ['dzen_chat_widget', 'dzen_chat_widgets', 'dzen_fixture_scenario', 'dzen_fixture_widgets', 'home'];
$saved = [];
foreach ($keys as $key) {
    $saved[$key] = get_option($key, null);
}
$api = new DzenChat\Api(new DzenChat\Credentials());
$plugin = new DzenChat\Plugin();
$requests = [];
$observe = static function ($pre, $args, $url) use (&$requests) {
    if ($url === 'https://chat.dzen.dev/api/v1/integration') {
        $requests[] = $args;
    }
    return $pre;
};
add_filter('pre_http_request', $observe, 9, 3);
$render = static function (int $post = 0) use ($plugin): array {
    $GLOBALS['wp_query'] = new WP_Query($post ? ['page_id' => $post] : ['post_type' => 'post']);
    $GLOBALS['wp_scripts'] = new WP_Scripts();
    $plugin->widget();
    $script = wp_scripts()->registered['dzen-chat-widget'] ?? null;
    ob_start();
    $plugin->widgetContainer();
    $html = ob_get_clean();
    return ['script' => $script, 'html' => $html];
};
$post = 0;
try {
    update_option('dzen_fixture_scenario', 'normal');
    $widget = ['id' => 'widget-one', 'code' => 'fixture-widget', 'name' => 'Widget regression',
        'is_enabled' => true, 'version' => 1, 'welcome_messages' => []];
    update_option('dzen_fixture_widgets', ['widget-one' => $widget], false);
    update_option('dzen_chat_widgets', ['widget-one' => $widget], false);
    update_option('dzen_chat_widget', 'widget-one', false);
    delete_transient('dzen_chat_status');
    delete_transient('dzen_chat_status_retry');

    $output = $render();
    $check($output['script']?->src === 'https://chat.dzen.dev/widget/fixture-widget', 'cold status cache refreshes before public embed');
    $check($output['html'] === '<div id="chat-chat"></div>', 'real Dzen mount container accompanies the script');
    $check(($output['script']->extra['strategy'] ?? '') === 'defer' && ($output['script']->extra['group'] ?? 0) === 1, 'loader runs deferred after footer mount');
    $check(count($requests) === 1 && $requests[0]['timeout'] === 3, 'public refresh has bounded timeout');
    $render();
    $check(count($requests) === 1, 'warm public cache avoids another API call');

    // Force WordPress's real transient expiry rather than only replacing the result.
    update_option('_transient_timeout_dzen_chat_status', time() - 1);
    $check($render()['script'] !== null && count($requests) === 2, 'expired status recovers with WP cron disabled');

    update_option('dzen_chat_widget', '');
    delete_transient('dzen_chat_status');
    $check($render() === ['script' => null, 'html' => ''] && count($requests) === 2, 'unselected site has no embed or status request');
    update_option('dzen_chat_widget', 'widget-one');
    $disabled = $widget;
    $disabled['is_enabled'] = false;
    update_option('dzen_chat_widgets', ['widget-one' => $disabled], false);
    $check($render() === ['script' => null, 'html' => ''], 'disabled widget omits script and mount');
    update_option('dzen_chat_widgets', ['widget-one' => $widget], false);

    update_option('dzen_fixture_scenario', 'blocked');
    $check($render() === ['script' => null, 'html' => ''], 'billing block still prevents public insertion');
    update_option('dzen_fixture_scenario', 'network');
    delete_transient('dzen_chat_status');
    $check($render()['script'] === null, 'unknown status fails closed');
    $afterFailure = count($requests);
    $render();
    $check(count($requests) === $afterFailure, 'network failure is not retried on every visitor');
    update_option('dzen_fixture_scenario', 'normal');
    update_option('_transient_timeout_dzen_chat_status_retry', time() - 1);
    $check($render()['script'] !== null, 'public refresh recovers after error backoff expires');

    $post = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Widget regression page']);
    update_post_meta($post, '_dzen_chat_widget_disabled', '1');
    $check($render($post) === ['script' => null, 'html' => ''], 'page exclusion prevents mount and loader');
    delete_post_meta($post, '_dzen_chat_widget_disabled');
    $override = $widget;
    $override['id'] = 'override-widget';
    $override['code'] = 'other-code';
    update_option('dzen_chat_widgets', ['widget-one' => $widget, 'override-widget' => $override], false);
    update_post_meta($post, '_dzen_chat_widget', 'override-widget');
    $check($render($post)['script']?->src === 'https://chat.dzen.dev/widget/other-code', 'per-page widget overrides the site choice');
    update_option('home', 'http://copied-site.invalid');
    $check($render()['script'] === null, 'site clone cannot reuse a warm status cache');
    update_option('home', $saved['home']);

    $result = $api->request('PATCH', '/widgets/widget-one', [], ['is_enabled' => false, 'expected_version' => 1], wp_generate_uuid4());
    $check($result['is_enabled'] === false && $api->request('GET', '/widgets')['items'][0]['is_enabled'] === false,
        'fixture toggle is persisted and reread from the API');
    $conflict = $api->request('PATCH', '/widgets/widget-one', [], ['is_enabled' => true, 'expected_version' => 1], wp_generate_uuid4());
    $check(is_wp_error($conflict) && $conflict->get_error_data()['status'] === 409, 'stale widget toggle does not overwrite newer state');
} finally {
    if ($post) {
        wp_delete_post($post, true);
    }
    foreach ($saved as $key => $value) {
        $value === null ? delete_option($key) : update_option($key, $value, false);
    }
    remove_filter('pre_http_request', $observe, 9);
    delete_transient('dzen_chat_status_retry');
    $api->status();
}
echo "Widget checks passed: $checks\n";
