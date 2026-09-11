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
$sync = new DzenChat\Sync($credentials, $api);
$index = new DzenChat\PageIndex($credentials, $api);
$admin = get_user_by('login', 'dzen_test');
wp_set_current_user($admin->ID);
$originalPermalinks = get_option('permalink_structure');
update_option('permalink_structure', '/%postname%/');
global $wpdb;
[$objects, $events] = DzenChat\Sync::tables();
$posts = [];
$newPost = static function (string $status = 'publish') use (&$posts) {
    $id = wp_insert_post(['post_type' => 'page', 'post_status' => $status, 'post_title' => 'Exclusion contract ' . wp_generate_uuid4()]);
    $posts[] = $id;
    return $id;
};
$post = $newPost();
$oldUrl = get_permalink($post);
wp_update_post(['ID' => $post, 'post_name' => 'renamed-exclusion-' . $post]);
$newUrl = get_permalink($post);
$requests = [];
$observe = static function ($pre, $args, $url) use (&$requests) { $requests[] = [$args['method'], $url, $args['body']]; return $pre; };
add_filter('pre_http_request', $observe, 9, 3);
$exitHandler = static fn () => static function () { throw new RuntimeException('ajax_exit'); };
add_filter('wp_die_ajax_handler', $exitHandler);
$ajax = static function (array $input) use ($index) {
    $_POST = $input;
    ob_start();
    try { $index->ajaxExclude(); } catch (RuntimeException $error) { if ($error->getMessage() !== 'ajax_exit') throw $error; }
    return json_decode(ob_get_clean(), true);
};
$input = ['post_id' => (string) $post, '_ajax_nonce' => wp_create_nonce('dzen_chat_page_exclude_' . $post)];
foreach ([[], ['post_id' => (string) $post, '_ajax_nonce' => wp_create_nonce('dzen_chat_page_status_' . $post)],
    ['post_id' => (string) ($post + 1), '_ajax_nonce' => $input['_ajax_nonce']],
    ['post_id' => [$post], '_ajax_nonce' => $input['_ajax_nonce']]] as $bad) {
    $check($ajax($bad)['success'] === false && !$requests && !get_post_meta($post, '_dzen_chat_exclude', true), 'exclusion rejects invalid identity or wrong nonce');
}
wp_set_current_user(get_user_by('login', 'dzen_subscriber')->ID);
$check($ajax($input)['success'] === false && !$requests, 'subscriber cannot exclude a page');
wp_set_current_user($admin->ID);
$product = wp_insert_post(['post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'Unsupported exclusion']);
$posts[] = $product;
$check($ajax(['post_id' => (string) $product, '_ajax_nonce' => wp_create_nonce('dzen_chat_page_exclude_' . $product)])['success'] === false && !$requests, 'products remain outside integration scope');
$data = $ajax($input);
$check($data['success'] && $data['data']['state'] === 'excluded' && $data['data']['exclusion']['done'], 'button confirms actual server exclusion');
$check(get_post_meta($post, '_dzen_chat_exclude', true) === '1' && get_post_status($post) === 'publish', 'blocking is persistent without unpublishing the page');
$urls = array_map(static fn ($request) => json_decode($request[2], true)['url'], $requests);
$check(count($urls) === 2 && in_array($oldUrl, $urls, true) && in_array($newUrl, $urls, true), 'all known old and current public URLs are excluded');
$check(count(array_filter($requests, static fn ($r) => $r[0] !== 'POST' || !str_ends_with($r[1], '/pages/exclude'))) === 0, 'removal never becomes an ordinary update');
$check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $events WHERE post_id=%d AND status='cancelled'", $post)) === 2, 'older queued revisions are cancelled');
$count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $events WHERE post_id=%d", $post));
$requests = [];
wp_update_post(['ID' => $post, 'post_content' => 'Do not submit this edit']);
$sync->retry();
$sync->run($post);
$check(!$requests && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $events WHERE post_id=%d", $post)) === $count, 'saving and retry cannot requeue excluded content');
$sync->saved($post, get_post($post), false, null);
$check(!$requests && $index->status(get_post($post))['state'] === 'excluded', 'reconciliation preserves the exclusion');
wp_update_post(['ID' => $post, 'post_name' => 'excluded-new-url-' . $post]);
$sync->run($post);
$check($requests && !array_filter($requests, static fn ($r) => !str_ends_with($r[1], '/pages/exclude')), 'permalink changes only exclude URLs, without submitting new content');

$draft = $newPost('draft');
$requests = [];
$check($sync->exclude(get_post($draft)) === null, 'draft can be blocked before publication');
$sync->run($draft);
$check(!$requests && $index->status(get_post($draft))['exclusion']['done'], 'never-public draft needs no remote removal');
wp_update_post(['ID' => $draft, 'post_status' => 'publish']);
$sync->run($draft);
$check(count($requests) === 1 && str_ends_with($requests[0][1], '/pages/exclude'), 'publishing a blocked draft excludes the URL instead of indexing content');

$failed = $newPost();
update_option('dzen_fixture_scenario', 'network', false);
$requests = [];
$sync->exclude(get_post($failed));
$sync->run($failed);
$check($index->status(get_post($failed))['state'] === 'exclusion_pending' && get_post_meta($failed, '_dzen_chat_exclude', true), 'network failure keeps local blocking and never claims remote removal');
wp_update_post(['ID' => $failed, 'post_content' => 'Blocked during outage']);
$check(!array_filter($requests, static fn ($r) => !str_ends_with($r[1], '/pages/exclude')), 'outage does not allow new content submissions');
update_option('dzen_fixture_scenario', 'normal', false);
$sync->exclude(get_post($failed));
$sync->run($failed);
$check($index->status(get_post($failed))['state'] === 'excluded', 'explicit retry bypasses the backoff after an outage');

$busy = $newPost();
add_option('dzen_chat_worker_lock', time() . ':test-busy-worker', '', false);
$requests = [];
$sync->exclude(get_post($busy));
$sync->run($busy);
$check(!$requests && $index->status(get_post($busy))['state'] === 'exclusion_pending', 'busy worker leaves durable removal pending');
delete_option('dzen_chat_worker_lock');
$sync->run($busy);
$check(count($requests) === 1 && str_ends_with($requests[0][1], '/pages/exclude'), 'next worker sends exclusion without restoring cancelled updates');

$stale = $newPost();
update_post_meta($stale, '_dzen_chat_exclude', '1');
$requests = [];
$sync->run($stale);
$check(!$requests && $wpdb->get_var($wpdb->prepare("SELECT status FROM $events WHERE post_id=%d ORDER BY id DESC LIMIT 1", $stale)) === 'cancelled', 'worker checks exclusion again immediately before sending');

$override = null;
$modify = static function ($pre, $args, $url) use (&$override) {
    if ($override !== null && str_ends_with($url, '/pages/exclude')) $pre['body'] = wp_json_encode($override);
    return $pre;
};
add_filter('pre_http_request', $modify, 11, 3);
foreach ([['url' => home_url('/wrong'), 'source_id' => 'source-one', 'index_status' => 'excluded'],
    ['url' => $oldUrl, 'source_id' => 'other-source', 'index_status' => 'excluded'],
    ['url' => $oldUrl, 'source_id' => 'source-one', 'index_status' => 'ready']] as $override) {
    $check(is_wp_error($api->excludeUrl('source-one', $oldUrl)), 'exclusion requires matching source, URL and confirmed state');
}
remove_filter('pre_http_request', $modify, 11);
remove_filter('pre_http_request', $observe, 9);
remove_filter('wp_die_ajax_handler', $exitHandler);
$_POST = [];
foreach ($posts as $id) {
    wp_delete_post($id, true);
    $wpdb->delete($events, ['post_id' => $id]);
    $wpdb->delete($objects, ['post_id' => $id]);
}
update_option('permalink_structure', $originalPermalinks);
echo "Page exclusion checks passed: $checks\n";
