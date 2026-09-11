<?php
/** Locale and rendered UI checks in the isolated WordPress fixture. */
require __DIR__ . '/setup.php';
require_once ABSPATH . 'wp-includes/pomo/po.php';
require_once ABSPATH . 'wp-includes/pomo/mo.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks) {
    if (!$ok) throw new RuntimeException('FAILED: ' . $label);
    ++$checks;
    echo 'PASS ' . $label . "\n";
};
$root = dirname(__DIR__);
$template = new PO();
$russian = new PO();
$compiled = new MO();
$check($template->import_from_file($root . '/languages/dzen-chat.pot')
    && $russian->import_from_file($root . '/languages/dzen-chat-ru_RU.po')
    && $compiled->import_from_file($root . '/languages/dzen-chat-ru_RU.mo'), 'all translation catalogs parse');
$check(count($template->entries) === count($russian->entries)
    && count($russian->entries) === count($compiled->entries), 'template, source translation and runtime catalog have equal coverage');
$complete = true;
foreach ($template->entries as $key => $entry) {
    $translation = $russian->entries[$key] ?? null;
    $complete = $complete && !preg_match('/[А-Яа-яЁё]/u', $entry->singular)
        && $translation && !in_array('fuzzy', $translation->flags, true)
        && !empty($translation->translations[0])
        && ($compiled->entries[$key]->translations ?? []) === $translation->translations;
}
$check($complete, 'every English template entry has a reviewed matching Russian runtime translation');

// Supply locale availability without downloading core language packs in CI.
// WordPress itself still performs all domain loading and locale switching.
$available = static fn () => ['ru_RU', 'de_DE'];
add_filter('get_available_languages', $available);
$switcher = new WP_Locale_Switcher();
remove_filter('get_available_languages', $available);
$switcher->init();
$oldUser = get_current_user_id();
$oldScreen = $GLOBALS['current_screen'] ?? null;
$oldGet = $_GET;
$user = get_user_by('login', 'dzen_test');
if (!$user) throw new RuntimeException('The dzen_test administrator is required');
$oldUserLocale = get_user_meta($user->ID, 'locale', true);
$post = 0;
$capture = static function (callable $render): string {
    ob_start();
    try { $render(); return ob_get_contents(); }
    finally { ob_end_clean(); }
};
try {
    wp_set_current_user($user->ID);
    update_user_meta($user->ID, 'locale', 'en_US');
    set_current_screen('toplevel_page_dzen-chat');
    $credentials = new DzenChat\Credentials();
    $api = new DzenChat\Api($credentials);
    $admin = new DzenChat\Admin($credentials, $api, new DzenChat\Sync($credentials, $api));
    $post = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Locale test']);

    $screens = [
        'dzen-chat' => ['This site is connected to Dzen Chat', 'Сайт авторизован в Dzen Chat'],
        'dzen-chat-widgets' => ['Create widget', 'Создать виджет'],
        'dzen-chat-documents' => ['Refresh status', 'Обновить статус'],
        'dzen-chat-history' => ['Hide empty conversations', 'Скрывать пустые диалоги'],
    ];
    foreach (['en_US' => 0, 'ru_RU' => 1] as $locale => $index) {
        $switched = $switcher->switch_to_locale($locale);
        $check(determine_locale() === $locale, 'WordPress selects ' . $locale);
        foreach ($screens as $slug => $labels) {
            $_GET = ['page' => $slug];
            $html = $capture([$admin, 'page']);
            $check(str_contains($html, $labels[$index]) && !str_contains($html, $labels[1 - $index]),
                'rendered ' . $slug . ' uses ' . $locale);
        }
        $html = $capture(static fn () => (new DzenChat\Plugin())->metaBox(get_post($post)));
        $invitation = $capture([DzenChat\Plugin::class, 'notFoundInvitation']);
        $check(str_contains($invitation, $index ? 'Начать диалог' : 'Start a conversation')
            && str_contains($invitation, $index ? 'Закрыть приглашение в чат' : 'Dismiss chat invitation'),
            'public 404 invitation uses ' . $locale);
        $_GET = ['page' => 'dzen-chat-widgets'];
        $widgetSettings = $capture([$admin, 'page']);
        $check(str_contains($widgetSettings, $index ? 'Показывать чат на страницах 404' : 'Show chat on 404 pages'),
            '404 setting uses ' . $locale);
        $_GET = ['page' => 'dzen-chat-documents'];
        $indexHtml = $capture([$admin, 'page']);
        $check(str_contains($indexHtml, $index ? 'Загрузить для индексации' : 'Upload for indexing'),
            'document upload form uses ' . $locale);
        $check(str_contains($html, $index ? 'Не показывать виджет' : 'Hide widget'), 'page settings use ' . $locale);
        $check(str_contains($html, $index ? 'Статус индексации' : 'Indexing status'), 'editor indexing panel uses ' . $locale);
        $check(str_contains($html, $index ? 'Удалить из индекса и заблокировать обновления' : 'Remove from index and block updates'), 'editor exclusion button uses ' . $locale);
        $check(str_contains($html, $index ? 'Триггеры страницы' : 'Page triggers')
            && str_contains($html, $index ? 'Открыть страницу в Dzen Chat' : 'Open page in Dzen Chat'), 'editor trigger panel uses ' . $locale);
        $pageStatus = (new DzenChat\PageIndex($credentials, $api))->status(get_post($post));
        $check($pageStatus['label'] === ($index ? 'Нет публичного доступа' : 'Not public'), 'editor draft status uses ' . $locale);
        $error = DzenChat\Admin::filters(['date_from' => 'invalid']);
        $check($error->get_error_message() === ($index ? 'Укажите дату в формате ГГГГ-ММ-ДД.' : 'Enter a date in YYYY-MM-DD format.'),
            'validation errors use ' . $locale);
        update_option('dzen_fixture_scenario', 'revoked', false);
        $error = $api->request('GET', '/sources');
        $check($error->get_error_message() === ($index ? 'Нужно повторно подключить сайт к Dzen Chat.' : 'Reconnect the site to Dzen Chat.'),
            'API errors use ' . $locale);
        update_option('dzen_fixture_scenario', 'normal', false);
        $_GET = ['page' => 'dzen-chat-history', 'chat' => 'chat-one'];
        $html = $capture([$admin, 'page']);
        $check(str_contains($html, $index ? 'Подсказки' : 'Suggestions')
            && str_contains($html, $index ? 'Выбрано посетителем' : 'Selected by visitor')
            && str_contains($html, $index ? 'Открыть диалог в Dzen Chat' : 'Open conversation in Dzen Chat'),
            'conversation suggestions and details link use ' . $locale);
        $check(str_contains($html, 'Можно ли изменить адрес?') && str_contains($html, '&lt;script&gt;')
            && !str_contains($html, '<script>'), 'user content is preserved and escaped in ' . $locale);
        if ($switched) $switcher->restore_previous_locale();
    }
    $check($switcher->switch_to_locale('de_DE') && __('Widgets', 'dzen-chat') === 'Widgets',
        'a locale without a translation falls back to English');
    $switcher->restore_previous_locale();
    update_user_meta($user->ID, 'locale', 'ru_RU');
    $check(get_locale() === 'en_US' && determine_locale() === 'ru_RU',
        'a Russian admin profile overrides an English site language');
    $check($switcher->switch_to_locale('en_US') && __('Widgets', 'dzen-chat') === 'Widgets',
        'English can be selected after Russian within one request');
    $switcher->restore_previous_locale();
} finally {
    $switcher->restore_current_locale();
    remove_filter('locale', [$switcher, 'filter_locale']);
    remove_filter('determine_locale', [$switcher, 'filter_locale']);
    update_user_meta($user->ID, 'locale', $oldUserLocale);
    wp_set_current_user($oldUser);
    $GLOBALS['current_screen'] = $oldScreen;
    $_GET = $oldGet;
    update_option('dzen_fixture_scenario', 'normal', false);
    if ($post) wp_delete_post($post, true);
}
echo "Internationalization checks passed: $checks\n";
