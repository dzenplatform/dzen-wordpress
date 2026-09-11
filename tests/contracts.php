<?php
/** Only the disposable fixture WordPress, never the real registration volume. */
require __DIR__ . '/setup.php';
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks) {
    if (!$ok) throw new RuntimeException('FAILED: ' . $label);
    ++$checks;
    echo 'PASS ' . $label . "\n";
};
$credentials = new DzenChat\Credentials();
$api = new DzenChat\Api($credentials);
$sync = new DzenChat\Sync($credentials, $api);
$requests = [];
$observe = static function ($pre, $args, $url) use (&$requests) {
    $requests[] = ['url' => $url, 'args' => $args];
    return $pre;
};
add_filter('pre_http_request', $observe, 9, 3);
$check(!str_contains(get_option('dzen_chat_credentials'), 'fixture-secret'), 'credentials encrypted at rest');
$check($credentials->get()['client_id'] === 'chatid-fixture-12345' && !isset($credentials->get()['integration_id']), 'actual exchange credentials round trip');
$encrypted = $credentials->encrypt(['value' => 1]);
$check($encrypted !== $credentials->encrypt(['value' => 1]), 'random encryption nonce');
$tampered = substr($encrypted, 0, 12) . ($encrypted[12] === 'A' ? 'B' : 'A') . substr($encrypted, 13);
try { $credentials->decrypt($tampered); $check(false, 'tamper detected'); }
catch (RuntimeException $e) { $check(true, 'tamper detected'); }
$storedCredentials = get_option('dzen_chat_credentials');
$invalidCredentials = $credentials->get();
$invalidCredentials['client_secret'] = ['invalid'];
update_option('dzen_chat_credentials', $credentials->encrypt($invalidCredentials), false);
$before = count($requests);
$check($api->request('GET', '/sources')->get_error_code() === 'dzen_configuration'
    && count($requests) === $before, 'invalid decrypted credentials never reach HTTP');
update_option('dzen_chat_credentials', $storedCredentials, false);
$check(is_wp_error(DzenChat\Admin::filters(['date_from' => '2026-02-31'])), 'invalid date rejected');
$check(is_wp_error(DzenChat\Admin::filters(['date_from' => '2026-09-10', 'date_to' => '2026-09-09'])), 'reversed dates rejected');
$check(is_wp_error(DzenChat\Admin::filters(['q' => str_repeat('x', 201)])), 'oversized query rejected');
$check(DzenChat\Admin::publicUrl('javascript:alert(1)') === '', 'script source link rejected');
$check(DzenChat\Admin::publicUrl('https://user:secret@example.org/private') === '', 'credential-bearing source link rejected');
$check(DzenChat\Admin::publicUrl('https://example.org/source') !== '', 'external source link supported');
foreach (['/sources', '/pages', '/files', '/chats'] as $path) {
    $items = $api->items($path, in_array($path, ['/pages', '/chats'], true) ? ['limit' => 100] : []);
    $check(!is_wp_error($items) && count($items) === 1, 'actual list DTO ' . $path);
    $request = end($requests);
    $check($request['args']['headers']['Authorization'] === 'Bearer chatid-fixture-12345.fixture-secret-for-tests-only-123456789'
        && !isset($request['args']['headers']['X-Dzen-Signature'])
        && str_starts_with($request['url'], 'https://chat.dzen.dev/api/')
        && $request['args']['sslverify'] && $request['args']['redirection'] === 0, 'Bearer-only transport ' . $path);
}
$before = count($requests);
$check($api->request('DELETE', '/chats/chat-one')->get_error_code() === 'dzen_history_immutable'
    && count($requests) === $before, 'no chat deletion request');
$check($api->request('PATCH', '/chats/chat-one/messages/1', [], ['text' => 'edit'])->get_error_code() === 'dzen_history_immutable'
    && count($requests) === $before, 'no message editing request');
$check($api->request('PATCH', '/chats/chat-one/visibility', [], ['hidden' => true])->get_error_code() === 'dzen_history_immutable'
    && count($requests) === $before, 'unimplemented visibility is not simulated');
$check($api->request('GET', '/chats/foreign')->get_error_data()['status'] === 404, 'foreign conversation inaccessible');
update_option('dzen_fixture_scenario', 'malformed_list');
$check(is_wp_error($api->items('/pages')), 'malformed list rejected');
update_option('dzen_fixture_scenario', 'normal');
$before = count($requests);
delete_option('dzen_chat_sync_client');
$sync->initialize();
$check(get_option('dzen_chat_reconcile_cursor') === 0 && wp_next_scheduled('dzen_chat_worker')
    && count($requests) === $before, 'upgrade schedules initial public scan without inline HTTP');
update_option('dzen_chat_reconcile_cursor', 55, false);
$sync->initialize();
$check(get_option('dzen_chat_reconcile_cursor') === 55, 'initialization preserves an in-progress scan');

global $wpdb;
[$objects, $events] = DzenChat\Sync::tables();
$wpdb->query("DELETE FROM $events");
$wpdb->query("DELETE FROM $objects");
delete_option('dzen_chat_reconcile_cursor');
delete_option('dzen_chat_verified');
delete_option('dzen_fixture_updates');
$post = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Sync contract page', 'post_content' => 'First text']);
$count = static fn () => (int) $wpdb->get_var("SELECT COUNT(*) FROM $events");
$check($count() === 0, 'draft never queued');
wp_update_post(['ID' => $post, 'post_status' => 'publish']);
$check($count() === 1, 'publication queues actual registered client');
wp_update_post(['ID' => $post, 'post_content' => 'Second text']);
$check($count() === 2, 'same-second content edit is not lost');
$sync->saved($post, get_post($post), true, null);
$check($count() === 2, 'unchanged hook deduplicated');
$last = json_decode($wpdb->get_var("SELECT payload FROM $events ORDER BY id DESC LIMIT 1"), true);
$check($last['url'] === get_permalink($post)
    && $wpdb->get_var("SELECT integration_id FROM $events LIMIT 1") === $credentials->get()['client_id'], 'queue bound to current client and public URL');
register_post_type('product', ['public' => true]);
$product = wp_insert_post(['post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Excluded product']);
$protected = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_password' => 'test', 'post_title' => 'Protected']);
$check($count() === 2, 'products and initially protected posts never queued');
wp_update_post(['ID' => $post, 'post_password' => 'protected']);
$last = json_decode($wpdb->get_var("SELECT payload FROM $events ORDER BY id DESC LIMIT 1"), true);
$check($last['action'] === 'delete' && $last['url'] !== '', 'closing public content schedules its previous URL for recheck');
wp_update_post(['ID' => $post, 'post_password' => '']);
wp_trash_post($post);
$last = json_decode($wpdb->get_var("SELECT payload FROM $events ORDER BY id DESC LIMIT 1"), true);
$check($last['action'] === 'delete', 'trash schedules recheck without claiming remote deletion');
$sync->run();
$check((int) $wpdb->get_var("SELECT COUNT(*) FROM $events WHERE status='accepted'") === $count(), '202 records submission, never index completion');
$sent = array_values(array_filter($requests, static fn ($r) => str_contains($r['url'], '/api/update')));
$check(count($sent) === $count() && array_keys(json_decode($sent[0]['args']['body'], true)) === ['url'], 'sync uses URL-only update contract without operation IDs');
$before = count($requests);
$sync->run();
$check(count($requests) === $before, 'accepted submissions are not polled or resubmitted');
wp_update_post(['ID' => $post, 'post_status' => 'publish']);
update_option('dzen_fixture_scenario', 'rate_limit');
$sync->run();
$check((int) $wpdb->get_var("SELECT COUNT(*) FROM $events WHERE status='pending' AND attempts=1") === 1, 'rate limit leaves durable pending event');
$check((int) $wpdb->get_var("SELECT next_attempt FROM $events WHERE status='pending' LIMIT 1") >= time() + 115, 'retry-after honored');
$before = count($requests);
$sync->run();
$check(count($requests) === $before, 'backoff does not retry immediately');
$wpdb->query("UPDATE $events SET next_attempt=0 WHERE status='pending'");
update_option('dzen_fixture_scenario', 'normal');
$sync->run();
$check((int) $wpdb->get_var("SELECT COUNT(*) FROM $events WHERE status='pending'") === 0, 'retry recovers after service returns');
$oldHome = get_option('home');
update_option('home', 'http://copied-site.invalid');
$before = count($requests);
$sync->run();
$check(count($requests) === $before && get_option('dzen_chat_queue_error'), 'copied site cannot send queued credentials');
update_option('home', $oldHome);
delete_option('dzen_chat_queue_error');

$receiptUrl = '';
$receipt = static function ($pre, $args, $url) use (&$receiptUrl) {
    if (!str_ends_with($url, '/api/update')) return $pre;
    return ['headers' => [], 'response' => ['code' => 202],
        'body' => wp_json_encode(['status' => 'ok', 'action' => 'queued', 'url' => $receiptUrl])];
};
add_filter('pre_http_request', $receipt, 99, 3);
foreach ([
    ['https://EXAMPLE.org:443', 'https://example.org/', true, 'normalized root URL is acknowledged'],
    ['https://пример.рф/доставка?я=1', 'https://xn--e1afmkfd.xn--p1ai/%D0%B4%D0%BE%D1%81%D1%82%D0%B0%D0%B2%D0%BA%D0%B0?%D1%8F=1', true, 'normalized IDN and Unicode URL are acknowledged'],
    ['https://example.org/a', 'https://example.org/b', false, 'different receipt path rejected'],
    ['https://example.org/?page_id=5', 'https://example.org/?page_id=6', false, 'different receipt query rejected'],
    ['https://example.org:8869/', 'https://example.org/', false, 'different receipt port rejected'],
    ['https://example.org/', 'https://other.example.org/', false, 'different receipt host rejected'],
] as [$requestedUrl, $returnedUrl, $accepted, $label]) {
    $receiptUrl = $returnedUrl;
    $check(!is_wp_error($api->reindex($requestedUrl)) === $accepted, $label);
}
remove_filter('pre_http_request', $receipt, 99);
$before = count($requests);
$check(is_wp_error($api->reindex('javascript:alert(1)')) && count($requests) === $before,
    'invalid public URL is rejected before HTTP');

$permalink = get_option('permalink_structure');
$oldUrl = get_permalink($post);
global $wp_rewrite;
$wp_rewrite->set_permalink_structure($permalink === '/%postname%/' ? '/articles/%postname%/' : '/%postname%/');
wp_update_post(['ID' => $post, 'post_name' => 'sync-renamed-page']);
$newUrl = get_permalink($post);
$last = json_decode($wpdb->get_var("SELECT payload FROM $events ORDER BY id DESC LIMIT 1"), true);
$check($newUrl !== $oldUrl && $last['previous_url'] === $oldUrl && $last['url'] === $newUrl,
    'permalink change retains both public URLs');
delete_option('dzen_chat_reconcile_cursor');
$before = count($requests);
$sync->run();
$updates = array_map(static fn ($r) => json_decode($r['args']['body'], true)['url'], array_slice($requests, $before));
$check($updates === [$oldUrl, $newUrl], 'old and new permalink both submitted for recheck');
$wp_rewrite->set_permalink_structure($permalink);
delete_option('dzen_chat_reconcile_cursor');

wp_set_current_user(get_user_by('login', 'dzen_test')->ID);
$admin = new DzenChat\Admin($credentials, $api, $sync);
foreach ([
    ['page' => 'dzen-chat-history', 'chat' => 'chat-one'],
    ['page' => 'dzen-chat-feedback'],
    ['page' => 'dzen-chat-documents', 'file' => 'file-one'],
    ['page' => 'dzen-chat-documents'],
    ['page' => 'dzen-chat-documents', 'source' => 'source-one'],
] as $query) {
    $_GET = $query;
    ob_start();
    $admin->page();
    $html = ob_get_clean();
    $check(!str_contains($html, '<script>') && !str_contains($html, 'fixture-secret') && !str_contains($html, 'Bearer '),
        'safe actual data view ' . wp_json_encode($query));
}
$check(!array_filter($requests, static fn ($r) => str_contains($r['url'], '/api/v1/')), 'no proposed versioned API used');
$leaks = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE %s AND (option_value LIKE %s OR option_value LIKE %s)",
    $wpdb->esc_like('dzen_chat_') . '%', '%Можно ли изменить адрес%', '%Тестовый текст файла%'));
$check($leaks === 0, 'chat and source contents are never persisted in plugin options');
$autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM $wpdb->options WHERE option_name=%s", 'dzen_chat_credentials'));
$check(in_array($autoload, ['no', 'off', 'auto-off'], true), 'credentials not autoloaded');
wp_update_post(['ID' => $post, 'post_content' => 'Pending before reconnect']);
$originalCredentials = $credentials->get();
$credentials->saveRegistration(['client_id' => 'chatid-rotated-12345',
    'client_secret' => 'rotated-fixture-secret-for-tests-only', 'site_source_id' => 'source-two'],
    DzenChat\Credentials::siteUrl(), DzenChat\Api::origin());
$before = count($requests);
$sync->run();
$check(count($requests) === $before, 'reconnected client never replays previous client events');
$credentials->saveRegistration($originalCredentials, DzenChat\Credentials::siteUrl(), DzenChat\Api::origin());
wp_delete_post($post, true);
wp_delete_post($product, true);
wp_delete_post($protected, true);
$wpdb->query("DELETE FROM $events");
$wpdb->query("DELETE FROM $objects");
delete_option('dzen_fixture_updates');
remove_filter('pre_http_request', $observe, 9);
echo "Checks passed: $checks\n";
