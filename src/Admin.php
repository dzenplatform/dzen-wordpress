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
        foreach (['dzen-chat' => __('Overview', 'dzen-chat'), 'dzen-chat-widgets' => __('Widgets', 'dzen-chat'),
            'dzen-chat-documents' => __('Index', 'dzen-chat'), 'dzen-chat-history' => __('Conversations', 'dzen-chat'),
            'dzen-chat-sources' => __('Sources', 'dzen-chat')] as $slug => $title) {
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
            if (strlen($value) > $max) return new \WP_Error('filter', __('The filter is too long.', 'dzen-chat'));
            if ($value === '') continue;
            if (str_starts_with($key, 'date_')) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
                if (!$date || $date->format('Y-m-d') !== $value) {
                    return new \WP_Error('filter', __('Enter a date in YYYY-MM-DD format.', 'dzen-chat'));
                }
            }
            $result[$key] = $value;
        }
        if (isset($result['date_from'], $result['date_to']) && $result['date_from'] > $result['date_to']) {
            return new \WP_Error('filter', __('The start date must be on or before the end date.', 'dzen-chat'));
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
            'crawler' => __('Waiting to be crawled', 'dzen-chat'),
            'parsing' => __('Processing', 'dzen-chat'),
            'ready' => __('Processing complete', 'dzen-chat'),
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
        if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to perform this action.', 'dzen-chat'), '', ['response' => 403]);
        nocache_headers();
        echo '<div class="wrap dzen-chat"><h1>Dzen Chat</h1>';
        $notices = [
            'registered' => __('Authorization complete. Public pages will be submitted for reindexing.', 'dzen-chat'),
            'credentials_removed' => __('Credentials removed from WordPress. Open Dzen Chat to revoke the API client.', 'dzen-chat'),
            'cancelled' => __('Connection cancelled.', 'dzen-chat'),
            'authorization_failed' => __('Connection was not completed. Start again.', 'dzen-chat'),
            'saved' => __('Change saved.', 'dzen-chat'),
            'queued' => __('Request accepted. Check processing progress in the Index section.', 'dzen-chat'),
            'reconcile' => __('Public content reconciliation has been queued.', 'dzen-chat'),
        ];
        $notice = self::input('notice', $_GET);
        if (isset($notices[$notice])) echo '<div class="notice notice-info"><p>' . esc_html($notices[$notice]) . '</p></div>';
        if (!$this->credentials->exists()) {
            echo '<p>' . esc_html__('Connect this site to an existing or new Dzen Chat project.', 'dzen-chat') . '</p>';
            $this->form('dzen_chat_connect', 'dzen_chat_connect', [], __('Connect Dzen Chat', 'dzen-chat'));
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
                $this->error(new \WP_Error('connection', __('The site URL, service URL or security keys have changed. Reconnect the site.', 'dzen-chat')));
                $this->form('dzen_chat_connect', 'dzen_chat_connect', [], __('Reconnect', 'dzen-chat'));
            }
        }
        echo '</div>';
    }

    private function overview(): void
    {
        echo '<h2>' . esc_html__('This site is connected to Dzen Chat', 'dzen-chat') . '</h2><p>' . esc_html(Credentials::siteUrl()) . '</p>';
        $sources = $this->api->items('/sources');
        if (is_wp_error($sources)) {
            $this->error($sources);
        } else {
            echo '<p>' . esc_html__('Service access confirmed.', 'dzen-chat') . '</p>';
            $sourceId = $this->credentials->get()['site_source_id'];
            $source = current(array_filter($sources, static fn ($item) => $item['id'] === $sourceId));
            if ($source) {
                echo '<p>' . esc_html__('Site source:', 'dzen-chat') . ' <a href="' . esc_url(self::url('dzen-chat-sources', ['source' => $sourceId])) . '">' . esc_html(self::text($source, 'title')) . '</a></p>';
                $this->sourceState($source);
            } else {
                $this->error(new \WP_Error('source', __('The site source is no longer available to this connection.', 'dzen-chat')));
            }
        }
        echo '<p><a class="button button-primary" href="' . esc_url(self::url('dzen-chat-widgets')) . '">' . esc_html__('Manage widgets', 'dzen-chat') . '</a> ';
        $this->external(Api::origin(), __('Open Dzen Chat ↗', 'dzen-chat'));
        echo '</p>';
        $this->form('dzen_chat_connect', 'dzen_chat_connect', [], __('Reconnect', 'dzen-chat'));
        $this->form('dzen_chat_disconnect', 'dzen_chat_disconnect', [], __('Remove credentials from WordPress', 'dzen-chat'));
        echo '<h2>' . esc_html__('Public page and post updates', 'dzen-chat') . '</h2>';
        global $wpdb;
        [, $events] = Sync::tables();
        $counts = $wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) total FROM $events WHERE integration_id=%s GROUP BY status",
            $this->credentials->get()['client_id']), ARRAY_A);
        $labels = ['pending' => __('Waiting to be sent', 'dzen-chat'), 'accepted' => __('Submitted to the service', 'dzen-chat'),
            'blocked' => __('Waiting for access', 'dzen-chat'), 'error' => __('Submission failed', 'dzen-chat')];
        echo '<ul>';
        foreach ($counts as $row) echo '<li>' . esc_html(($labels[$row['status']] ?? $row['status']) . ': ' . $row['total']) . '</li>';
        echo '</ul><p>' . esc_html__('An accepted request does not mean indexing is complete. Check processing results in the Index section.', 'dzen-chat') . '</p>';
        if (get_option('dzen_chat_queue_error')) $this->error(new \WP_Error('queue', __('Could not save the event. Check the connection and WordPress database, then run reconciliation.', 'dzen-chat')));
        $this->form('dzen_chat_action', 'dzen_chat_action', ['operation' => 'reconcile'], __('Reconcile content and retry failed requests', 'dzen-chat'));
        echo '<p>' . esc_html__('The WordPress scheduler runs these jobs. Products, drafts and password-protected posts are not submitted as public content.', 'dzen-chat') . '</p>';
        echo '<p class="description">' . esc_html__('Billing status, hiding conversations, answer sources and page trigger controls are not yet available in this integration.', 'dzen-chat') . '</p>';
    }

    private function sourceState(array $source): void
    {
        if (!is_bool($source['is_paused'] ?? null) || !is_bool($source['enable_triggers'] ?? null)) {
            $this->error(new \WP_Error('protocol', __('The service returned an invalid source state.', 'dzen-chat')));
            return;
        }
        if (self::text($source, 'blocked_reason') !== '') {
            echo '<p>' . esc_html__('Processing restricted:', 'dzen-chat') . ' ' . esc_html(self::text($source, 'blocked_reason')) . '</p>';
        } else {
            echo '<p>' . esc_html(($source['is_paused'] ?? null) === true ? __('Updates paused', 'dzen-chat') : __('Updates allowed', 'dzen-chat')) . '</p>';
        }
        echo '<p>' . esc_html__('Source triggers:', 'dzen-chat') . ' ' . esc_html(($source['enable_triggers'] ?? null) === true ? __('enabled', 'dzen-chat') : __('disabled', 'dzen-chat')) . '</p>';
        if (self::text($source, 'last_reindexed_at') !== '') echo '<p>' . esc_html__('Last reindexed:', 'dzen-chat') . ' ' . esc_html(self::text($source, 'last_reindexed_at')) . '</p>';
    }

    private function sources(): void
    {
        echo '<h2>' . esc_html__('Sources', 'dzen-chat') . '</h2>';
        $fileId = self::input('file', $_GET);
        if ($fileId !== '') {
            $file = $this->api->request('GET', '/files/' . Api::segment($fileId));
            if (is_wp_error($file)) { $this->error($file); return; }
            if (($file['id'] ?? null) !== $fileId) { $this->error(new \WP_Error('protocol', __('The service returned a different file.', 'dzen-chat'))); return; }
            echo '<p><a href="' . esc_url(self::url('dzen-chat-sources')) . '">← ' . esc_html__('All sources', 'dzen-chat') . '</a></p><h3>' . esc_html(self::text($file, 'name')) . '</h3>';
            echo '<p>' . esc_html(self::statusLabel($file)) . ' ' . esc_html(self::text($file, 'status_error')) . '</p><div class="dzen-text">' . esc_html(self::text($file, 'content')) . '</div>';
            return;
        }
        $id = self::input('source', $_GET);
        if ($id !== '') {
            $source = $this->api->request('GET', '/sources/' . Api::segment($id));
            if (is_wp_error($source)) { $this->error($source); return; }
            if (($source['id'] ?? null) !== $id) { $this->error(new \WP_Error('protocol', __('The service returned a different source.', 'dzen-chat'))); return; }
            echo '<p><a href="' . esc_url(self::url('dzen-chat-sources')) . '">← ' . esc_html__('All sources', 'dzen-chat') . '</a></p>';
            echo '<h3>' . esc_html(self::text($source, 'title')) . '</h3>';
            $this->external($source['url'] ?? null);
            $this->sourceState($source);
            echo '<p><a href="' . esc_url(self::url('dzen-chat-documents', ['source_id' => $id])) . '">' . esc_html__('Source documents', 'dzen-chat') . '</a></p>';
            return;
        }
        $items = $this->api->items('/sources');
        if (is_wp_error($items)) { $this->error($items); return; }
        if (!$items) echo '<p>' . esc_html__('No sources are available.', 'dzen-chat') . '</p>';
        foreach ($items as $source) {
            echo '<section class="dzen-message"><h3><a href="' . esc_url(self::url('dzen-chat-sources', ['source' => $source['id']])) . '">' . esc_html(self::text($source, 'title')) . '</a></h3>';
            $this->sourceState($source);
            $this->external($source['url'] ?? null);
            echo '</section>';
        }
        echo '<h3>' . esc_html__('Knowledge base files', 'dzen-chat') . '</h3>';
        $files = $this->api->items('/files');
        if (is_wp_error($files)) { $this->error($files); return; }
        if (!$files) echo '<p>' . esc_html__('No files have been added.', 'dzen-chat') . '</p>';
        foreach ($files as $file) {
            echo '<section class="dzen-message"><h4><a href="' . esc_url(self::url('dzen-chat-sources', ['file' => $file['id']])) . '">' . esc_html(self::text($file, 'name')) . '</a></h4><p>' . esc_html(self::statusLabel($file)) . ' ' . esc_html(self::text($file, 'status_error')) . '</p><p>' . esc_html(self::text($file, 'updated_at')) . '</p></section>';
        }
    }

    private function documents(): void
    {
        echo '<h2>' . esc_html__('Index', 'dzen-chat') . '</h2>';
        $id = self::input('document', $_GET);
        if ($id !== '') {
            $page = $this->api->request('GET', '/pages/' . Api::segment($id));
            if (is_wp_error($page)) { $this->error($page); return; }
            if (($page['id'] ?? null) !== $id) { $this->error(new \WP_Error('protocol', __('The service returned a different document.', 'dzen-chat'))); return; }
            echo '<p><a href="' . esc_url(self::url('dzen-chat-documents')) . '">← ' . esc_html__('All documents', 'dzen-chat') . '</a></p>';
            echo '<h3>' . esc_html(self::text($page, 'title') ?: self::text($page, 'url')) . '</h3>';
            $this->documentState($page);
            $this->documentLinks($page);
            return;
        }
        $filters = self::filters($_GET);
        if (is_wp_error($filters)) { $this->error($filters); return; }
        $items = $this->api->items('/pages', ['limit' => 100]);
        if (is_wp_error($items)) { $this->error($items); return; }
        echo '<p>' . esc_html__('Showing up to 100 recently updated documents. Filters apply to this set.', 'dzen-chat') . '</p>';
        echo '<form method="get" class="dzen-filters"><input type="hidden" name="page" value="dzen-chat-documents">';
        foreach (['q' => __('Title or URL', 'dzen-chat'), 'status' => __('Status', 'dzen-chat'), 'source_id' => __('Source ID', 'dzen-chat')] as $key => $label) {
            echo '<label>' . esc_html($label) . '<input name="' . esc_attr($key) . '" value="' . esc_attr($filters[$key] ?? '') . '"></label>';
        }
        echo '<button class="button"> ' . esc_html__('Apply', 'dzen-chat') . '</button></form>';
        $items = array_filter($items, static function ($item) use ($filters) {
            if (isset($filters['source_id']) && ($item['source_id'] ?? '') !== $filters['source_id']) return false;
            if (isset($filters['status']) && ($item['status'] ?? '') !== $filters['status']) return false;
            return !isset($filters['q']) || preg_match('/' . preg_quote($filters['q'], '/') . '/iu', self::text($item, 'title') . ' ' . self::text($item, 'url')) === 1;
        });
        if (!$items) echo '<p>' . esc_html__('No results in the loaded set.', 'dzen-chat') . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Document', 'dzen-chat') . '</th><th>' . esc_html__('State', 'dzen-chat') . '</th><th>' . esc_html__('Links and actions', 'dzen-chat') . '</th></tr></thead><tbody>';
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
        if (self::text($page, 'updated_at') !== '') echo '<p>' . esc_html__('Updated:', 'dzen-chat') . ' ' . esc_html(self::text($page, 'updated_at')) . '</p>';
        echo '<p>' . esc_html(($page['has_triggers'] ?? null) === true ? __('Triggers prepared', 'dzen-chat') : __('Triggers not prepared', 'dzen-chat')) . '</p>';
    }

    private function documentLinks(array $page): void
    {
        $this->external($page['url'] ?? null);
        $post = is_string($page['url'] ?? null) ? url_to_postid($page['url']) : 0;
        if ($post && current_user_can('edit_post', $post)) {
            echo '<p><a href="' . esc_url(get_edit_post_link($post, '')) . '">' . esc_html__('Edit in WordPress', 'dzen-chat') . '</a></p>';
        }
        if (self::text($page, 'source_id') !== '') {
            echo '<p><a href="' . esc_url(self::url('dzen-chat-sources', ['source' => $page['source_id']])) . '">' . esc_html__('View source in WordPress', 'dzen-chat') . '</a></p>';
        }
        if (($page['source_id'] ?? null) === $this->credentials->get()['site_source_id'] && self::publicUrl($page['url'] ?? null)) {
            $this->form('dzen_chat_action', 'dzen_chat_action', ['operation' => 'reindex', 'id' => $page['id']], __('Reindex', 'dzen-chat'));
        }
    }

    private function history(): void
    {
        echo '<h2>' . esc_html__('Conversations', 'dzen-chat') . '</h2><p>' . esc_html__('Conversations are stored in Dzen Chat.', 'dzen-chat') . '</p>';
        $id = self::input('chat', $_GET);
        if ($id !== '') { $this->chat($id); return; }
        $filters = self::filters($_GET);
        if (is_wp_error($filters)) { $this->error($filters); return; }
        $items = $this->api->items('/chats', ['limit' => 100]);
        if (is_wp_error($items)) { $this->error($items); return; }
        echo '<p>' . esc_html__('Filters apply to the 100 most recent conversations. Full history text search and hiding conversations are not yet available.', 'dzen-chat') . '</p>';
        echo '<form method="get" class="dzen-filters"><input type="hidden" name="page" value="dzen-chat-history">';
        echo '<label>' . esc_html__('Conversation or visitor ID', 'dzen-chat') . '<input name="q" value="' . esc_attr($filters['q'] ?? '') . '" maxlength="200"></label>';
        foreach (['date_from' => __('From date (UTC)', 'dzen-chat'), 'date_to' => __('To date (UTC)', 'dzen-chat')] as $key => $label) {
            echo '<label>' . esc_html($label) . '<input type="date" name="' . esc_attr($key) . '" value="' . esc_attr($filters[$key] ?? '') . '"></label>';
        }
        echo '<label>' . esc_html__('Widget', 'dzen-chat') . '<select name="widget_code"><option value="">' . esc_html__('All widgets', 'dzen-chat') . '</option>';
        $widgets = $this->api->items('/widgets');
        if (!is_wp_error($widgets)) foreach ($widgets as $widget) {
            echo '<option value="' . esc_attr(self::text($widget, 'code')) . '"' . selected($filters['widget_code'] ?? '', self::text($widget, 'code'), false) . '>' . esc_html(self::text($widget, 'name')) . '</option>';
        }
        echo '</select></label><button class="button">' . esc_html__('Apply', 'dzen-chat') . '</button></form>';
        $items = array_filter($items, static function ($item) use ($filters) {
            $date = substr(self::text($item, 'created_at'), 0, 10);
            if (isset($filters['date_from']) && $date < $filters['date_from']) return false;
            if (isset($filters['date_to']) && $date > $filters['date_to']) return false;
            if (isset($filters['widget_code']) && ($item['widget_code'] ?? '') !== $filters['widget_code']) return false;
            return !isset($filters['q']) || stripos(self::text($item, 'id') . ' ' . self::text($item, 'visitor'), $filters['q']) !== false;
        });
        if (!$items) echo '<p>' . esc_html__('No results in the loaded set.', 'dzen-chat') . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Conversation', 'dzen-chat') . '</th><th>' . esc_html__('Visitor', 'dzen-chat') . '</th><th>' . esc_html__('Created (UTC)', 'dzen-chat') . '</th></tr></thead><tbody>';
        foreach ($items as $chat) {
            echo '<tr><td><a href="' . esc_url(self::url('dzen-chat-history', ['chat' => $chat['id']])) . '">' . esc_html(self::text($chat, 'id')) . '</a></td><td>' . esc_html(self::text($chat, 'visitor')) . '</td><td>' . esc_html(self::text($chat, 'created_at')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function chat(string $id): void
    {
        echo '<p><a href="' . esc_url(self::url('dzen-chat-history')) . '">← ' . esc_html__('All conversations', 'dzen-chat') . '</a></p>';
        $chat = $this->api->request('GET', '/chats/' . Api::segment($id));
        if (is_wp_error($chat)) { $this->error($chat); return; }
        if (($chat['id'] ?? null) !== $id) { $this->error(new \WP_Error('protocol', __('The service returned a different conversation.', 'dzen-chat'))); return; }
        $items = $this->api->items('/chats/' . Api::segment($id) . '/messages', ['limit' => 100]);
        if (is_wp_error($items)) { $this->error($items); return; }
        echo '<h3>' . esc_html($id) . '</h3>';
        if (count($items) === 100) echo '<p>' . esc_html__('The first 100 messages have been loaded. Open Dzen Chat to view the rest.', 'dzen-chat') . '</p>';
        foreach ($items as $message) {
            if (!in_array($message['role'] ?? '', ['user', 'assistant'], true)) continue;
            echo '<article class="dzen-message"><h3>' . esc_html($message['role'] === 'user' ? __('Visitor', 'dzen-chat') : __('Dzen Chat', 'dzen-chat')) . '</h3><p class="description">' . esc_html(self::text($message, 'created_at')) . '</p><div class="dzen-text">' . esc_html(self::text($message, 'text')) . '</div>';
            if (is_bool($message['vote'] ?? null)) echo '<p>' . esc_html($message['vote'] ? __('Helpful answer', 'dzen-chat') : __('Unhelpful answer', 'dzen-chat')) . '</p>';
            echo '</article>';
        }
    }

    private function external(mixed $url, ?string $label = null): void
    {
        $safe = self::publicUrl($url);
        if ($safe !== '') echo '<a class="dzen-external" href="' . $safe . '" target="_blank" rel="noopener noreferrer">' . esc_html($label ?? __('Open original ↗', 'dzen-chat')) . '</a>';
    }

    private function widgets(): void
    {
        echo '<h2>' . esc_html__('Widgets', 'dzen-chat') . '</h2>';
        $widgets = (new Widgets($this->credentials, $this->api))->all();
        if (is_wp_error($widgets)) {
            $this->error($widgets);
            echo '<p><a class="button" href="' . esc_url(self::url('dzen-chat-widgets')) . '">' . esc_html__('Refresh list', 'dzen-chat') . '</a></p>';
            echo '<p><a href="' . esc_url(self::url('dzen-chat')) . '">' . esc_html__('Connection settings', 'dzen-chat') . '</a></p>';
            return;
        }
        echo '<p>' . esc_html__('Enabling a widget and changing its settings affects the entire Dzen Chat project. Website placement is configured separately in WordPress.', 'dzen-chat') . '</p>';
        if (!get_option('dzen_chat_widget')) {
            echo '<p>' . esc_html__('No widget is selected for this site. Enable a widget and click “Place on site”.', 'dzen-chat') . '</p>';
        } elseif (!isset($widgets[get_option('dzen_chat_widget')])) {
            echo '<p>' . esc_html__('The previously selected widget is no longer available. Select another widget for this site.', 'dzen-chat') . '</p>';
        }
        foreach ($widgets as $widget) {
            echo '<section class="dzen-message"><h3>' . esc_html($widget['name']) . '</h3><p>' . esc_html($widget['is_enabled'] ? __('Enabled in Dzen Chat', 'dzen-chat') : __('Disabled in Dzen Chat — hidden on the site', 'dzen-chat')) . '</p>';
            if (get_option('dzen_chat_widget') === $widget['id']) {
                echo '<p><strong>' . esc_html__('Selected for this site', 'dzen-chat') . '</strong></p>';
            }
            $this->form('dzen_chat_action', 'dzen_chat_action', ['operation' => 'widget_toggle', 'id' => $widget['id'],
                'enabled' => $widget['is_enabled'] ? '0' : '1'], $widget['is_enabled'] ? __('Disable in Dzen Chat', 'dzen-chat') : __('Enable in Dzen Chat', 'dzen-chat'));
            if ($widget['is_enabled'] && get_option('dzen_chat_widget') !== $widget['id']) {
                $this->form('dzen_chat_action', 'dzen_chat_action', ['operation' => 'widget_select', 'id' => $widget['id']], __('Place on site', 'dzen-chat'));
            }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('dzen_chat_action');
            echo '<input type="hidden" name="action" value="dzen_chat_action"><input type="hidden" name="operation" value="widget_edit"><input type="hidden" name="id" value="' . esc_attr($widget['id']) . '">';
            echo '<p><label>' . esc_html__('Name', 'dzen-chat') . ' <input name="name" required maxlength="128" value="' . esc_attr($widget['name']) . '"></label></p>';
            echo '<p><label><input type="checkbox" name="suggestions_enabled" value="1"' . checked($widget['suggestions_enabled'], true, false) . '> ' . esc_html__('Suggest follow-up questions after an answer', 'dzen-chat') . '</label></p>';
            echo '<button class="button">' . esc_html__('Save settings', 'dzen-chat') . '</button></form></section>';
        }
        echo '<p><a class="button" href="' . esc_url(home_url('/')) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Open site to check the widget', 'dzen-chat') . '</a></p>';
        $this->form('dzen_chat_action', 'dzen_chat_action', ['operation' => 'widget_select', 'id' => ''], __('Remove the site-wide widget', 'dzen-chat'));
        echo '<p>' . esc_html__('In the page or post editor, you can select another widget or hide it on that page.', 'dzen-chat') . '</p>';
        echo '<h3>' . esc_html__('Create widget', 'dzen-chat') . '</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('dzen_chat_action');
        echo '<input type="hidden" name="action" value="dzen_chat_action"><input type="hidden" name="operation" value="widget_create"><label>' . esc_html__('Name', 'dzen-chat') . ' <input name="name" required maxlength="128"></label> <button class="button button-primary">' . esc_html__('Create', 'dzen-chat') . '</button></form>';
        echo '<p>' . esc_html__('Before enabling the widget, remove any manually added Dzen Chat script to prevent it from loading twice.', 'dzen-chat') . '</p>';
    }


    public function action(): void
    {
        Connection::authorize('dzen_chat_action');
        try { $this->credentials->get(); }
        catch (\RuntimeException | \JsonException $error) {
            wp_die(esc_html__('Reconnect the site in the Dzen Chat section.', 'dzen-chat'), '', ['response' => 409]);
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
                    wp_die(esc_html__('This page does not belong to this site\'s source.', 'dzen-chat'), '', ['response' => 403]);
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
                        $result = new \WP_Error('disabled', __('Enable the widget before placing it on the site.', 'dzen-chat'));
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
                if (!preg_match('/\A.{1,128}\z/us', $name)) wp_die(esc_html__('Enter a widget name.', 'dzen-chat'), '', ['response' => 400]);
                $changes = ['name' => $name];
                if ($operation === 'widget_edit') $changes['suggestions_enabled'] = self::input('suggestions_enabled', $_POST) === '1';
                $result = $widgets->save($operation === 'widget_create' ? null : $id, $changes);
                break;
            default:
                wp_die(esc_html__('Unknown action.', 'dzen-chat'), '', ['response' => 400]);
        }
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()), '', ['response' => 502, 'back_link' => true]);
        wp_safe_redirect(self::url($destination, ['notice' => $notice]), 303);
        exit;
    }
}
