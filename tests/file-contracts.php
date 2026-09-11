<?php
/** Safe text uploads and the implemented Files API on the isolated local fixture. */
require __DIR__ . '/setup.php';
ob_start();
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks) {
    if (!$ok) throw new RuntimeException('FAILED: ' . $label);
    ++$checks;
    echo 'PASS ' . $label . "\n";
};
$originalFiles = get_option('dzen_fixture_files', null);
$originalUser = get_current_user_id();
$originalGet = $_GET;
$originalPost = $_POST;
$originalRequest = $_REQUEST;
$api = new DzenChat\Api(new DzenChat\Credentials());
$files = new DzenChat\Files($api);
$requests = [];
$observe = static function ($pre, $args, $url) use (&$requests) { $requests[] = ['args' => $args, 'url' => $url]; return $pre; };
$override = null;
$modify = static function ($pre, $args, $url) use (&$override) {
    if ($override && $url === 'https://chat.dzen.dev/api/files' && $args['method'] === 'POST') {
        $body = json_decode($pre['body'], true);
        $pre['body'] = wp_json_encode(array_replace($body, $override));
    }
    return $pre;
};
add_filter('pre_http_request', $observe, 9, 3);
add_filter('pre_http_request', $modify, 11, 3);
final class DzenFileTestDenied extends RuntimeException {}
$die = static fn () => static function ($message, $title = '', $args = []) {
    throw new DzenFileTestDenied((string) $message, (int) ($args['response'] ?? 500));
};
add_filter('wp_die_handler', $die, PHP_INT_MAX);
try {
    foreach (['guide.txt', 'guide.md', 'guide.markdown', 'Guide.MD'] as $name) {
        $prepared = DzenChat\Files::prepare($name, "# Guide\nДобро пожаловать! It's safe. <script>example</script>");
        $check(!is_wp_error($prepared) && $prepared['name'] === $name && str_contains($prepared['content'], "It's safe."),
            'supported text remains intact: ' . $name);
    }
    $check(DzenChat\Files::prepare('bom.txt', "\xEF\xBB\xBFText")['content'] === 'Text', 'UTF-8 BOM is removed');
    foreach ([['file.pdf', '%PDF-1.0'], ['file.php', '<?php echo 1;'], ['file.txt', "a\0b"],
        ['file.md', "\xFF\xFEtext"], ['empty.txt', " \n\t"], ['guide', 'Text'],
        [str_repeat('a', 260) . '.txt', 'Text'], ['large.txt', str_repeat('x', DzenChat\Files::uploadLimit() + 1)]] as [$name, $text]) {
        $check(is_wp_error(DzenChat\Files::prepare($name, $text)), 'invalid document is rejected: ' . substr($name, 0, 20));
    }
    $check(is_wp_error(DzenChat\Files::readUpload(null)), 'missing upload is rejected');
    $check(DzenChat\Files::readUpload(['error' => UPLOAD_ERR_FORM_SIZE])->get_error_code() === 'file_size', 'PHP size errors are explained');
    $check(is_wp_error(DzenChat\Files::readUpload(['error' => UPLOAD_ERR_OK, 'name' => 'secret.txt', 'tmp_name' => ABSPATH . 'wp-config.php'])),
        'a supplied local path is never treated as an HTTP upload');
    $document = DzenChat\Files::prepare('wordpress-guide.md', "# Guide\nТекст WordPress. <script>Example</script>");
    $file = $files->create($document);
    $request = end($requests);
    $check(!is_wp_error($file) && $request['url'] === 'https://chat.dzen.dev/api/files'
        && $request['args']['method'] === 'POST' && json_decode($request['args']['body'], true) === $document,
        'creation uses the implemented name/content JSON contract');
    $check($request['args']['sslverify'] === true && $request['args']['redirection'] === 0
        && isset($request['args']['headers']['Authorization']), 'upload uses the authenticated verified transport');
    $check(DzenChat\Files::indexStatus($file) === 'processing', 'accepted document is processing, not ready');
    $check($files->get($file['id'])['content'] === $document['content'] && count($files->all()) === 2,
        'uploaded document is available in the list and detail API');
    $override = ['status' => 'ready', 'status_error' => 'too_big'];
    $failed = $files->create($document);
    $check(!is_wp_error($failed) && DzenChat\Files::indexStatus($failed) === 'errors', 'server indexing error takes precedence over ready');
    $override = ['content' => 'Another document'];
    $check(is_wp_error($files->create($document)), 'mismatched acknowledgment is never reported as a confirmed upload');
    $override = ['id' => '../unsafe'];
    $check(is_wp_error($files->create($document)), 'unsafe document ID is rejected');
    $override = null;
    wp_set_current_user(get_user_by('login', 'dzen_test')->ID);
    $credentials = new DzenChat\Credentials();
    $admin = new DzenChat\Admin($credentials, $api, new DzenChat\Sync($credentials, $api));
    $_GET = ['page' => 'dzen-chat-documents', 'file' => $file['id']];
    ob_start(); $admin->page(); $html = ob_get_clean();
    $check(str_contains($html, '&lt;script&gt;Example&lt;/script&gt;') && !str_contains($html, '<script>')
        && !str_contains($html, 'fixture-secret'), 'document detail escapes text and never exposes credentials');
    $check(str_contains($html, '/projects/project-one/files/' . $file['id']) && str_contains($html, 'Back to Index'),
        'document can be opened inside WordPress and in the matching Dzen Chat editor');
    foreach (['bad_nonce', 'subscriber', 'missing_file'] as $case) {
        wp_set_current_user(get_user_by('login', $case === 'subscriber' ? 'dzen_subscriber' : 'dzen_test')->ID);
        $_POST = ['operation' => 'file_upload', '_wpnonce' => $case === 'bad_nonce' ? 'wrong' : wp_create_nonce('dzen_chat_action')];
        $_REQUEST = $_POST;
        $before = count($requests);
        try { $admin->action(); throw new RuntimeException('Expected upload rejection'); }
        catch (DzenFileTestDenied $error) { $check(count($requests) === $before, 'invalid upload makes no service request: ' . $case); }
    }
} finally {
    $originalFiles === null ? delete_option('dzen_fixture_files') : update_option('dzen_fixture_files', $originalFiles, false);
    wp_set_current_user($originalUser);
    $_GET = $originalGet; $_POST = $originalPost; $_REQUEST = $originalRequest;
    remove_filter('pre_http_request', $observe, 9);
    remove_filter('pre_http_request', $modify, 11);
    remove_filter('wp_die_handler', $die, PHP_INT_MAX);
    ob_end_flush();
}
echo "Document checks passed: $checks\n";
