<?php
/** Actual registration contract; no management API or real credentials involved. */
if (wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Run only in the isolated local WordPress');
}
ob_start(); // Keep HTTP callbacks testable before any CLI output is flushed.
final class RegistrationRedirect extends Exception
{
    public function __construct(public string $location) { parent::__construct('redirect'); }
}
final class RegistrationDenied extends Exception {}
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks) {
    if (!$ok) throw new RuntimeException('FAILED: ' . $label);
    ++$checks;
    echo 'PASS ' . $label . "\n";
};
$keys = ['dzen_chat_credentials', 'dzen_chat_verified', 'dzen_chat_widget', 'dzen_chat_widgets',
    'dzen_chat_reconcile_cursor', 'dzen_chat_queue_error'];
$saved = [];
foreach ($keys as $key) $saved[$key] = get_option($key, null);
$user = get_current_user_id();
$savedGet = $_GET;
$savedRequest = $_REQUEST;
$savedCookie = $_COOKIE;
$localHome = 'https://wordpress-registration.example/site/';
$localAdmin = 'https://wordpress-registration.example/site';
$homeFilter = static function () use (&$localHome) { return $localHome; };
$adminFilter = static function () use (&$localAdmin) { return $localAdmin; };
add_filter('option_home', $homeFilter);
add_filter('option_siteurl', $adminFilter);
$capture = static function ($url) { throw new RegistrationRedirect($url); };
add_filter('wp_redirect', $capture, -1000);
$deny = static fn () => static function () { throw new RegistrationDenied('denied'); };
add_filter('wp_die_handler', $deny);
$redirect = static function (callable $action): string {
    try { $action(); } catch (RegistrationRedirect $r) { return $r->location; }
    throw new RuntimeException('Expected a redirect');
};
$admin = get_user_by('login', 'dzen_test');
if (!$admin) throw new RuntimeException('Install the fixture with the dzen_test administrator first');
wp_set_current_user($admin->ID);
$nonce = static function (string $action) {
    $_REQUEST['_wpnonce'] = wp_create_nonce($action);
};
$credentials = new DzenChat\Credentials();
$api = new DzenChat\Api($credentials);
$connection = new DzenChat\Connection($credentials, $api);
$calls = [];
$mode = 'success';
$payload = ['client_id' => str_repeat('c', 43), 'client_secret' => str_repeat('s', 64), 'site_source_id' => 'source1234'];
$transport = static function ($pre, $args, $url) use (&$calls, &$mode, $payload) {
    $calls[] = ['url' => $url, 'args' => $args];
    if ($url !== DzenChat\Api::origin() . '/auth/exchange/') {
        throw new RuntimeException('Unexpected management API request');
    }
    if ($mode === 'network') return new WP_Error('test_network', 'Synthetic transport error');
    $body = $mode === 'invalid_json' ? 'not-json' : wp_json_encode($mode === 'missing_source' ? array_diff_key($payload, ['site_source_id' => true]) : $payload);
    return ['headers' => [], 'response' => ['code' => $mode === 'redirect' ? 302 : ($mode === 'expired_code' ? 400 : 200)],
        'body' => $body, 'cookies' => [], 'filename' => null];
};
add_filter('pre_http_request', $transport, 1, 3);
$begin = static function () use ($connection, $redirect, $nonce): array {
    $nonce('dzen_chat_connect');
    $url = $redirect([$connection, 'start']);
    parse_str(wp_parse_url($url, PHP_URL_QUERY), $query);
    return [$url, $query];
};
$finish = static function (array $query) use ($connection, $redirect): string {
    $_GET = ['state' => $query['state'], 'code' => str_repeat('x', 43)];
    return $redirect([$connection, 'callback']);
};
$post = 0;
try {
    [$url, $query] = $begin();
    $check(str_starts_with($url, DzenChat\Api::origin() . '/auth/add?') && $query['type'] === 'wordpress', 'consent targets implemented auth/add');
    $check(array_keys($query) === ['type', 'site_url', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method'], 'registration sends exact implemented parameters');
    $check($query['site_url'] === $localHome && $query['redirect_uri'] === $localAdmin . '/wp-admin/admin-post.php?action=dzen_chat_authorize', 'site prefix and callback preserved');
    $pending = 'dzen_chat_auth_' . $admin->ID;
    $attempt = $credentials->decrypt(get_transient($pending));
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $attempt['verifier'], true)), '+/', '-_'), '=');
    $check($query['code_challenge_method'] === 'S256' && hash_equals($challenge, $query['code_challenge']), 'PKCE binds consent to exchange');
    $check(!str_contains(get_transient($pending), $attempt['verifier']), 'pending verifier encrypted');
    $check($attempt['expires'] > time() + 1200, 'attempt allows consent TTL plus code return');
    update_option('dzen_chat_verified', true, false);
    $result = $finish($query);
    $check(str_contains($result, 'notice=registered') && !str_contains($result, 'code='), 'callback strips code and acknowledges authorization');
    $check(count($calls) === 1 && $calls[0]['args']['method'] === 'POST', 'one exchange without follow-up management status');
    $sent = json_decode($calls[0]['args']['body'], true);
    $check($sent === ['code' => str_repeat('x', 43), 'code_verifier' => $attempt['verifier'], 'redirect_uri' => $query['redirect_uri']], 'exchange sends exact JSON contract');
    $check(($calls[0]['args']['headers']['Content-Type'] ?? '') === 'application/json'
        && !isset($calls[0]['args']['headers']['X-Dzen-Signature']) && $calls[0]['args']['cookies'] === [], 'exchange sends no HMAC or browser cookies');
    $check($calls[0]['args']['redirection'] === 0 && $calls[0]['args']['sslverify'] === true, 'exchange requires TLS verification and never follows redirects');
    $stored = $credentials->get();
    $check($stored['client_id'] === $payload['client_id'] && $stored['site_source_id'] === $payload['site_source_id']
        && $stored['site_url'] === $localHome && $stored['api_origin'] === DzenChat\Api::origin(), 'actual credentials and source bound to site and service');
    $check(!isset($stored['project_id'], $stored['integration_id']) && $credentials->registrationOnly(), 'no invented project or integration identifiers');
    $check(!str_contains(get_option('dzen_chat_credentials'), $payload['client_secret']), 'new credentials encrypted at rest');
    $check(!get_option('dzen_chat_verified') && get_transient('dzen_chat_status') === false, 'registration does not imply billing or management capabilities');
    $replay = $finish($query);
    $check(str_contains($replay, 'notice=authorization_failed') && count($calls) === 1, 'callback cannot exchange twice');
    set_transient($pending, $credentials->encrypt($attempt), 300);
    $check(str_contains($finish($query), 'notice=authorization_failed') && count($calls) === 1, 'atomic claim rejects a concurrently read callback');
    $check($api->request('GET', '/widgets')->get_error_code() === 'dzen_api_pending' && count($calls) === 1, 'deferred management API never receives registration credentials');

    global $wpdb;
    [, $events] = DzenChat\Sync::tables();
    $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM $events");
    $post = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Registration scope test']);
    wp_update_post(['ID' => $post, 'post_content' => 'Updated']);
    wp_delete_post($post, true);
    $post = 0;
    $check((int) $wpdb->get_var("SELECT COUNT(*) FROM $events") === $before, 'registration alone does not queue indexing events');
    $_GET = ['page' => 'dzen-chat'];
    ob_start();
    (new DzenChat\Admin($credentials, $api, new DzenChat\Sync($credentials, $api)))->page();
    $html = ob_get_clean();
    $check(str_contains($html, 'Сайт авторизован') && !str_contains($html, $payload['client_secret']) && count($calls) === 1, 'connected admin renders without secrets or unimplemented API');

    foreach (['wrong_state', 'expired_attempt', 'different_session', 'different_service', 'invalid_code'] as $failure) {
        [, $q] = $begin();
        $attempt = $credentials->decrypt(get_transient($pending));
        if ($failure === 'wrong_state') $q['state'] = str_repeat('z', 64);
        if ($failure === 'expired_attempt') $attempt['expires'] = time() - 1;
        if ($failure === 'different_session') $attempt['session'] = str_repeat('0', 64);
        if ($failure === 'different_service') $attempt['api_origin'] = 'https://another-service.example';
        set_transient($pending, $credentials->encrypt($attempt), 300);
        if ($failure === 'invalid_code') {
            $_GET = ['state' => $q['state'], 'code' => 'invalid'];
            $r = $redirect([$connection, 'callback']);
        } else {
            $r = $finish($q);
        }
        $check(str_contains($r, 'notice=authorization_failed') && count($calls) === 1, $failure . ' rejected before exchange');
    }
    $previous = get_option('dzen_chat_credentials');
    foreach (['expired_code', 'network', 'redirect', 'invalid_json', 'missing_source'] as $mode) {
        [, $q] = $begin();
        $check(str_contains($finish($q), 'notice=authorization_failed') && get_option('dzen_chat_credentials') === $previous,
            $mode . ' does not overwrite saved credentials');
    }
    $mode = 'success';
    [, $q] = $begin();
    $_GET = ['state' => $q['state'], 'error' => 'access_denied'];
    $before = count($calls);
    $check(str_contains($redirect([$connection, 'callback']), 'notice=cancelled') && count($calls) === $before, 'cancel returns without exchange');
    $_REQUEST['_wpnonce'] = 'invalid';
    try { $connection->start(); $check(false, 'invalid nonce'); } catch (RegistrationDenied) { $check(true, 'invalid connect nonce denied'); }
    wp_set_current_user(0);
    try { $connection->callback(); $check(false, 'guest callback'); } catch (RegistrationDenied) { $check(true, 'guest cannot exchange a code'); }
    wp_set_current_user($admin->ID);
    $localHome = 'http://wordpress-registration.example/site/';
    $nonce('dzen_chat_connect');
    try { $connection->start(); $check(false, 'HTTPS required'); } catch (RegistrationDenied) { $check(true, 'HTTP site denied before consent'); }
    $localHome = 'https://wordpress-registration.example/site/';
    $localAdmin = 'https://wordpress-registration.example/other';
    $nonce('dzen_chat_connect');
    try { $connection->start(); $check(false, 'callback prefix'); } catch (RegistrationDenied) { $check(true, 'callback outside site prefix denied'); }
    $localAdmin = 'https://wordpress-registration.example/site';
    $nonce('dzen_chat_disconnect');
    $check(str_contains($redirect([$connection, 'disconnect']), 'notice=credentials_removed') && !$credentials->exists()
        && count($calls) === $before, 'explicit local key removal makes no remote revocation claim');
} finally {
    remove_filter('pre_http_request', $transport, 1);
    remove_filter('wp_redirect', $capture, -1000);
    remove_filter('wp_die_handler', $deny);
    remove_filter('option_home', $homeFilter);
    remove_filter('option_siteurl', $adminFilter);
    foreach ($saved as $key => $value) $value === null ? delete_option($key) : update_option($key, $value, false);
    delete_transient('dzen_chat_auth_' . $admin->ID);
    $_GET = $savedGet;
    $_REQUEST = $savedRequest;
    $_COOKIE = $savedCookie;
    wp_set_current_user($user);
    if ($post) wp_delete_post($post, true);
}
echo "Registration checks passed: $checks\n";
ob_end_flush();
