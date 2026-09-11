<?php
/** Calendar boundaries and date rendering on the isolated WordPress fixture. */
require __DIR__ . '/setup.php';

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks) {
    if (!$ok) throw new RuntimeException('FAILED: ' . $label);
    ++$checks;
    echo 'PASS ' . $label . "\n";
};
$options = [];
foreach (['timezone_string', 'gmt_offset', 'date_format', 'time_format'] as $key) $options[$key] = get_option($key, null);
$oldGet = $_GET;
$oldUser = get_current_user_id();
$switched = switch_to_locale('en_US');
$credentials = new DzenChat\Credentials();
$api = new DzenChat\Api($credentials);
$admin = new DzenChat\Admin($credentials, $api, new DzenChat\Sync($credentials, $api));
$created = null;
$modify = static function ($pre, $args, $url) use (&$created) {
    if ($created !== null && wp_parse_url($url, PHP_URL_PATH) === '/api/chats') {
        $body = json_decode($pre['body'], true);
        foreach ($body['items'] as &$item) $item['created_at'] = $created;
        $pre['body'] = wp_json_encode($body);
    }
    return $pre;
};
add_filter('pre_http_request', $modify, 11, 3);
$render = static function (string $page, array $query = []) use ($admin) {
    $_GET = ['page' => $page] + $query;
    ob_start();
    try { $admin->page(); return ob_get_contents(); }
    finally { ob_end_clean(); }
};
try {
    wp_set_current_user(get_user_by('login', 'dzen_test')->ID);
    update_option('timezone_string', 'Europe/Sofia');
    update_option('date_format', 'd.m.Y');
    update_option('time_format', 'H:i');
    foreach (['2026-09-11T01:53:46.990760Z', '2026-09-11T01:53:46Z',
        '2026-09-11T07:23:46+05:30', '2026-09-10T18:53:46-07:00'] as $date) {
        $check(DzenChat\Dates::format($date) === '11.09.2026 04:53', 'same instant uses site timezone: ' . $date);
    }
    $check(DzenChat\Dates::format('2026-03-29T00:30:00Z') === '29.03.2026 02:30'
        && DzenChat\Dates::format('2026-03-29T01:30:00Z') === '29.03.2026 04:30', 'spring daylight-saving transition uses the historical offset');
    $check(DzenChat\Dates::format('2026-10-25T00:30:00Z') === '25.10.2026 03:30'
        && DzenChat\Dates::format('2026-10-25T01:30:00Z') === '25.10.2026 03:30', 'autumn daylight-saving transition uses the historical offset');
    $check(DzenChat\Dates::day('2026-09-10T22:30:00Z') === '2026-09-11', 'filter day rolls forward in the site timezone');
    foreach ([null, '', false, [], 0, 'tomorrow', '<script>bad date</script>',
        '2026-09-11T01:53:46', '2026-02-30T01:00:00Z', '2026-09-11T25:00:00Z',
        '2026-09-11T01:00:00+25:00', "2026-09-11T01:00:00Z\n"] as $date) {
        $check(DzenChat\Dates::format($date) === '—' && DzenChat\Dates::day($date) === null,
            'missing or invalid timestamp is not guessed: ' . wp_json_encode($date));
    }
    update_option('timezone_string', 'America/New_York');
    $check(DzenChat\Dates::format('2026-09-11T01:53:46Z') === '10.09.2026 21:53'
        && DzenChat\Dates::day('2026-09-11T01:53:46Z') === '2026-09-10', 'negative timezone rolls back to the previous day');
    update_option('timezone_string', '');
    update_option('gmt_offset', 5.5);
    $check(DzenChat\Dates::format('2026-09-11T01:53:46Z') === '11.09.2026 07:23', 'fixed fractional WordPress timezone is supported');
    update_option('timezone_string', 'UTC');
    $check(DzenChat\Dates::format('1970-01-01T00:00:00Z') === '01.01.1970 00:00', 'a real Unix epoch is not treated as a missing date');
    update_option('date_format', 'F j, Y');
    update_option('time_format', 'g:i a');
    $check(DzenChat\Dates::format('2026-09-11T01:53:46Z') === 'September 11, 2026 1:53 am', 'WordPress date and 12-hour time preferences are honored');
    update_option('timezone_string', 'Europe/Sofia');
    update_option('date_format', 'd.m.Y');
    update_option('time_format', 'H:i');
    foreach ([['dzen-chat', []], ['dzen-chat-documents', []], ['dzen-chat-documents', ['file' => 'file-one']],
        ['dzen-chat-history', []], ['dzen-chat-history', ['chat' => 'chat-one']]] as [$page, $query]) {
        $html = $render($page, $query);
        $check(str_contains($html, '11.09.2026 04:00') && !str_contains($html, '2026-09-11T01:00:'),
            'localized date renders on ' . $page . ' ' . wp_json_encode($query));
    }
    $created = '2026-09-10T22:30:00Z';
    $html = $render('dzen-chat-history', ['date_from' => '2026-09-11', 'date_to' => '2026-09-11']);
    $check(str_contains($html, 'chat-one') && str_contains($html, '11.09.2026 01:30')
        && str_contains($html, 'Europe/Sofia') && !str_contains($html, 'Created (UTC)'),
        'date range includes a chat on its displayed calendar day');
    $check(!str_contains($render('dzen-chat-history', ['date_to' => '2026-09-10']), 'chat-one'),
        'date range excludes the previous UTC calendar day');
    $created = 'not a date';
    $html = $render('dzen-chat-history');
    $check(str_contains($html, 'chat-one') && str_contains($html, '<td>—</td>') && !str_contains($html, $created),
        'invalid service timestamps render a placeholder without hiding an unfiltered chat');
    $check(!str_contains($render('dzen-chat-history', ['date_to' => '2026-09-11']), 'chat-one'),
        'unknown dates cannot match a calendar filter');
} finally {
    remove_filter('pre_http_request', $modify, 11);
    foreach ($options as $key => $value) $value === null ? delete_option($key) : update_option($key, $value);
    $_GET = $oldGet;
    wp_set_current_user($oldUser);
    if ($switched) restore_previous_locale();
}
echo "Date checks passed: $checks\n";
