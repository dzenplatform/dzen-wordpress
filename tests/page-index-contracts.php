<?php
require __DIR__ . '/setup.php';
define('DOING_AJAX', true);
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks) {
    if (!$ok) throw new RuntimeException('FAILED: ' . $label);
    ++$checks;
    echo 'PASS ' . $label . "\n";
};
$credentials = new DzenChat\Credentials();
$api = new DzenChat\Api($credentials);
$index = new DzenChat\PageIndex($credentials, $api);
$admin = get_user_by('login', 'dzen_test');
wp_set_current_user($admin->ID);
$post = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Page status contract']);
$url = home_url('/delivery/');
$permalink = static fn ($link, $id) => $id === $post ? $url : $link;
add_filter('page_link', $permalink, 10, 2);
$requests = [];
$observe = static function ($pre, $args, $url) use (&$requests) { $requests[] = $url; return $pre; };
add_filter('pre_http_request', $observe, 9, 3);
$get = static fn () => $index->status(get_post($post));
$check($get()['state'] === 'not_public' && !$requests, 'draft does not send private content to the API');
wp_update_post(['ID' => $post, 'post_status' => 'publish']);
$data = $get();
parse_str(wp_parse_url(end($requests), PHP_URL_QUERY), $query);
$check($data['state'] === 'ready' && $query === ['limit' => '1', 'source_id' => 'source-one', 'url' => $url], 'published page uses exact URL and connected source lookup');
$check(str_contains($data['sync'], 'waiting to be sent'), 'ready server page and pending local changes remain distinct');
global $wpdb;
[, $events] = DzenChat\Sync::tables();
foreach (['accepted' => 'does not confirm indexing', 'error' => 'could not be sent', 'blocked' => 'waiting for service access'] as $state => $text) {
    $wpdb->update($events, ['status' => $state], ['post_id' => $post, 'integration_id' => $credentials->get()['client_id']]);
    $check(str_contains($get()['sync'], $text), 'local submission status: ' . $state);
}
$override = null;
$modify = static function ($pre, $args, $url) use (&$override) {
    if ($override !== null && wp_parse_url($url, PHP_URL_PATH) === '/api/pages') {
        $pre['body'] = wp_json_encode(['items' => $override]);
    }
    return $pre;
};
add_filter('pre_http_request', $modify, 11, 3);
$page = $api->pageForUrl('source-one', $url);
foreach (['pending', 'processing', 'ready', 'errors', 'excluded'] as $state) {
    $override = [array_replace($page, ['index_status' => $state])];
    $check($get()['state'] === $state, 'page stage: ' . $state);
}
$override = [];
$check($get()['state'] === 'not_found', 'exact lookup distinguishes an unknown URL');
foreach (['source_id' => 'another-source', 'url' => home_url('/another/'), 'index_status' => 'unsupported'] as $field => $value) {
    $override = [array_replace($page, [$field => $value])];
    $check(is_wp_error($get()), 'mismatched response rejected: ' . $field);
}
$override = [$page, $page];
$check(is_wp_error($get()), 'multiple pages do not confirm an exact lookup');
$override = null;
foreach (['network', 'revoked'] as $scenario) {
    update_option('dzen_fixture_scenario', $scenario, false);
    $check(is_wp_error($get()), 'unavailable API does not become not-found: ' . $scenario);
}
update_option('dzen_fixture_scenario', 'normal', false);
wp_update_post(['ID' => $post, 'post_password' => 'test-password']);
$requests = [];
$data = $get();
$check($data['state'] === 'not_public' && !$requests && str_contains($data['description'], 'Password-protected'), 'password protection prevents public lookup');
$check(str_contains($data['description'], 'removal from the index is not immediate'), 'previously public content is not falsely reported as removed');
wp_update_post(['ID' => $post, 'post_password' => '']);
update_post_meta($post, '_dzen_chat_exclude', '1');
$requests = [];
$check(str_contains($get()['description'], 'excluded from automatic') && !$requests, 'local indexing exclusion respected');
delete_post_meta($post, '_dzen_chat_exclude');
update_post_meta($post, '_dzen_chat_widget_disabled', '1');
$check($get()['state'] === 'ready', 'widget visibility does not change page indexing');

$exitHandler = static fn () => static function () { throw new RuntimeException('page_index_ajax_exit'); };
add_filter('wp_die_ajax_handler', $exitHandler);
$ajax = static function (array $input) use ($index) {
    $_POST = $input;
    ob_start();
    try { $index->ajax(); }
    catch (RuntimeException $error) { if ($error->getMessage() !== 'page_index_ajax_exit') throw $error; }
    return json_decode(ob_get_clean(), true);
};
$nonce = wp_create_nonce('dzen_chat_page_status_' . $post);
$check($ajax(['post_id' => (string) $post, '_ajax_nonce' => $nonce])['success'] === true, 'authorized AJAX response returns current page status');
foreach ([[], ['post_id' => (string) $post, '_ajax_nonce' => 'bad'], ['post_id' => [$post]],
    ['post_id' => (string) $post, '_ajax_nonce' => ['invalid']]] as $input) {
    $requests = [];
    $check($ajax($input)['success'] === false && !$requests, 'invalid AJAX identity or nonce is rejected before HTTP');
}
wp_set_current_user(get_user_by('login', 'dzen_subscriber')->ID);
$requests = [];
$check($ajax(['post_id' => (string) $post, '_ajax_nonce' => $nonce])['success'] === false && !$requests, 'subscriber cannot read indexing status');
wp_set_current_user($admin->ID);
ob_start();
(new DzenChat\Plugin())->metaBox(get_post($post));
$html = ob_get_clean();
$check(str_contains($html, 'dzen-page-index') && str_contains($html, 'Hide widget')
    && !str_contains($html, 'fixture-secret') && !str_contains($html, 'Bearer '), 'index panel preserves widget settings without exposing API credentials');
remove_filter('wp_die_ajax_handler', $exitHandler);
remove_filter('pre_http_request', $observe, 9);
remove_filter('pre_http_request', $modify, 11);
remove_filter('page_link', $permalink, 10);
wp_delete_post($post, true);
$wpdb->delete($events, ['post_id' => $post]);
echo "Page indexing checks passed: $checks\n";
