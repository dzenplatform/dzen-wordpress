<?php
namespace DzenChat;

defined('ABSPATH') || exit;

final class Admin
{
    public function __construct(private Credentials $credentials, private Api $api, private Sync $sync) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_dzen_chat_visibility', [$this, 'visibility']);
        add_action('admin_post_dzen_chat_action', [$this, 'action']);
        add_action('admin_enqueue_scripts', function ($hook) {
            if (str_contains($hook, 'dzen-chat')) {
                wp_enqueue_style('dzen-chat-admin', plugins_url('assets/admin.css', DZEN_CHAT_FILE), [], DZEN_CHAT_VERSION);
            }
        });
    }

    public function menu(): void
    {
        add_menu_page('Dzen Chat', 'Dzen Chat', 'manage_options', 'dzen-chat', [$this, 'page'], 'dashicons-format-chat', 58);
        foreach (['dzen-chat' => __('Обзор', 'dzen-chat'), 'dzen-chat-widgets' => __('Виджеты', 'dzen-chat'),
            'dzen-chat-documents' => __('Индекс и триггеры', 'dzen-chat'), 'dzen-chat-history' => __('Диалоги', 'dzen-chat'),
            'dzen-chat-sources' => __('Источники', 'dzen-chat')] as $slug => $title) {
            add_submenu_page('dzen-chat', $title, $title, 'manage_options', $slug, [$this, 'page']);
        }
    }

    public static function url(string $page = 'dzen-chat-history', array $query = []): string
    {
        return add_query_arg(['page' => $page] + $query, admin_url('admin.php'));
    }

    private static function input(string $key, array $source): string
    {
        return isset($source[$key]) && is_string($source[$key]) ? sanitize_text_field(wp_unslash($source[$key])) : '';
    }

    public static function filters(array $input): array|\WP_Error
    {
        $result = ['limit' => 20, 'visibility' => self::input('visibility', $input) ?: 'visible'];
        if (!in_array($result['visibility'], ['visible', 'hidden', 'all'], true)) {
            return new \WP_Error('filter', __('Выберите корректный фильтр видимости.', 'dzen-chat'));
        }
        foreach (['q' => 200, 'widget_id' => 128, 'cursor' => 512, 'date_from' => 10, 'date_to' => 10] as $key => $max) {
            $value = self::input($key, $input);
            if (strlen($value) > $max) {
                return new \WP_Error('filter', __('Слишком длинное значение фильтра.', 'dzen-chat'));
            }
            if ($value !== '') {
                if (str_starts_with($key, 'date_')) {
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
                    if (!$date || $date->format('Y-m-d') !== $value) {
                        return new \WP_Error('filter', __('Укажите дату в формате ГГГГ-ММ-ДД.', 'dzen-chat'));
                    }
                }
                $result[$key] = $value;
            }
        }
        if (isset($result['date_from'], $result['date_to']) && $result['date_from'] > $result['date_to']) {
            return new \WP_Error('filter', __('Начальная дата должна быть не позже конечной.', 'dzen-chat'));
        }
        return $result;
    }

    public static function publicUrl(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $parts = wp_parse_url($value);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        return esc_url($value, ['http', 'https']);
    }

    private function error(\WP_Error $error): void
    {
        echo '<div class="notice notice-error"><p>' . esc_html($error->get_error_message()) . '</p></div>';
    }

    private function form(string $action, string $nonce, array $fields, string $label): void
    {
        echo '<form method="post" class="dzen-inline" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        wp_nonce_field($nonce);
        foreach ($fields as $key => $value) {
            if (is_scalar($value)) {
                echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr((string) $value) . '">';
            }
        }
        echo '<button class="button" type="submit">' . esc_html($label) . '</button></form>';
    }

    public function page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'dzen-chat'), '', ['response' => 403]);
        }
        nocache_headers();
        echo '<div class="wrap dzen-chat"><h1>Dzen Chat</h1>';
        $notices = [
            'connected' => __('Сайт подключён. Начальная сверка поставлена в очередь.', 'dzen-chat'),
            'disconnected' => __('Интеграция отключена.', 'dzen-chat'),
            'cancelled' => __('Подключение отменено.', 'dzen-chat'),
            'authorization_failed' => __('Подключение не завершено. Начните его заново из этой страницы.', 'dzen-chat'),
            'unverified' => __('Ключи сохранены, но сервис ещё не подтвердил подключение. Нажмите «Проверить подключение».', 'dzen-chat'),
            'hidden' => __('Чат скрыт из основного списка. Переписка и биллинговая история сохранены в Dzen Chat.', 'dzen-chat'),
            'restored' => __('Чат возвращён в основной список.', 'dzen-chat'),
            'saved' => __('Изменение сохранено.', 'dzen-chat'),
            'queued' => __('Сверка и повторная обработка ошибок поставлены в очередь.', 'dzen-chat'),
        ];
        $notice = self::input('notice', $_GET);
        if (isset($notices[$notice])) {
            echo '<div class="notice notice-info"><p>' . esc_html($notices[$notice]) . '</p></div>';
        }
        if (!$this->credentials->exists()) {
            echo '<p>' . esc_html__('Подключите сайт к существующему или новому проекту Dzen Chat.', 'dzen-chat') . '</p>';
            $this->form('dzen_chat_connect', 'dzen_chat_connect', [], __('Подключить Dzen Chat', 'dzen-chat'));
            echo '</div>';
            return;
        }
        $page = self::input('page', $_GET);
        match ($page) {
            'dzen-chat-history' => $this->history(),
            'dzen-chat-widgets' => $this->widgets(),
            'dzen-chat-documents' => $this->documents(),
            'dzen-chat-sources' => $this->sources(),
            default => $this->overview(),
        };
        echo '</div>';
    }

    private function overview(): void
    {
        $status = $this->api->status();
        if (is_wp_error($status)) {
            $this->error($status);
        } else {
            echo '<h2>' . esc_html($status['project']['title']) . '</h2><p>'
                . esc_html__('Сайт:', 'dzen-chat') . ' ' . esc_html(Credentials::siteUrl()) . '</p>';
            $active = $status['integration']['status'] === 'active' && $status['project']['status'] === 'active';
            echo '<p><strong>' . esc_html($active ? __('Проект активен', 'dzen-chat') : __('Работа проекта ограничена', 'dzen-chat')) . '</strong></p>';
            if (!$active) {
                echo '<p>' . esc_html__('Проверьте состояние проекта и оплату в Dzen Chat. Отправка контента зависит от доступных операций.', 'dzen-chat') . '</p>';
            }
            echo '<a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url(Api::origin() . '/projects/' . Api::segment($status['project']['id']) . '/pages') . '">' . esc_html__('Открыть проект в Dzen Chat', 'dzen-chat') . '</a> ';
        }
        echo '<p><a class="button" href="' . esc_url(self::url('dzen-chat')) . '">' . esc_html__('Проверить подключение', 'dzen-chat') . '</a></p>';
        $this->form('dzen_chat_connect', 'dzen_chat_connect', [], __('Подключить заново', 'dzen-chat'));
        $this->form('dzen_chat_disconnect', 'dzen_chat_disconnect', [], __('Отключить интеграцию', 'dzen-chat'));
        echo '<h2>' . esc_html__('Синхронизация страниц и записей', 'dzen-chat') . '</h2>';
        global $wpdb;
        [, $events] = Sync::tables();
        $counts = is_wp_error($status) ? [] : $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) total FROM $events WHERE integration_id=%s GROUP BY status",
            $status['integration']['id']), ARRAY_A);
        $labels = ['pending' => 'Ожидает отправки', 'accepted' => 'Принято, обрабатывается', 'blocked' => 'Ожидает доступа', 'done' => 'Завершено', 'error' => 'Ошибка'];
        echo '<ul>';
        foreach ($counts as $row) {
            echo '<li>' . esc_html(($labels[$row['status']] ?? $row['status']) . ': ' . $row['total']) . '</li>';
        }
        echo '</ul><p>' . esc_html__('Товары не отправляются. Задания выполняет WP-Cron; на сайте без посещений нужен планировщик хостинга.', 'dzen-chat') . '</p>';
        if (get_option('dzen_chat_queue_error')) {
            $this->error(new \WP_Error('queue', __('Не удалось записать событие. Проверьте базу WordPress и запустите сверку.', 'dzen-chat')));
        }
        $this->form('dzen_chat_action', 'dzen_chat_action', ['operation' => 'reconcile'], __('Сверить контент и повторить ошибки', 'dzen-chat'));
    }

    private function history(): void
    {
        echo '<h2>' . esc_html__('Диалоги', 'dzen-chat') . '</h2><p>'
            . esc_html__('История хранится в Dzen Chat. Скрытие меняет общий список проекта; сообщения и биллинг сохраняются.', 'dzen-chat') . '</p>';
        $filters = self::filters($_GET);
        if (is_wp_error($filters)) {
            $this->error($filters);
            return;
        }
        $chatId = self::input('chat', $_GET);
        if ($chatId !== '') {
            $this->chat($chatId, $filters);
            return;
        }
        echo '<form method="get" class="dzen-filters"><input type="hidden" name="page" value="dzen-chat-history">';
        echo '<label>' . esc_html__('Поиск', 'dzen-chat') . '<input type="search" name="q" value="' . esc_attr($filters['q'] ?? '') . '" maxlength="200"></label>';
        echo '<label>' . esc_html__('Видимость', 'dzen-chat') . '<select name="visibility">';
        foreach (['visible' => __('Видимые', 'dzen-chat'), 'hidden' => __('Скрытые', 'dzen-chat'), 'all' => __('Все', 'dzen-chat')] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($filters['visibility'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        foreach (['date_from' => __('С даты (UTC)', 'dzen-chat'), 'date_to' => __('По дату (UTC)', 'dzen-chat')] as $key => $label) {
            echo '<label>' . esc_html($label) . '<input type="date" name="' . esc_attr($key) . '" value="' . esc_attr($filters[$key] ?? '') . '"></label>';
        }
        echo '<label>' . esc_html__('Виджет', 'dzen-chat') . '<select name="widget_id"><option value="">' . esc_html__('Все виджеты', 'dzen-chat') . '</option>';
        $widgets = get_option('dzen_chat_widgets', []);
        if (!$widgets) {
            $available = $this->api->request('GET', '/widgets', ['limit' => 100]);
            if (!is_wp_error($available) && isset($available['items'])) {
                foreach ($available['items'] as $widget) {
                    $widgets[(string) $widget['id']] = ['name' => $widget['name'] ?? $widget['id']];
                }
            }
        }
        foreach ($widgets as $widgetId => $widget) {
            echo '<option value="' . esc_attr($widgetId) . '"' . selected($filters['widget_id'] ?? '', $widgetId, false) . '>' . esc_html($widget['name']) . '</option>';
        }
        echo '</select></label>';
        echo '<button class="button button-primary">' . esc_html__('Применить', 'dzen-chat') . '</button></form>';
        $result = $this->api->request('GET', '/chats', $filters);
        if (!$this->listing($result)) {
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Диалог', 'dzen-chat') . '</th><th>' . esc_html__('Создан (UTC)', 'dzen-chat') . '</th><th>' . esc_html__('Сообщения', 'dzen-chat') . '</th><th>' . esc_html__('Действие', 'dzen-chat') . '</th></tr></thead><tbody>';
        foreach ($result['items'] as $chat) {
            echo '<tr><td><a href="' . esc_url(self::url('dzen-chat-history', $filters + ['chat' => (string) $chat['id']])) . '">' . esc_html($chat['title'] ?? $chat['id']) . '</a><p>' . esc_html($chat['preview'] ?? '') . '</p>';
            if (($chat['hidden'] ?? false) === true) {
                echo '<strong>' . esc_html__('Скрыт', 'dzen-chat') . '</strong>';
            }
            echo '</td><td>' . esc_html($chat['created_at'] ?? '') . '</td><td>' . esc_html((string) ($chat['message_count'] ?? '')) . '</td><td>';
            $this->visibilityForm($chat, $filters);
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        $this->nextPage($result, 'dzen-chat-history', $filters);
    }

    private function chat(string $id, array $filters): void
    {
        echo '<p><a href="' . esc_url(self::url('dzen-chat-history', $filters)) . '">← ' . esc_html__('К списку диалогов', 'dzen-chat') . '</a></p>';
        $path = '/chats/' . Api::segment($id);
        $chat = $this->api->request('GET', $path);
        if (is_wp_error($chat)) {
            $this->error($chat);
            return;
        }
        if (($chat['id'] ?? null) !== $id) {
            $this->error(new \WP_Error('protocol', __('Сервис вернул другой диалог.', 'dzen-chat')));
            return;
        }
        echo '<h3>' . esc_html($chat['title'] ?? $id) . '</h3>';
        $this->visibilityForm($chat, $filters + ['return_chat' => $id]);
        if (($chat['hidden'] ?? false) === true) {
            echo '<p><strong>' . esc_html__('Скрыт из основного списка', 'dzen-chat') . '</strong></p>';
        }
        $message = self::input('message_id', $_GET);
        $reference = self::input('reference_id', $_GET);
        if ($message !== '' && $reference !== '') {
            echo '<p><a href="' . esc_url(self::url('dzen-chat-history', $filters + ['chat' => $id])) . '">← ' . esc_html__('К сообщениям', 'dzen-chat') . '</a></p>';
            $source = $this->api->request('GET', $path . '/messages/' . Api::segment($message) . '/sources/' . Api::segment($reference));
            if (is_wp_error($source)) {
                $this->error($source);
                return;
            }
            echo '<section class="dzen-source"><h3>' . esc_html($source['title'] ?? __('Источник', 'dzen-chat')) . '</h3>';
            if (($source['available'] ?? false) !== true) {
                echo '<p>' . esc_html__('Источник удалён или право просмотра недоступно. Содержимое не загружается.', 'dzen-chat') . '</p>';
            } else {
                echo '<p>' . esc_html(($source['content_kind'] ?? '') === 'excerpt' ? __('Цитата, использованная в ответе', 'dzen-chat') : __('Содержимое источника', 'dzen-chat')) . '</p>';
                if (!empty($source['captured_at'])) {
                    echo '<p>' . esc_html__('Снимок от', 'dzen-chat') . ' ' . esc_html($source['captured_at']) . '</p>';
                }
                echo '<div class="dzen-text">' . esc_html($source['content'] ?? '') . '</div>';
                $this->external($source['url'] ?? null);
            }
            echo '</section>';
            return;
        }
        $messageQuery = ['limit' => 50];
        $cursor = self::input('message_cursor', $_GET);
        if ($cursor !== '') {
            $messageQuery['cursor'] = $cursor;
        }
        $messages = $this->api->request('GET', $path . '/messages', $messageQuery);
        if (!$this->listing($messages)) {
            return;
        }
        foreach ($messages['items'] as $msg) {
            if (!in_array($msg['role'] ?? '', ['user', 'assistant'], true)) {
                continue;
            }
            echo '<article class="dzen-message"><h3>' . esc_html($msg['role'] === 'user' ? __('Посетитель', 'dzen-chat') : __('Dzen Chat', 'dzen-chat')) . '</h3><p class="description">' . esc_html($msg['created_at'] ?? '') . '</p><div class="dzen-text">' . esc_html($msg['text'] ?? '') . '</div>';
            if (isset($msg['vote']) && is_bool($msg['vote'])) {
                echo '<p>' . esc_html($msg['vote'] ? __('Полезный ответ', 'dzen-chat') : __('Ответ не помог', 'dzen-chat')) . '</p>';
            }
            foreach ($msg['sources'] ?? [] as $source) {
                $link = self::url('dzen-chat-history', $filters + ['chat' => $id, 'message_id' => (string) $msg['id'], 'reference_id' => (string) $source['id']]);
                echo '<div class="dzen-reference"><strong>' . esc_html($source['title'] ?? __('Источник', 'dzen-chat')) . '</strong> <a href="' . esc_url($link) . '">' . esc_html__('Показать в WordPress', 'dzen-chat') . '</a> ';
                if (($source['available'] ?? false) === true) {
                    $this->external($source['url'] ?? null);
                } else {
                    echo esc_html__('Источник недоступен', 'dzen-chat');
                }
                echo '</div>';
            }
            echo '</article>';
        }
        if (!empty($messages['next_cursor'])) {
            echo '<p><a class="button" href="' . esc_url(self::url('dzen-chat-history', $filters + ['chat' => $id, 'message_cursor' => $messages['next_cursor']])) . '">' . esc_html__('Следующие сообщения', 'dzen-chat') . '</a></p>';
        }
    }

    private function external(mixed $url): void
    {
        $safe = self::publicUrl($url);
        if ($safe !== '') {
            echo '<a class="dzen-external" href="' . $safe . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Открыть оригинал ↗', 'dzen-chat') . '</a>';
        }
    }

    private function visibilityForm(array $chat, array $filters): void
    {
        $status = get_transient('dzen_chat_status');
        if (!$status || !in_array('chats:visibility:update', $status['scopes'] ?? [], true)) {
            // Fetch permissions, never cache chat history to get them.
            $status = $this->api->status();
        }
        if (is_wp_error($status) || !in_array('chats:visibility:update', $status['scopes'] ?? [], true)
            || !isset($chat['visibility_version']) || !is_bool($chat['hidden'] ?? null)) {
            return;
        }
        $this->form('dzen_chat_visibility', 'dzen_chat_visibility_' . $chat['id'], $filters + [
            'chat_id' => $chat['id'], 'hidden' => $chat['hidden'] ? '0' : '1',
            'expected_version' => (string) $chat['visibility_version'],
        ], $chat['hidden'] ? __('Вернуть в список', 'dzen-chat') : __('Скрыть', 'dzen-chat'));
    }

    public function visibility(): void
    {
        $id = self::input('chat_id', $_POST);
        Connection::authorize('dzen_chat_visibility_' . $id);
        $hidden = self::input('hidden', $_POST);
        $version = self::input('expected_version', $_POST);
        $filters = self::filters($_POST);
        if ($id === '' || strlen($id) > 128 || !in_array($hidden, ['0', '1'], true)
            || !preg_match('/^[0-9]{1,10}$/D', $version) || is_wp_error($filters)) {
            wp_die(esc_html__('Некорректное действие.', 'dzen-chat'), '', ['response' => 400]);
        }
        $status = $this->api->status();
        if (is_wp_error($status) || !in_array('chats:visibility:update', $status['scopes'] ?? [], true)) {
            wp_die(esc_html__('Нет права менять видимость чата.', 'dzen-chat'), '', ['response' => 403, 'back_link' => true]);
        }
        $result = $this->api->request('PATCH', '/chats/' . Api::segment($id) . '/visibility', [], [
            'hidden' => $hidden === '1', 'expected_version' => (int) $version,
        ], wp_generate_uuid4());
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()), '', ['response' => $result->get_error_data()['status'] ?? 502, 'back_link' => true]);
        }
        if (($result['id'] ?? '') !== $id || ($result['hidden'] ?? null) !== ($hidden === '1')
            || !is_int($result['visibility_version'] ?? null) || $result['visibility_version'] < (int) $version) {
            wp_die(esc_html__('Сервис не подтвердил новое состояние. Обновите список.', 'dzen-chat'), '', ['response' => 502, 'back_link' => true]);
        }
        unset($filters['cursor']);
        if (self::input('return_chat', $_POST) === $id) {
            $filters['chat'] = $id;
        }
        $filters['notice'] = $hidden === '1' ? 'hidden' : 'restored';
        wp_safe_redirect(self::url('dzen-chat-history', $filters), 303);
        exit;
    }

    private function listing(array|\WP_Error $result): bool
    {
        if (is_wp_error($result)) {
            $this->error($result);
            return false;
        }
        if (!isset($result['items']) || !is_array($result['items'])) {
            $this->error(new \WP_Error('protocol', __('Сервис вернул некорректный список.', 'dzen-chat')));
            return false;
        }
        if (!$result['items']) {
            echo '<p>' . esc_html__('По этим условиям ничего не найдено.', 'dzen-chat') . '</p>';
        }
        return true;
    }

    private function nextPage(array $result, string $page, array $filters): void
    {
        if (!empty($result['next_cursor'])) {
            $filters['cursor'] = $result['next_cursor'];
            echo '<p><a class="button" href="' . esc_url(self::url($page, $filters)) . '">' . esc_html__('Следующая страница', 'dzen-chat') . '</a></p>';
        }
    }

    private function widgets(): void
    {
        echo '<h2>' . esc_html__('Виджеты', 'dzen-chat') . '</h2>';
        $status = $this->api->status();
        if (is_wp_error($status)) {
            $this->error($status);
            echo '<p>' . esc_html__('Показ виджета приостановлен до подтверждения подключения.', 'dzen-chat') . '</p>';
        } elseif (($status['allowed_operations']['widget_answers'] ?? false) !== true
            || ($status['integration']['status'] ?? '') !== 'active') {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Показ виджета приостановлен: проверьте состояние проекта и оплату в Dzen Chat.', 'dzen-chat') . '</p></div>';
        }
        if (!get_option('dzen_chat_widget')) {
            echo '<p>' . esc_html__('Виджет для сайта не выбран. Включите нужный виджет и нажмите «Разместить на сайте».', 'dzen-chat') . '</p>';
        }
        $result = $this->api->request('GET', '/widgets', ['limit' => 50]);
        if (!$this->listing($result)) {
            return;
        }
        $cached = [];
        foreach ($result['items'] as $widget) {
            if (isset($widget['id'], $widget['code']) && preg_match('/^[A-Za-z0-9_-]+$/D', $widget['code'])) {
                $cached[(string) $widget['id']] = ['id' => (string) $widget['id'], 'code' => $widget['code'],
                    'name' => sanitize_text_field($widget['name'] ?? ''), 'is_enabled' => ($widget['is_enabled'] ?? false) === true];
            }
            echo '<section class="dzen-message"><h3>' . esc_html($widget['name'] ?? $widget['id']) . '</h3><p>' . esc_html(($widget['is_enabled'] ?? false) ? __('Включён в Dzen Chat', 'dzen-chat') : __('Выключен в Dzen Chat — не показывается на сайте', 'dzen-chat')) . '</p>';
            $this->form('dzen_chat_action', 'dzen_chat_action', ['operation' => 'widget_toggle', 'id' => $widget['id'],
                'enabled' => !empty($widget['is_enabled']) ? '0' : '1', 'version' => $widget['version'] ?? 0], !empty($widget['is_enabled']) ? __('Выключить в Dzen Chat', 'dzen-chat') : __('Включить в Dzen Chat', 'dzen-chat'));
            $this->form('dzen_chat_action', 'dzen_chat_action', ['operation' => 'widget_select', 'id' => $widget['id']],
                get_option('dzen_chat_widget') === (string) $widget['id'] ? __('Выбран для сайта', 'dzen-chat') : __('Разместить на сайте', 'dzen-chat'));
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('dzen_chat_action');
            echo '<input type="hidden" name="action" value="dzen_chat_action"><input type="hidden" name="operation" value="widget_edit"><input type="hidden" name="id" value="' . esc_attr($widget['id']) . '"><input type="hidden" name="version" value="' . esc_attr((string) ($widget['version'] ?? 0)) . '">';
            echo '<p><label>' . esc_html__('Название', 'dzen-chat') . ' <input name="name" required maxlength="128" value="' . esc_attr($widget['name'] ?? '') . '"></label></p><p><label>' . esc_html__('Приветствие', 'dzen-chat') . '<br><textarea name="welcome" rows="3" class="large-text" maxlength="2000">' . esc_textarea(implode("\n", $widget['welcome_messages'] ?? [])) . '</textarea></label></p><button class="button">' . esc_html__('Сохранить настройки', 'dzen-chat') . '</button></form></section>';
        }
        update_option('dzen_chat_widgets', $cached, false);
        echo '<p><a class="button" href="' . esc_url(home_url('/')) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Открыть сайт и проверить виджет', 'dzen-chat') . '</a></p>';
        $this->form('dzen_chat_action', 'dzen_chat_action', ['operation' => 'widget_select', 'id' => ''], __('Не вставлять виджет на сайте', 'dzen-chat'));
        echo '<h3>' . esc_html__('Создать виджет', 'dzen-chat') . '</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('dzen_chat_action');
        echo '<input type="hidden" name="action" value="dzen_chat_action"><input type="hidden" name="operation" value="widget_create"><label>' . esc_html__('Название', 'dzen-chat') . ' <input name="name" required maxlength="128"></label> <button class="button button-primary">' . esc_html__('Создать', 'dzen-chat') . '</button></form>';
        echo '<p>' . esc_html__('Перед включением уберите ранее вставленный вручную скрипт Dzen Chat, чтобы виджет не загружался дважды.', 'dzen-chat') . '</p>';
    }

    private function documents(): void
    {
        echo '<h2>' . esc_html__('Индекс и триггеры', 'dzen-chat') . '</h2>';
        $query = ['limit' => 20];
        foreach (['cursor', 'q', 'status', 'source_id'] as $field) {
            if (self::input($field, $_GET) !== '') {
                $query[$field] = self::input($field, $_GET);
            }
        }
        echo '<form method="get"><input type="hidden" name="page" value="dzen-chat-documents"><label>' . esc_html__('Поиск URL или названия', 'dzen-chat') . ' <input name="q" value="' . esc_attr($query['q'] ?? '') . '"></label> <button class="button">' . esc_html__('Найти', 'dzen-chat') . '</button></form>';
        $result = $this->api->request('GET', '/documents', $query);
        if (!$this->listing($result)) {
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Документ', 'dzen-chat') . '</th><th>' . esc_html__('Состояние', 'dzen-chat') . '</th><th>' . esc_html__('Триггеры страницы', 'dzen-chat') . '</th></tr></thead><tbody>';
        foreach ($result['items'] as $doc) {
            echo '<tr><td>' . esc_html($doc['title'] ?? '') . '<br>';
            $this->external($doc['url'] ?? null);
            echo '</td><td>' . esc_html($doc['status'] ?? '') . '<br>' . esc_html($doc['error_message'] ?? '') . '</td><td>';
            if (!empty($doc['external_id'])) {
                echo '<p>' . esc_html(($doc['triggers_enabled'] ?? false) ? __('Действуют', 'dzen-chat') : __('Выключены или ещё не готовы', 'dzen-chat')) . '</p>';
                foreach (['inherit' => __('Наследовать', 'dzen-chat'), 'enabled' => __('Включить', 'dzen-chat'), 'disabled' => __('Выключить', 'dzen-chat')] as $policy => $label) {
                    $this->form('dzen_chat_action', 'dzen_chat_action', ['operation' => 'triggers', 'id' => $doc['external_id'], 'policy' => $policy], $label);
                }
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        $this->nextPage($result, 'dzen-chat-documents', $query);
    }

    private function sources(): void
    {
        echo '<h2>' . esc_html__('Источники', 'dzen-chat') . '</h2>';
        $query = ['limit' => 20];
        if (self::input('cursor', $_GET) !== '') {
            $query['cursor'] = self::input('cursor', $_GET);
        }
        $result = $this->api->request('GET', '/sources', $query);
        if (!$this->listing($result)) {
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Источник', 'dzen-chat') . '</th><th>' . esc_html__('Состояние', 'dzen-chat') . '</th><th>' . esc_html__('Документы', 'dzen-chat') . '</th><th>' . esc_html__('Обновление (UTC)', 'dzen-chat') . '</th></tr></thead><tbody>';
        foreach ($result['items'] as $source) {
            echo '<tr><td>' . esc_html($source['title'] ?? '') . '</td><td>' . esc_html($source['status'] ?? '') . '<br>' . esc_html($source['error_message'] ?? '') . '</td><td>' . esc_html((string) ($source['document_count'] ?? '')) . '</td><td>' . esc_html($source['last_indexed_at'] ?? '') . '<br>' . esc_html($source['next_index_at'] ?? '') . '</td></tr>';
        }
        echo '</tbody></table>';
        $this->nextPage($result, 'dzen-chat-sources', $query);
    }

    public function action(): void
    {
        Connection::authorize('dzen_chat_action');
        $operation = self::input('operation', $_POST);
        $id = self::input('id', $_POST);
        $destination = 'dzen-chat-widgets';
        $result = [];
        switch ($operation) {
            case 'reconcile':
                $this->sync->retry();
                wp_safe_redirect(self::url('dzen-chat', ['notice' => 'queued']), 303);
                exit;
            case 'widget_select':
                $widgets = get_option('dzen_chat_widgets', []);
                if ($id !== '' && !isset($widgets[$id])) {
                    wp_die(esc_html__('Обновите список виджетов.', 'dzen-chat'), '', ['response' => 400]);
                }
                update_option('dzen_chat_widget', $id, false);
                break;
            case 'widget_toggle':
                $enabled = self::input('enabled', $_POST) === '1';
                $result = $this->api->request('PATCH', '/widgets/' . Api::segment($id), [], [
                    'is_enabled' => $enabled,
                    'expected_version' => (int) self::input('version', $_POST),
                ], wp_generate_uuid4());
                if (!is_wp_error($result)) {
                    if (($result['id'] ?? '') !== $id || ($result['is_enabled'] ?? null) !== $enabled) {
                        $result = new \WP_Error('dzen_widget_state', __('Сервис не подтвердил состояние виджета. Обновите список.', 'dzen-chat'));
                    } else {
                        $widgets = get_option('dzen_chat_widgets', []);
                        if (isset($widgets[$id])) {
                            $widgets[$id]['is_enabled'] = $enabled;
                            update_option('dzen_chat_widgets', $widgets, false);
                        }
                    }
                }
                break;
            case 'widget_edit':
            case 'widget_create':
                $name = self::input('name', $_POST);
                if ($name === '' || strlen($name) > 512) {
                    wp_die(esc_html__('Укажите название виджета.', 'dzen-chat'), '', ['response' => 400]);
                }
                $payload = ['name' => $name];
                if ($operation === 'widget_edit') {
                    $welcome = isset($_POST['welcome']) && is_string($_POST['welcome']) ? sanitize_textarea_field(wp_unslash($_POST['welcome'])) : '';
                    $payload += ['welcome_messages' => array_values(array_filter(explode("\n", $welcome))), 'expected_version' => (int) self::input('version', $_POST)];
                } else {
                    $payload['site_source_only'] = true;
                }
                $result = $this->api->request($operation === 'widget_create' ? 'POST' : 'PATCH',
                    '/widgets' . ($operation === 'widget_edit' ? '/' . Api::segment($id) : ''), [], $payload, wp_generate_uuid4());
                break;
            case 'triggers':
                $policy = self::input('policy', $_POST);
                if (!in_array($policy, ['inherit', 'enabled', 'disabled'], true)) {
                    wp_die(esc_html__('Неверная политика триггеров.', 'dzen-chat'), '', ['response' => 400]);
                }
                $result = $this->api->request('PUT', '/page-policies/' . Api::segment($id), [], ['mode' => $policy], wp_generate_uuid4());
                $destination = 'dzen-chat-documents';
                if (!is_wp_error($result) && preg_match('/^wp:post:(\d+)$/D', $id, $match)) {
                    update_post_meta((int) $match[1], '_dzen_chat_triggers', $policy);
                }
                break;
            default:
                wp_die(esc_html__('Неизвестное действие.', 'dzen-chat'), '', ['response' => 400]);
        }
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()), '', ['response' => 502, 'back_link' => true]);
        }
        wp_safe_redirect(self::url($destination, ['notice' => 'saved']), 303);
        exit;
    }
}
