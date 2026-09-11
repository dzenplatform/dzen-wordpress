<?php
namespace DzenChat;

defined('ABSPATH') || exit;

final class Plugin
{
    public function register(): void
    {
        add_action('init', [self::class, 'translations'], 0);
        $credentials = new Credentials();
        $api = new Api($credentials);
        $sync = new Sync($credentials, $api);
        (new Connection($credentials, $api))->register();
        (new Admin($credentials, $api, $sync))->register();
        $sync->register();
        add_filter('cron_schedules', [self::class, 'schedules']);
        add_action('dzen_chat_clean_claim', static function ($key) {
            if (is_string($key) && preg_match('/^dzen_chat_auth_claim_[a-f0-9]{64}$/D', $key)) {
                delete_option($key);
            }
        });
        add_action('wp_enqueue_scripts', [$this, 'widget']);
        add_action('wp_footer', [$this, 'widgetContainer'], 5);
        add_action('add_meta_boxes', [$this, 'metaBoxes']);
        add_action('save_post', [$this, 'saveMeta'], 10, 2);
    }

    public static function translations(): void
    {
        // Let WordPress select the current user's admin locale or the site locale.
        load_plugin_textdomain('dzen-chat', false, dirname(plugin_basename(DZEN_CHAT_FILE)) . '/languages');
    }

    public static function schedules(array $schedules): array
    {
        $schedules['dzen_chat_minute'] = ['interval' => 60, 'display' => __('Dzen Chat: every minute', 'dzen-chat')];
        return $schedules;
    }

    public static function activate(bool $networkWide = false): void
    {
        if ($networkWide) {
            wp_die(esc_html__('Connect Dzen Chat separately for each site. Network activation is not supported yet.', 'dzen-chat'));
        }
        Sync::install();
        delete_option('dzen_chat_sync_client');
        add_filter('cron_schedules', [self::class, 'schedules']);
        if (!wp_next_scheduled('dzen_chat_worker')) {
            wp_schedule_event(time() + 60, 'dzen_chat_minute', 'dzen_chat_worker');
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('dzen_chat_worker');
        delete_option('dzen_chat_worker_lock');
        delete_transient('dzen_chat_status');
        delete_transient('dzen_chat_status_retry');
    }

    public function widget(): void
    {
        $credentials = new Credentials();
        if (!$credentials->exists()) {
            return;
        }
        try {
            $credentials->get(); // A copied database must not send as the original site.
        } catch (\RuntimeException | \JsonException $error) {
            return;
        }
        $post = get_queried_object_id();
        if (is_singular() && get_post_meta($post, '_dzen_chat_widget_disabled', true)) {
            return;
        }
        $id = is_singular() ? get_post_meta($post, '_dzen_chat_widget', true) : '';
        $id = $id ?: get_option('dzen_chat_widget', '');
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id)) {
            return;
        }
        $widget = (new Widgets($credentials, new Api($credentials)))->cached($id);
        if (is_wp_error($widget) || !$widget['is_enabled']) {
            return;
        }
        wp_enqueue_script('dzen-chat-widget', Api::origin() . '/widget/' . rawurlencode($widget['code']), [], null,
            ['strategy' => 'defer', 'in_footer' => true]);
    }

    public function widgetContainer(): void
    {
        if (!is_admin() && wp_script_is('dzen-chat-widget', 'enqueued')) {
            // The Dzen loader requires this mount point before its script runs.
            echo '<div id="chat-chat"></div>';
        }
    }

    public function metaBoxes(): void
    {
        if (current_user_can('manage_options')) {
            add_meta_box('dzen-chat-page', 'Dzen Chat', [$this, 'metaBox'], ['page', 'post'], 'side');
        }
    }

    public function metaBox(\WP_Post $post): void
    {
        try {
            (new Credentials())->get();
        } catch (\RuntimeException | \JsonException $error) {
            echo '<p>' . esc_html__('Reconnect the site in the Dzen Chat section.', 'dzen-chat') . '</p>';
            return;
        }
        wp_nonce_field('dzen_chat_post_' . $post->ID, 'dzen_chat_post_nonce');
        echo '<p><label><input type="checkbox" name="_dzen_chat_widget_disabled" value="1"' . checked((bool) get_post_meta($post->ID, '_dzen_chat_widget_disabled', true), true, false) . '> ' . esc_html__('Hide widget', 'dzen-chat') . '</label></p>';
        echo '<p><label>' . esc_html__('Widget', 'dzen-chat') . '<br><select name="_dzen_chat_widget"><option value="">' . esc_html__('Use the site-wide widget', 'dzen-chat') . '</option>';
        foreach (get_option('dzen_chat_widgets', []) as $id => $widget) {
            echo '<option value="' . esc_attr($id) . '"' . selected(get_post_meta($post->ID, '_dzen_chat_widget', true), $id, false) . '>' . esc_html($widget['name']) . '</option>';
        }
        echo '</select></label></p><p class="description">' . esc_html__('These widget settings apply only to this page.', 'dzen-chat') . '</p>';
    }

    public function saveMeta(int $id, \WP_Post $post): void
    {
        if (!in_array($post->post_type, ['page', 'post'], true) || wp_is_post_revision($id)
            || wp_is_post_autosave($id) || !current_user_can('manage_options') || !current_user_can('edit_post', $id)
            || !isset($_POST['dzen_chat_post_nonce']) || !is_string($_POST['dzen_chat_post_nonce'])
            || !wp_verify_nonce(wp_unslash($_POST['dzen_chat_post_nonce']), 'dzen_chat_post_' . $id)) {
            return;
        }
        try {
            (new Credentials())->get();
        } catch (\RuntimeException | \JsonException $error) {
            return;
        }
        update_post_meta($id, '_dzen_chat_widget_disabled', isset($_POST['_dzen_chat_widget_disabled']) && $_POST['_dzen_chat_widget_disabled'] === '1' ? '1' : '');
        $widget = isset($_POST['_dzen_chat_widget']) && is_string($_POST['_dzen_chat_widget']) ? wp_unslash($_POST['_dzen_chat_widget']) : '';
        if ($widget === '' || isset(get_option('dzen_chat_widgets', [])[$widget])) {
            update_post_meta($id, '_dzen_chat_widget', $widget);
        }
    }
}
