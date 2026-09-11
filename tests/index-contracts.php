<?php
/** Run only in the disposable API fixture WordPress. */
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
wp_set_current_user(get_user_by('login', 'dzen_test')->ID);
$render = static function () use ($admin) {
    $_GET = ['page' => 'dzen-chat-documents', 'source_id' => 'another-source', 'document' => 'another-page'];
    ob_start();
    $admin->page();
    return ob_get_clean();
};
$requests = [];
$observe = static function ($pre, $args, $url) use (&$requests) {
    $requests[] = $url;
    return $pre;
};
add_filter('pre_http_request', $observe, 9, 3);
$status = $api->sourceStatus('source-one');
$check(!is_wp_error($status) && $status['total'] === 115 && $status['counts']['ready'] === 105,
    'aggregate counts are not limited to 100 documents');
$requests = [];
$html = $render();
$check($requests === ['https://chat.dzen.dev/api/sources/source-one/status'],
    'Index requests only the connected source status and ignores query overrides');
$check(str_contains($html, '115 pages in this source.') && substr_count($html, '<dt>') === 5,
    'Index includes the total and five status counters');
$check(str_contains($html, 'https://chat.dzen.dev/projects/project-one/sources/source-one')
    && str_contains($html, 'Refresh status') && !str_contains($html, '<table') && !str_contains($html, 'name="q"'),
    'Index contains the details link and refresh without a document table or filters');
$check(!str_contains($html, 'fixture-secret') && !str_contains($html, 'Bearer '), 'Index never exposes credentials');

$override = null;
$modify = static function ($pre, $args, $url) use (&$override) {
    if ($override !== null && str_ends_with($url, '/sources/source-one/status')) {
        $pre['body'] = wp_json_encode($override);
    }
    return $pre;
};
add_filter('pre_http_request', $modify, 11, 3);
foreach ([-1, 0.5, '105', null] as $bad) {
    $override = $status;
    $override['counts']['ready'] = $bad;
    $check(is_wp_error($api->sourceStatus('source-one')), 'invalid count rejected: ' . wp_json_encode($bad));
}
foreach (['id' => 'foreign-source', 'total' => 999, 'is_paused' => 'false'] as $key => $bad) {
    $override = array_replace($status, [$key => $bad]);
    $check(is_wp_error($api->sourceStatus('source-one')), 'invalid status field rejected: ' . $key);
}
foreach (['https://evil.example/projects/p/sources/source-one',
    'https://chat.dzen.dev/projects/p/sources/foreign-source',
    'https://chat.dzen.dev/projects/p/sources/source-one?token=secret', 'javascript:alert(1)'] as $url) {
    $override = array_replace($status, ['details_url' => $url]);
    $check(is_wp_error($api->sourceStatus('source-one')), 'unsafe or mismatched detail link rejected');
}
$check(!str_contains($render(), 'dzen-index-bar'), 'invalid response does not render misleading progress');
$override = array_replace($status, ['counts' => array_fill_keys(array_keys($status['counts']), 0), 'total' => 0]);
$html = $render();
$check(str_contains($html, 'No pages have been discovered yet.') && !str_contains($html, 'flex-grow:'), 'empty source has a neutral bar');
$override['counts']['excluded'] = 4;
$override['total'] = 4;
$html = $render();
$check(str_contains($html, 'All discovered pages are excluded') && !str_contains($html, 'flex-grow:'), 'excluded-only source is not shown as completed');
$override = array_replace($status, ['is_paused' => true, 'blocked_reason' => 'http_403', 'title' => '<script>alert(1)</script>']);
$html = $render();
$check(str_contains($html, 'Updates paused') && str_contains($html, 'Indexing is restricted.'), 'paused and restricted source states are visible');
$check(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'source title is escaped');
$override = null;
foreach (['network', 'revoked', 'forbidden'] as $scenario) {
    update_option('dzen_fixture_scenario', $scenario, false);
    $check(!str_contains($render(), 'dzen-index-bar'), 'unavailable status does not show stale progress: ' . $scenario);
}
update_option('dzen_fixture_scenario', 'normal', false);
remove_filter('pre_http_request', $observe, 9);
remove_filter('pre_http_request', $modify, 11);
echo "Checks passed: $checks\n";
