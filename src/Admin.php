<?php
namespace DzenChat;

defined('ABSPATH') || exit;

final class Admin
{
    public function __construct(private Credentials $credentials, private Api $api, private Sync $sync) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_dzen_chat_action', [$this, 'action']);
        add_action('admin_enqueue_scripts', static function ($hook) {
            if (str_contains($hook, 'dzen-chat')) {
                wp_enqueue_style('dzen-chat-admin', plugins_url('assets/admin.css', DZEN_CHAT_FILE), [], DZEN_CHAT_VERSION);
            }
        });
    }

    public function menu(): void
    {
        add_menu_page('Dzen Chat', 'Dzen Chat', 'manage_options', 'dzen-chat', [$this, 'page'], 'dashicons-format-chat', 58);
        foreach (['dzen-chat' => __('Обзор', 'dzen-chat'), 'dzen-chat-widgets' => __('Виджеты', 'dzen-chat'),
            'dzen-chat-documents' => __('Индекс', 'dzen-chat'), 'dzen-chat-history' => __('Диалоги', 'dzen-chat'),
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
        $result = [];
        foreach (['q' => 200, 'widget_code' => 128, 'source_id' => 128, 'status' => 64,
            'date_from' => 10, 'date_to' => 10] as $key => $max) {
            $value = self::input($key, $input);
            if (strlen($value) > $max) return new \WP_Error('filter', __('Слишком длинный фильтр.', 'dzen-chat'));
            if ($value === '') continue;
            if (str_starts_with($key, 'date_')) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
                if (!$date || $date->format('Y-m-d') !== $value) {
                    return new \WP_Error('filter', __('Укажите дату в формате ГГГГ-ММ-ДД.', 'dzen-chat'));
                }
            }
            $result[$key] = $value;
        }
        if (isset($result['date_from'], $result['date_to']) && $result['date_from'] > $result['date_to']) {
            return new \WP_Error('filter', __('Начальная дата должна быть не позже конечной.', 'dzen-chat'));
        }
        return $result;
    }

    public static function publicUrl(mixed $value): string
    {
        if (!is_string($value)) return '';
        $parts = wp_parse_url($value);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return '';
        return esc_url($value, ['http', 'https']);
    }

    private static function text(array $item, string $key): string
    {
        return isset($item[$key]) && is_scalar($item[$key]) ? (string) $item[$key] : '';
    }

    private static function statusLabel(array $item): string
    {
        return match (self::text($item, 'status')) {
            'crawler' => __('Ожидает обхода', 'dzen-chat'),
            'parsing' => __('Обрабатывается', 'dzen-chat'),
            'ready' => __('Обработка завершена', 'dzen-chat'),
            default => self::text($item, 'status'),
        };
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
            if (is_scalar($value)) echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr((string) $value) . '">';
        }
        echo '<button class="button" type="submit">' . esc_html($label) . '</button></form>';
    }

    public function page(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Недостаточно прав.', 'dzen-chat'), '', ['response' => 403]);
        nocache_headers();
        echo '<div class="wrap dzen-chat"><h1>Dzen Chat</h1>';
        $notices = [
            'registered' => __('Авторизация завершена. Публичные страницы будут отправлены на переиндексацию.', 'dzen-chat'),
            'credentials_removed' => __('Ключи удалены из WordPress. Для отзыва API-клиента откройте Dzen Chat.', 'dzen-chat'),
            'cancelled' => __('Подключение отменено.', 'dzen-chat'),
            'authorization_failed' => __('Подключение не завершено. Начните его заново.', 'dzen-chat'),
            'saved' => __('Изменение сохранено.', 'dzen-chat'),
            'queued' => __('Запрос принят. Статус обработки можно посмотреть в разделе «Индекс».', 'dzen-chat'),
            'reconcile' => __('Сверка публичных страниц поставлена в очередь.', 'dzen-chat'),
        ];
        $notice = self::input('notice', $_GET);
        if (isset($notices[$notice])) echo '<div class="notice notice-info"><p>' . esc_html($notices[$notice]) . '</p></div>';
        if (!$this->credentials->exists()) {
            echo '<p>' . esc_html__('Подключите сайт к существующему или новому проекту Dzen Chat.', 'dzen-chat') . '</p>';
            $this->form('dzen_chat_connect', 'dzen_chat_connect', [], __('Подключить Dzen Chat', 'dzen-chat'));
        } else {
            try {
                $this->credentials->get();
                match (self::input('page', $_GET)) {
                    'dzen-chat-widgets' => $this->widgets(),
                    'dzen-chat-documents' => $this->documents(),
                    'dzen-chat-history' => $this->history(),
                    'dzen-chat-sources' => $this->sources(),
                    default => $this->overview(),
                };
            } catch (\RuntimeException | \JsonException $error) {
                $this->error(new \WP_Error('connection', __('Адрес сайта, сервис или ключи безопасности изменились. Подключите сайт заново.', 'dzen-chat')));
                $this->form('dzen_chat_connect', 'dzen_chat_connect', [], __('Подключить заново', 'dzen-chat'));
            }
        }
        echo '</div>';
    }

    private function overview(): void
    {
        echo '<h2>' . esc_html__('Сайт авторизован в Dzen Chat', 'dzen-chat') . '</h2><p>' . esc_html(Credentials::siteUrl()) . '</p>';
        $sources = $this->api->items('/sources');
        if (is_wp_error($sources)) {
            $this->error($sources);
        } else {
            echo '<p>' . esc_html__('Доступ к сервису подтверждён.', 'dzen-chat') . '</p>';
            $sourceId = $this->credentials->get()['site_source_id'];
            $source = current(array_filter($sources, static fn ($item) => $item['id'] === $sourceId));
            if ($source) {
                echo '<p>' . esc_html__('Источник сайта:', 'dzen-chat') . ' <a href="' . esc_url(self::url('dzen-chat-sources', ['source' => $sourceId])) . '">' . esc_html(self::text($source, 'title')) . '</a></p>';
                $this->sourceState($source);
            } else {
                $this->error(new \WP_Error('source', __('Источник сайта больше не доступен этому подключению.', 'dzen-chat')));
            }
        }
        echo '<p><a class="button button-primary" href="' . esc_url(self::url('dzen-chat-widgets')) . '">' . esc_html__('Настроить виджеты', 'dzen-chat') . '</a> ';
        $this->external(Api::origin(), __('Открыть Dzen Chat ↗', 'dzen-chat'));
        echo '</p>';
        $this->form('dzen_chat_connect', 'dzen_chat_connect', [], __('Подключить заново', 'dzen-chat'));
        $this->form('dzen_chat_disconnect', 'dzen_chat_disconnect', [], __('Удалить ключи из WordPress', 'dzen-chat'));
        echo '<h2>' . esc_html__('Обновление публичных страниц и записей', 'dzen-chat') . '</h2>';
        global $wpdb;
        [, $events] = Sync::tables();
        $counts = $wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) total FROM $events WHERE integration_id=%s GROUP BY status",
            $this->credentials->get()['client_id']), ARRAY_A);
        $labels = ['pending' => __('Ожидает отправки', 'dzen-chat'), 'accepted' => __('Передано сервису', 'dzen-chat'),
            'blocked' => __('Ожидает доступа', 'dzen-chat'), 'error' => __('Ошибка отправки', 'dzen-chat')];
        echo '<ul>';
        foreach ($counts as $row) echo '<li>' . esc_html(($labels[$row['status']] ?? $row['status']) . ': ' . $row['total']) . '</li>';
        echo '</ul><p>' . esc_html__('Принятый запрос ещё не означает завершённую индексацию. Результат обработки отображается в разделе «Индекс».', 'dzen-chat') . '</p>';
        if (get_option('dzen_chat_queue_error')) $this->error(new \WP_Error('queue', __('Не удалось записать событие. Проверьте подключение и базу WordPress, затем запустите сверку.', 'dzen-chat')));
        $this->form('dzen_chat_action', 'dzen_chat_action', ['operation' => 'reconcile'], __('Сверить контент и повторить ошибки', 'dzen-chat'));
        echo '<p>' . esc_html__('Задания выполняет планировщик WordPress. Товары, черновики и защищённые записи не отправляются как публичный контент.', 'dzen-chat') . '</p>';
        echo '<p class="description">' . esc_html__('Статус оплаты, скрытие диалогов, источники ответов и управление триггерами страниц пока недоступны в этой версии интеграции.', 'dzen-chat') . '</p>';
    }

    private function sourceState(array $source): void
    {
        if (!is_bool($source['is_paused'] ?? null) || !is_bool($source['enable_triggers'] ?? null)) {
            $this->error(new \WP_Error('protocol', __('Сервис вернул некорректное состояние источника.', 'dzen-chat')));
            return;
        }
        if (self::text($source, 'blocked_reason') !== '') {
            echo '<p>' . esc_html__('Обработка ограничена:', 'dzen-chat') . ' ' . esc_html(self::text($source, 'blocked_reason')) . '</p>';
        } else {
            echo '<p>' . esc_html(($source['is_paused'] ?? null) === true ? __('Обновление приостановлено', 'dzen-chat') : __('Обновление разрешено', 'dzen-chat')) . '</p>';
        }
        echo '<p>' . esc_html__('Триггеры источника:', 'dzen-chat') . ' ' . esc_html(($source['enable_triggers'] ?? null) === true ? __('включены', 'dzen-chat') : __('выключены', 'dzen-chat')) . '</p>';
        if (self::text($source, 'last_reindexed_at') !== '') echo '<p>' . esc_html__('Последняя переиндексация:', 'dzen-chat') . ' ' . esc_html(self::text($source, 'last_reindexed_at')) . '</p>';
    }

    private function sources(): void
    {
        echo '<h2>' . esc_html__('Источники', 'dzen-chat') . '</h2>';
        $fileId = self::input('file', $_GET);
        if ($fileId !== '') {
            $file = $this->api->request('GET', '/files/' . Api::segment($fileId));
            if (is_wp_error($file)) { $this->error($file); return; }
            if (($file['id'] ?? null) !== $fileId) { $this->error(new \WP_Error('protocol', __('Сервис вернул другой файл.', 'dzen-chat'))); return; }
            echo '<p><a href="' . esc_url(self::url('dzen-chat-sources')) . '">← ' . esc_html__('Все источники', 'dzen-chat') . '</a></p><h3>' . esc_html(self::text($file, 'name')) . '</h3>';
            echo '<p>' . esc_html(self::text($file, 'status')) . ' ' . esc_html(self::text($file, 'status_error')) . '</p><div class="dzen-text">' . esc_html(self::text($file, 'content')) . '</div>';
            return;
        }
        $id = self::input('source', $_GET);
        if ($id !== '') {
            $source = $this->api->request('GET', '/sources/' . Api::segment($id));
            if (is_wp_error($source)) { $this->error($source); return; }
            if (($source['id'] ?? null) !== $id) { $this->error(new \WP_Error('protocol', __('Сервис вернул другой источник.', 'dzen-chat'))); return; }
            echo '<p><a href="' . esc_url(self::url('dzen-chat-sources')) . '">← ' . esc_html__('Все источники', 'dzen-chat') . '</a></p>';
            echo '<h3>' . esc_html(self::text($source, 'title')) . '</h3>';
            $this->external($source['url'] ?? null);
            $this->sourceState($source);
            echo '<p><a href="' . esc_url(self::url('dzen-chat-documents', ['source_id' => $id])) . '">' . esc_html__('Документы источника', 'dzen-chat') . '</a></p>';
            return;
        }
        $items = $this->api->items('/sources');
        if (is_wp_error($items)) { $this->error($items); return; }
        if (!$items) echo '<p>' . esc_html__('Нет доступных источников.', 'dzen-chat') . '</p>';
        foreach ($items as $source) {
            echo '<section class="dzen-message"><h3><a href="' . esc_url(self::url('dzen-chat-sources', ['source' => $source['id']])) . '">' . esc_html(self::text($source, 'title')) . '</a></h3>';
            $this->sourceState($source);
            $this->external($source['url'] ?? null);
            echo '</section>';
        }
        echo '<h3>' . esc_html__('Файлы базы знаний', 'dzen-chat') . '</h3>';
        $files = $this->api->items('/files');
        if (is_wp_error($files)) { $this->error($files); return; }
        if (!$files) echo '<p>' . esc_html__('Файлы не добавлены.', 'dzen-chat') . '</p>';
        foreach ($files as $file) {
            echo '<section class="dzen-message"><h4><a href="' . esc_url(self::url('dzen-chat-sources', ['file' => $file['id']])) . '">' . esc_html(self::text($file, 'name')) . '</a></h4><p>' . esc_html(self::text($file, 'status')) . ' ' . esc_html(self::text($file, 'status_error')) . '</p><p>' . esc_html(self::text($file, 'updated_at')) . '</p></section>';
        }
    }

    private function documents(): void
    {
        echo '<h2>' . esc_html__('Индекс', 'dzen-chat') . '</h2>';
        $id = self::input('document', $_GET);
        if ($id !== '') {
            $page = $this->api->request('GET', '/pages/' . Api::segment($id));
            if (is_wp_error($page)) { $this->error($page); return; }
            if (($page['id'] ?? null) !== $id) { $this->error(new \WP_Error('protocol', __('Сервис вернул другой документ.', 'dzen-chat'))); return; }
            echo '<p><a href="' . esc_url(self::url('dzen-chat-documents')) . '">← ' . esc_html__('Все документы', 'dzen-chat') . '</a></p>';
            echo '<h3>' . esc_html(self::text($page, 'title') ?: self::text($page, 'url')) . '</h3>';
            $this->documentState($page);
            $this->documentLinks($page);
            return;
        }
        $filters = self::filters($_GET);
        if (is_wp_error($filters)) { $this->error($filters); return; }
        $items = $this->api->items('/pages', ['limit' => 100]);
        if (is_wp_error($items)) { $this->error($items); return; }
        echo '<p>' . esc_html__('Показаны до 100 последних обновлённых документов. Фильтры применяются к этому набору.', 'dzen-chat') . '</p>';
        echo '<form method="get" class="dzen-filters"><input type="hidden" name="page" value="dzen-chat-documents">';
        foreach (['q' => __('Название или URL', 'dzen-chat'), 'status' => __('Статус', 'dzen-chat'), 'source_id' => __('ID источника', 'dzen-chat')] as $key => $label) {
            echo '<label>' . esc_html($label) . '<input name="' . esc_attr($key) . '" value="' . esc_attr($filters[$key] ?? '') . '"></label>';
        }
        echo '<button class="button"> ' . esc_html__('Применить', 'dzen-chat') . '</button></form>';
        $items = array_filter($items, static function ($item) use ($filters) {
            if (isset($filters['source_id']) && ($item['source_id'] ?? '') !== $filters['source_id']) return false;
            if (isset($filters['status']) && ($item['status'] ?? '') !== $filters['status']) return false;
            return !isset($filters['q']) || preg_match('/' . preg_quote($filters['q'], '/') . '/iu', self::text($item, 'title') . ' ' . self::text($item, 'url')) === 1;
        });
        if (!$items) echo '<p>' . esc_html__('В загруженном наборе ничего не найдено.', 'dzen-chat') . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Документ', 'dzen-chat') . '</th><th>' . esc_html__('Состояние', 'dzen-chat') . '</th><th>' . esc_html__('Ссылки и действия', 'dzen-chat') . '</th></tr></thead><tbody>';
        foreach ($items as $page) {
            echo '<tr><td><a href="' . esc_url(self::url('dzen-chat-documents', ['document' => $page['id']])) . '">' . esc_html(self::text($page, 'title') ?: self::text($page, 'url')) . '</a></td><td>';
            $this->documentState($page);
            echo '</td><td>';
            $this->documentLinks($page);
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function documentState(array $page): void
    {
        echo '<p>' . esc_html(self::statusLabel($page)) . '</p>';
        if (self::text($page, 'status_error') !== '') echo '<p>' . esc_html(self::text($page, 'status_error')) . '</p>';
        if (self::text($page, 'updated_at') !== '') echo '<p>' . esc_html__('Обновлён:', 'dzen-chat') . ' ' . esc_html(self::text($page, 'updated_at')) . '</p>';
        echo '<p>' . esc_html(($page['has_triggers'] ?? null) === true ? __('Триггеры подготовлены', 'dzen-chat') : __('Триггеры не подготовлены', 'dzen-chat')) . '</p>';
    }

    private function documentLinks(array $page): void
    {
        $this->external($page['url'] ?? null);
        $post = is_string($page['url'] ?? null) ? url_to_postid($page['url']) : 0;
        if ($post && current_user_can('edit_post', $post)) {
            echo '<p><a href="' . esc_url(get_edit_post_link($post, '')) . '">' . esc_html__('Открыть запись в WordPress', 'dzen-chat') . '</a></p>';
        }
        if (self::text($page, 'source_id') !== '') {
            echo '<p><a href="' . esc_url(self::url('dzen-chat-sources', ['source' => $page['source_id']])) . '">' . esc_html__('Открыть источник в WordPress', 'dzen-chat') . '</a></p>';
        }
        if (($page['source_id'] ?? null) === $this->credentials->get()['site_source_id'] && self::publicUrl($page['url'] ?? null)) {
            $this->form('dzen_chat_action', 'dzen_chat_action', ['operation' => 'reindex', 'id' => $page['id']], __('Переиндексировать', 'dzen-chat'));
        }
    }

    private function history(): void
    {
        echo '<h2>' . esc_html__('Диалоги', 'dzen-chat') . '</h2><p>' . esc_html__('Переписка хранится в Dzen Chat.', 'dzen-chat') . '</p>';
        $id = self::input('chat', $_GET);
        if ($id !== '') { $this->chat($id); return; }
        $filters = self::filters($_GET);
        if (is_wp_error($filters)) { $this->error($filters); return; }
        $items = $this->api->items('/chats', ['limit' => 100]);
        if (is_wp_error($items)) { $this->error($items); return; }
        echo '<p>' . esc_html__('Фильтры применяются к 100 последним диалогам. Поиск по тексту всей истории и скрытие пока недоступны.', 'dzen-chat') . '</p>';
        echo '<form method="get" class="dzen-filters"><input type="hidden" name="page" value="dzen-chat-history">';
        echo '<label>' . esc_html__('ID диалога или посетителя', 'dzen-chat') . '<input name="q" value="' . esc_attr($filters['q'] ?? '') . '" maxlength="200"></label>';
        foreach (['date_from' => __('С даты (UTC)', 'dzen-chat'), 'date_to' => __('По дату (UTC)', 'dzen-chat')] as $key => $label) {
            echo '<label>' . esc_html($label) . '<input type="date" name="' . esc_attr($key) . '" value="' . esc_attr($filters[$key] ?? '') . '"></label>';
        }
        echo '<label>' . esc_html__('Виджет', 'dzen-chat') . '<select name="widget_code"><option value="">' . esc_html__('Все виджеты', 'dzen-chat') . '</option>';
        $widgets = $this->api->items('/widgets');
        if (!is_wp_error($widgets)) foreach ($widgets as $widget) {
            echo '<option value="' . esc_attr(self::text($widget, 'code')) . '"' . selected($filters['widget_code'] ?? '', self::text($widget, 'code'), false) . '>' . esc_html(self::text($widget, 'name')) . '</option>';
        }
        echo '</select></label><button class="button">' . esc_html__('Применить', 'dzen-chat') . '</button></form>';
        $items = array_filter($items, static function ($item) use ($filters) {
            $date = substr(self::text($item, 'created_at'), 0, 10);
            if (isset($filters['date_from']) && $date < $filters['date_from']) return false;
            if (isset($filters['date_to']) && $date > $filters['date_to']) return false;
            if (isset($filters['widget_code']) && ($item['widget_code'] ?? '') !== $filters['widget_code']) return false;
            return !isset($filters['q']) || stripos(self::text($item, 'id') . ' ' . self::text($item, 'visitor'), $filters['q']) !== false;
        });
        if (!$items) echo '<p>' . esc_html__('В загруженном наборе ничего не найдено.', 'dzen-chat') . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Диалог', 'dzen-chat') . '</th><th>' . esc_html__('Посетитель', 'dzen-chat') . '</th><th>' . esc_html__('Создан (UTC)', 'dzen-chat') . '</th></tr></thead><tbody>';
        foreach ($items as $chat) {
            echo '<tr><td><a href="' . esc_url(self::url('dzen-chat-history', ['chat' => $chat['id']])) . '">' . esc_html(self::text($chat, 'id')) . '</a></td><td>' . esc_html(self::text($chat, 'visitor')) . '</td><td>' . esc_html(self::text($chat, 'created_at')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function chat(string $id): void
    {
        echo '<p><a href="' . esc_url(self::url('dzen-chat-history')) . '">← ' . esc_html__('Все диалоги', 'dzen-chat') . '</a></p>';
        $chat = $this->api->request('GET', '/chats/' . Api::segment($id));
        if (is_wp_error($chat)) { $this->error($chat); return; }
        if (($chat['id'] ?? null) !== $id) { $this->error(new \WP_Error('protocol', __('Сервис вернул другой диалог.', 'dzen-chat'))); return; }
        $items = $this->api->items('/chats/' . Api::segment($id) . '/messages', ['limit' => 100]);
        if (is_wp_error($items)) { $this->error($items); return; }
        echo '<h3>' . esc_html($id) . '</h3>';
        if (count($items) === 100) echo '<p>' . esc_html__('Получены первые 100 сообщений. Продолжение пока доступно только в Dzen Chat.', 'dzen-chat') . '</p>';
        foreach ($items as $message) {
            if (!in_array($message['role'] ?? '', ['user', 'assistant'], true)) continue;
            echo '<article class="dzen-message"><h3>' . esc_html($message['role'] === 'user' ? __('Посетитель', 'dzen-chat') : __('Dzen Chat', 'dzen-chat')) . '</h3><p class="description">' . esc_html(self::text($message, 'created_at')) . '</p><div class="dzen-text">' . esc_html(self::text($message, 'text')) . '</div>';
            if (is_bool($message['vote'] ?? null)) echo '<p>' . esc_html($message['vote'] ? __('Полезный ответ', 'dzen-chat') : __('Ответ не помог', 'dzen-chat')) . '</p>';
            echo '</article>';
        }
    }

    private function external(mixed $url, ?string $label = null): void
    {
        $safe = self::publicUrl($url);
        if ($safe !== '') echo '<a class="dzen-external" href="' . $safe . '" target="_blank" rel="noopener noreferrer">' . esc_html($label ?? __('Открыть оригинал ↗', 'dzen-chat')) . '</a>';
    }

    private function widgets(): void
    {
        echo '<h2>' . esc_html__('Виджеты', 'dzen-chat') . '</h2>';
        $widgets = (new Widgets($this->credentials, $this->api))->all();
        if (is_wp_error($widgets)) {
            $this->error($widgets);
            echo '<p><a class="button" href="' . esc_url(self::url('dzen-chat-widgets')) . '">' . esc_html__('Обновить список', 'dzen-chat') . '</a></p>';
            echo '<p><a href="' . esc_url(self::url('dzen-chat')) . '">' . esc_html__('Настройки подключения', 'dzen-chat') . '</a></p>';
            return;
        }
        echo '<p>' . esc_html__('Включение и настройки виджета меняются для всего проекта Dzen Chat. Размещение на сайте настраивается отдельно в WordPress.', 'dzen-chat') . '</p>';
        if (!get_option('dzen_chat_widget')) {
            echo '<p>' . esc_html__('Виджет для сайта не выбран. Включите нужный виджет и нажмите «Разместить на сайте».', 'dzen-chat') . '</p>';
        } elseif (!isset($widgets[get_option('dzen_chat_widget')])) {
            echo '<p>' . esc_html__('Выбранный ранее виджет больше недоступен. Выберите другой виджет для сайта.', 'dzen-chat') . '</p>';
        }
        foreach ($widgets as $widget) {
            echo '<section class="dzen-message"><h3>' . esc_html($widget['name']) . '</h3><p>' . esc_html($widget['is_enabled'] ? __('Включён в Dzen Chat', 'dzen-chat') : __('Выключен в Dzen Chat — не показывается на сайте', 'dzen-chat')) . '</p>';
            if (get_option('dzen_chat_widget') === $widget['id']) {
                echo '<p><strong>' . esc_html__('Выбран для этого сайта', 'dzen-chat') . '</strong></p>';
            }
            $this->form('dzen_chat_action', 'dzen_chat_action', ['operation' => 'widget_toggle', 'id' => $widget['id'],
                'enabled' => $widget['is_enabled'] ? '0' : '1'], $widget['is_enabled'] ? __('Выключить в Dzen Chat', 'dzen-chat') : __('Включить в Dzen Chat', 'dzen-chat'));
            if ($widget['is_enabled'] && get_option('dzen_chat_widget') !== $widget['id']) {
                $this->form('dzen_chat_action', 'dzen_chat_action', ['operation' => 'widget_select', 'id' => $widget['id']], __('Разместить на сайте', 'dzen-chat'));
            }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('dzen_chat_action');
            echo '<input type="hidden" name="action" value="dzen_chat_action"><input type="hidden" name="operation" value="widget_edit"><input type="hidden" name="id" value="' . esc_attr($widget['id']) . '">';
            echo '<p><label>' . esc_html__('Название', 'dzen-chat') . ' <input name="name" required maxlength="128" value="' . esc_attr($widget['name']) . '"></label></p>';
            echo '<p><label><input type="checkbox" name="suggestions_enabled" value="1"' . checked($widget['suggestions_enabled'], true, false) . '> ' . esc_html__('Предлагать уточняющие вопросы после ответа', 'dzen-chat') . '</label></p>';
            echo '<button class="button">' . esc_html__('Сохранить настройки', 'dzen-chat') . '</button></form></section>';
        }
        echo '<p><a class="button" href="' . esc_url(home_url('/')) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Открыть сайт и проверить виджет', 'dzen-chat') . '</a></p>';
        $this->form('dzen_chat_action', 'dzen_chat_action', ['operation' => 'widget_select', 'id' => ''], __('Убрать общий виджет с сайта', 'dzen-chat'));
        echo '<p>' . esc_html__('В редакторе страницы или записи можно выбрать другой виджет либо скрыть его на этой странице.', 'dzen-chat') . '</p>';
        echo '<h3>' . esc_html__('Создать виджет', 'dzen-chat') . '</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('dzen_chat_action');
        echo '<input type="hidden" name="action" value="dzen_chat_action"><input type="hidden" name="operation" value="widget_create"><label>' . esc_html__('Название', 'dzen-chat') . ' <input name="name" required maxlength="128"></label> <button class="button button-primary">' . esc_html__('Создать', 'dzen-chat') . '</button></form>';
        echo '<p>' . esc_html__('Перед включением уберите ранее вставленный вручную скрипт Dzen Chat, чтобы виджет не загружался дважды.', 'dzen-chat') . '</p>';
    }


    public function action(): void
    {
        Connection::authorize('dzen_chat_action');
        try { $this->credentials->get(); }
        catch (\RuntimeException | \JsonException $error) {
            wp_die(esc_html__('Подключите сайт заново в разделе Dzen Chat.', 'dzen-chat'), '', ['response' => 409]);
        }
        $operation = self::input('operation', $_POST);
        $id = self::input('id', $_POST);
        $destination = 'dzen-chat-widgets';
        $notice = 'saved';
        $result = [];
        $widgets = new Widgets($this->credentials, $this->api);
        switch ($operation) {
            case 'reconcile':
                $this->sync->retry();
                $destination = 'dzen-chat';
                $notice = 'reconcile';
                break;
            case 'reindex':
                $page = $this->api->request('GET', '/pages/' . Api::segment($id));
                if (is_wp_error($page)) { $result = $page; break; }
                if (($page['id'] ?? '') !== $id || ($page['source_id'] ?? '') !== $this->credentials->get()['site_source_id']
                    || !self::publicUrl($page['url'] ?? null)) {
                    wp_die(esc_html__('Страница не относится к источнику этого сайта.', 'dzen-chat'), '', ['response' => 403]);
                }
                $result = $this->api->reindex($page['url']);
                $destination = 'dzen-chat-documents';
                $notice = 'queued';
                break;
            case 'widget_select':
                if ($id !== '') {
                    $result = $widgets->get($id);
                    if (is_wp_error($result)) break;
                    if (!$result['is_enabled']) {
                        $result = new \WP_Error('disabled', __('Сначала включите виджет, затем разместите его на сайте.', 'dzen-chat'));
                        break;
                    }
                }
                update_option('dzen_chat_widget', $id, false);
                break;
            case 'widget_toggle':
                $result = $widgets->save($id, ['is_enabled' => self::input('enabled', $_POST) === '1']);
                break;
            case 'widget_edit':
            case 'widget_create':
                $name = self::input('name', $_POST);
                if (!preg_match('/\A.{1,128}\z/us', $name)) wp_die(esc_html__('Укажите название виджета.', 'dzen-chat'), '', ['response' => 400]);
                $changes = ['name' => $name];
                if ($operation === 'widget_edit') $changes['suggestions_enabled'] = self::input('suggestions_enabled', $_POST) === '1';
                $result = $widgets->save($operation === 'widget_create' ? null : $id, $changes);
                break;
            default:
                wp_die(esc_html__('Неизвестное действие.', 'dzen-chat'), '', ['response' => 400]);
        }
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()), '', ['response' => 502, 'back_link' => true]);
        wp_safe_redirect(self::url($destination, ['notice' => $notice]), 303);
        exit;
    }
}
