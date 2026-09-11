<?php
/** Run with wp eval-file on the isolated compose installation. */
require __DIR__ . '/setup.php';
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks) {
    if (!$ok) {
        throw new RuntimeException('FAILED: ' . $label);
    }
    ++$checks;
    echo 'PASS ' . $label . "\n";
};
$credentials = new DzenChat\Credentials();
$api = new DzenChat\Api($credentials);
$check(!str_contains(get_option('dzen_chat_credentials'), 'fixture-secret'), 'secret encrypted at rest');
$check($credentials->get()['client_id'] === 'chatid-fixture-12345', 'credential round trip');
$check($credentials->encrypt(['value' => 1]) !== $credentials->encrypt(['value' => 1]), 'random nonce on each encryption');
$encrypted = $credentials->encrypt(['value' => 1]);
$tampered = substr($encrypted, 0, 12) . ($encrypted[12] === 'A' ? 'B' : 'A') . substr($encrypted, 13);
try { $credentials->decrypt($tampered); $check(false, 'tamper detected'); }
catch (RuntimeException $e) { $check(true, 'tamper detected'); }

$signature = DzenChat\Api::signature('secret', 'PATCH', '/api/v1/chats/c/visibility', 'client', '123', 'nonce', 'event', '{"hidden":true}');
$canonical = "DZEN-HMAC-V1\nPATCH\n/api/v1/chats/c/visibility\nclient\n123\nnonce\nevent\n" . hash('sha256', '{"hidden":true}');
$check($signature === hash_hmac('sha256', $canonical, 'secret'), 'signature canonicalization');
$check($signature !== DzenChat\Api::signature('secret', 'GET', '/api/v1/chats/c/visibility', 'client', '123', 'nonce', 'event', '{"hidden":true}'), 'method bound to signature');
$check($signature !== DzenChat\Api::signature('secret', 'PATCH', '/api/v1/chats/other/visibility', 'client', '123', 'nonce', 'event', '{"hidden":true}'), 'object bound to signature');
$check($signature !== DzenChat\Api::signature('secret', 'PATCH', '/api/v1/chats/c/visibility', 'client', '123', 'nonce', 'event', '{"hidden":false}'), 'target state bound to signature');

$check(is_wp_error(DzenChat\Admin::filters(['date_from' => '2026-02-31'])), 'impossible date rejected');
$check(is_wp_error(DzenChat\Admin::filters(['date_from' => '2026-09-10', 'date_to' => '2026-09-09'])), 'reversed dates rejected');
$check(is_wp_error(DzenChat\Admin::filters(['visibility' => 'deleted'])), 'unknown visibility rejected');
$check(DzenChat\Admin::filters([])['visibility'] === 'visible', 'visible is default');
$check(DzenChat\Admin::publicUrl('javascript:alert(1)') === '', 'javascript source URL rejected');
$check(DzenChat\Admin::publicUrl('https://user:secret@example.org/private') === '', 'credential URL rejected');
$check(DzenChat\Admin::publicUrl('https://example.org/source') !== '', 'public source URL accepted');

$listed = $api->request('GET', '/chats', ['visibility' => 'visible', 'limit' => 20]);
$check(!is_wp_error($listed) && count($listed['items']) === 1, 'signed list request accepted');
$check($api->request('DELETE', '/chats/chat-one')->get_error_code() === 'dzen_history_immutable', 'chat deletion blocked before transport');
$check($api->request('PATCH', '/chats/chat-one/messages/msg-answer', [], ['text' => 'modified'])->get_error_code() === 'dzen_history_immutable', 'message edits blocked before transport');
$hidden = $api->request('PATCH', '/chats/chat-one/visibility', [], ['hidden' => true, 'expected_version' => 1], wp_generate_uuid4());
$check($hidden['hidden'] === true && $hidden['visibility_version'] === 2, 'explicit hide request');
$check($api->request('GET', '/chats', ['visibility' => 'visible'])['items'] === [], 'hidden excluded from visible list');
$check(count($api->request('GET', '/chats', ['visibility' => 'hidden'])['items']) === 1, 'hidden filter includes chat');
$check($api->request('GET', '/chats/chat-one')['message_count'] === 2, 'hidden detail retains messages');
$check($api->request('GET', '/chats/other-chat')->get_error_data()['status'] === 404, 'foreign chat rejected');
$conflict = $api->request('PATCH', '/chats/chat-one/visibility', [], ['hidden' => false, 'expected_version' => 1], wp_generate_uuid4());
$check(is_wp_error($conflict) && $conflict->get_error_data()['status'] === 409, 'stale visibility version rejected');
$restored = $api->request('PATCH', '/chats/chat-one/visibility', [], ['hidden' => false, 'expected_version' => 2], wp_generate_uuid4());
$check($restored['hidden'] === false, 'explicit restore request');
$source = $api->request('GET', '/chats/chat-one/messages/msg-answer/sources/source-ref');
$check($source['content_kind'] === 'excerpt' && str_contains($source['content'], 'Изменить адрес'), 'source reference read');
$check(is_wp_error($api->request('GET', '/chats/chat-one/messages/other-message/sources/source-ref')), 'source chain authorization');

foreach (['forbidden' => 403, 'rate_limit' => 429, 'conflict' => 409] as $scenario => $status) {
    update_option('dzen_fixture_scenario', $scenario);
    $result = $api->request('PATCH', '/chats/chat-one/visibility', [], ['hidden' => true, 'expected_version' => 3], wp_generate_uuid4());
    $check(is_wp_error($result) && $result->get_error_data()['status'] === $status, 'surface API ' . $status);
    $check(get_option('dzen_fixture_visibility')['hidden'] === false, 'failed request did not hide ' . $status);
}
update_option('dzen_fixture_scenario', 'network');
$check(is_wp_error($api->status()) && get_transient('dzen_chat_status') === false, 'failed verification clears stale status');
update_option('dzen_fixture_scenario', 'normal');
$api->status();

global $wpdb;
[$objects, $events] = DzenChat\Sync::tables();
$wpdb->query("DELETE FROM $events");
$wpdb->query("DELETE FROM $objects");
delete_option('dzen_chat_reconcile_cursor');
$postId = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Sync contract page', 'post_content' => 'First text']);
$count = static fn () => (int) $wpdb->get_var("SELECT COUNT(*) FROM $events");
$check($count() === 0, 'draft never queued');
wp_update_post(['ID' => $postId, 'post_status' => 'publish']);
$check($count() === 1, 'first publication queued');
wp_update_post(['ID' => $postId, 'post_content' => 'Second text']);
$check($count() === 2, 'content edit in same second queued');
$sync = new DzenChat\Sync($credentials, $api);
$sync->saved($postId, get_post($postId), true, null);
$check($count() === 2, 'unchanged hook deduplicated');
register_post_type('product', ['public' => true]);
$product = wp_insert_post(['post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Do not index product']);
$check($count() === 2, 'products excluded');
wp_update_post(['ID' => $postId, 'post_password' => 'protected']);
$last = json_decode($wpdb->get_var("SELECT payload FROM $events ORDER BY id DESC LIMIT 1"), true);
$check($last['action'] === 'delete', 'password protection removes document');
wp_update_post(['ID' => $postId, 'post_password' => '']);
wp_trash_post($postId);
$last = json_decode($wpdb->get_var("SELECT payload FROM $events ORDER BY id DESC LIMIT 1"), true);
$check($last['action'] === 'delete', 'trash removes document');
$payloads = array_map(static fn ($s) => json_decode($s, true), $wpdb->get_col("SELECT payload FROM $events ORDER BY id"));
$check(array_column($payloads, 'revision') === range(1, count($payloads)), 'monotonic object versions');
$sync->run();
$check((int) $wpdb->get_var("SELECT COUNT(*) FROM $events WHERE status='accepted'") > 0, '202 is accepted not completed');
$wpdb->query("UPDATE $events SET next_attempt=0");
$sync->run();
$check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $events WHERE status='done' AND post_id=%d", $postId)) === count($payloads), 'operation completion recorded');

$old = get_option('home');
update_option('home', 'http://copied-site.invalid');
try { $credentials->get(); $check(false, 'site clone blocked'); }
catch (RuntimeException $e) { $check(true, 'site clone blocked'); }
update_option('home', $old);

$leaks = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE %s AND (option_value LIKE %s OR option_value LIKE %s)",
    $wpdb->esc_like('dzen_chat_') . '%', '%Можно ли изменить адрес%', '%Изменить адрес можно%'));
$check($leaks === 0, 'no chat or source text persisted in plugin options');
$autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM $wpdb->options WHERE option_name=%s", 'dzen_chat_credentials'));
$check(in_array($autoload, ['no', 'off', 'auto-off'], true), 'credentials not autoloaded');
$check(!str_contains(get_option('dzen_chat_credentials'), 'fixture-secret'), 'secret remains encrypted');

wp_delete_post($postId, true);
wp_delete_post($product, true);
update_option('dzen_fixture_scenario', 'normal');
update_option('dzen_fixture_visibility', ['hidden' => false, 'version' => 1]);
$api->status();
echo "Checks passed: $checks\n";
