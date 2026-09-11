<?php
/** Feedback display contract in the disposable fixture WordPress only. */
require __DIR__ . '/setup.php';
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks) {
    if (!$ok) throw new RuntimeException('FAILED: ' . $label);
    ++$checks;
    echo 'PASS ' . $label . "\n";
};
$credentials = new DzenChat\Credentials();
$api = new DzenChat\Api($credentials);
$admin = new DzenChat\Admin($credentials, $api, new DzenChat\Sync($credentials, $api));
$oldUser = get_current_user_id();
$oldGet = $_GET;
$oldDates = array_combine(['timezone_string', 'date_format', 'time_format'],
    array_map('get_option', ['timezone_string', 'date_format', 'time_format']));
wp_set_current_user(get_user_by('login', 'dzen_test')->ID);
$render = static function () use ($admin): string {
    $_GET = ['page' => 'dzen-chat-feedback'];
    ob_start();
    try { $admin->page(); return ob_get_contents(); }
    finally { ob_end_clean(); }
};
$items = $api->feedback();
if (is_wp_error($items)) throw new RuntimeException($items->get_error_message());
$chat = $items[0];
$override = null;
$requests = [];
$modify = static function ($pre, $args, $url) use (&$override, &$requests) {
    $requests[] = ['url' => $url, 'method' => $args['method']];
    if ($override !== null && wp_parse_url($url, PHP_URL_PATH) === '/api/feedback') {
        $pre['body'] = wp_json_encode(['items' => $override]);
    }
    return $pre;
};
$die = static fn () => static function ($message, $title = '', $args = []) {
    throw new RuntimeException((string) $message, $args['response'] ?? 0);
};
add_filter('pre_http_request', $modify, 11, 3);
add_filter('wp_die_handler', $die, PHP_INT_MAX);
try {
    update_option('timezone_string', 'Europe/Sofia');
    update_option('date_format', 'F j, Y');
    update_option('time_format', 'g:i a');
    $html = $render();
    $check($requests === [['url' => 'https://chat.dzen.dev/api/feedback?limit=100', 'method' => 'GET']],
        'feedback loads through one real API endpoint without fetching every conversation');
    $check(str_contains($html, 'Rating: 4 out of 5') && str_contains($html, '★★★★☆')
        && str_contains($html, 'Solved my problem') && str_contains($html, 'Quick answer')
        && str_contains($html, "I found the delivery details I needed.\nA helpful explanation."),
        'saved rating, reasons and multiline comment are displayed');
    $check(str_contains($html, 'September 11, 2026 4:05 am') && !str_contains($html, '2026-09-11T')
        && str_contains($html, 'Europe/Sofia'), 'submission time follows WordPress timezone and date settings');
    $check(str_contains($html, 'chat=chat-one') && str_contains($html, 'Open conversation in WordPress')
        && str_contains($html, 'href="https://chat.dzen.dev/projects/project-one/history/chat-one"')
        && str_contains($html, 'target="_blank" rel="noopener noreferrer"'), 'feedback links to the same chat in both admin interfaces');
    $check(str_contains($html, 'page=dzen-chat-feedback') && str_contains($html, 'Refresh list'),
        'refresh returns to the feedback list');
    $check(!str_contains($html, '<form') && !str_contains($html, 'fixture-secret') && !str_contains($html, 'Bearer '),
        'feedback offers no editing actions and never exposes credentials');
    $override = [array_replace($chat, ['visitor' => '<img src=x onerror=alert(1)>', 'feedback' => [
        'rating' => 1, 'selected' => ['<script>alert(1)</script>'],
        'text' => '<img src=x onerror=alert(1)>', 'submitted_at' => '<script>bad date</script>',
    ]])];
    $html = $render();
    $check(str_contains($html, '&lt;img') && str_contains($html, '&lt;script&gt;')
        && !str_contains($html, '<img') && !str_contains($html, '<script>'),
        'comments, reasons and visitor IDs are escaped');
    $check(str_contains($html, 'Submitted: —') && !str_contains($html, 'bad date'), 'invalid timestamps are not displayed as raw text');
    $override = [array_replace($chat, ['feedback' => ['rating' => 5]])];
    $html = $render();
    $check(str_contains($html, 'Rating: 5 out of 5') && str_contains($html, 'No comment provided.')
        && str_contains($html, 'Submitted: —') && !str_contains($html, 'September 11'),
        'older rating-only feedback does not invent a comment or submission date');
    foreach ([['rating' => 0], ['rating' => 6], ['rating' => '4'], ['rating' => true],
        ['selected' => 'invalid'], ['selected' => [null]], ['selected' => ['']],
        ['text' => ['invalid']], ['submitted_at' => ['invalid']]] as $change) {
        $override = [array_replace($chat, ['feedback' => array_replace($chat['feedback'], $change)])];
        $check(is_wp_error($api->feedback()), 'malformed feedback rejected: ' . wp_json_encode($change));
    }
    foreach ([null, [], 'invalid'] as $feedback) {
        $override = [array_replace($chat, ['feedback' => $feedback])];
        $check(is_wp_error($api->feedback()), 'missing or invalid feedback is not interpreted as a rating');
    }
    foreach (['javascript:alert(1)', 'https://evil.test/projects/project-one/history/chat-one',
        'https://chat.dzen.dev/projects/project-one/history/other-chat',
        'https://chat.dzen.dev/projects/project-one/history/chat-one?token=secret'] as $url) {
        $override = [array_replace($chat, ['details_url' => $url])];
        $html = $render();
        $check(str_contains($html, 'invalid feedback') && !str_contains($html, '<article'),
            'unsafe or mismatched conversation links are not rendered');
    }
    $override = [];
    $check(str_contains($render(), 'No feedback yet.'), 'empty feedback has a clear empty state');
    $override = null;
    foreach (['network', 'revoked', 'forbidden', 'malformed_list'] as $scenario) {
        update_option('dzen_fixture_scenario', $scenario, false);
        $html = $render();
        $check(str_contains($html, 'notice-error') && !str_contains($html, '<article')
            && !str_contains($html, 'No feedback yet.'), 'failed feedback requests are distinct from an empty list: ' . $scenario);
    }
    update_option('dzen_fixture_scenario', 'normal', false);
    $check(!array_filter($requests, static fn ($request) => $request['method'] !== 'GET'), 'all feedback requests are read-only');
    $before = count($requests);
    wp_set_current_user(get_user_by('login', 'dzen_subscriber')->ID);
    try { $render(); $check(false, 'subscriber blocked'); }
    catch (RuntimeException $error) {
        $check($error->getCode() === 403 && count($requests) === $before, 'non-admins cannot request or view feedback');
    }
} finally {
    remove_filter('pre_http_request', $modify, 11);
    remove_filter('wp_die_handler', $die, PHP_INT_MAX);
    update_option('dzen_fixture_scenario', 'normal', false);
    foreach ($oldDates as $key => $value) update_option($key, $value);
    wp_set_current_user($oldUser);
    $_GET = $oldGet;
}
echo "Feedback checks passed: $checks\n";
