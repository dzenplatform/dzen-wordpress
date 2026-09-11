<?php
namespace DzenChat;

defined('ABSPATH') || exit;

final class PageIndex
{
    public function __construct(private Credentials $credentials, private Api $api) {}

    public function register(): void
    {
        add_action('wp_ajax_dzen_chat_page_status', [$this, 'ajax']);
        add_action('admin_enqueue_scripts', static function ($hook) {
            $screen = get_current_screen();
            if (in_array($hook, ['post.php', 'post-new.php'], true)
                && in_array($screen->post_type ?? '', ['page', 'post'], true) && current_user_can('manage_options')) {
                wp_enqueue_style('dzen-chat-page-index', plugins_url('assets/page-index.css', DZEN_CHAT_FILE), [], DZEN_CHAT_VERSION);
                wp_enqueue_script('dzen-chat-page-index', plugins_url('assets/page-index.js', DZEN_CHAT_FILE), ['wp-data'], DZEN_CHAT_VERSION, true);
            }
        });
    }

    public static function render(\WP_Post $post): void
    {
        echo '<section class="dzen-page-index" data-post-id="' . esc_attr((string) $post->ID) . '" data-nonce="' . esc_attr(wp_create_nonce('dzen_chat_page_status_' . $post->ID)) . '" data-endpoint="' . esc_url(admin_url('admin-ajax.php')) . '" data-loading="' . esc_attr__('Checking indexing status…', 'dzen-chat') . '" data-error="' . esc_attr__('Could not load indexing status. Try again.', 'dzen-chat') . '">';
        echo '<h3>' . esc_html__('Indexing status', 'dzen-chat') . '</h3><div class="dzen-page-index-result" aria-live="polite" aria-atomic="true">';
        echo '<p><strong class="dzen-page-index-label">' . esc_html__('Checking indexing status…', 'dzen-chat') . '</strong></p><p class="dzen-page-index-description"></p><p class="dzen-page-index-sync"></p></div>';
        echo '<p class="dzen-page-index-actions"><button type="button" class="button dzen-page-index-refresh">' . esc_html__('Refresh status', 'dzen-chat') . '</button> <a href="' . esc_url(Admin::url('dzen-chat-documents')) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Source status ↗', 'dzen-chat') . '</a></p>';
        echo '<p class="description">' . esc_html__('Status applies to the saved page. Unsaved changes are not indexed.', 'dzen-chat') . '</p></section>';
    }

    public function ajax(): void
    {
        $id = isset($_POST['post_id']) && is_string($_POST['post_id']) && ctype_digit($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (!$id || !current_user_can('manage_options') || !current_user_can('edit_post', $id)) {
            wp_send_json_error(['message' => __('You cannot view this page status.', 'dzen-chat')], 403);
        }
        if (!isset($_POST['_ajax_nonce']) || !is_string($_POST['_ajax_nonce'])
            || !wp_verify_nonce(wp_unslash($_POST['_ajax_nonce']), 'dzen_chat_page_status_' . $id)) {
            wp_send_json_error(['message' => __('The status request expired. Reload the editor.', 'dzen-chat')], 403);
        }
        $post = get_post($id);
        if (!$post || !in_array($post->post_type, ['page', 'post'], true)) {
            wp_send_json_error(['message' => __('This content type is not supported.', 'dzen-chat')], 400);
        }
        $result = $this->status($post);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 502);
        }
        wp_send_json_success($result);
    }

    public function status(\WP_Post $post): array|\WP_Error
    {
        try {
            $connection = $this->credentials->get();
        } catch (\RuntimeException | \JsonException $error) {
            return new \WP_Error('dzen_connection', __('Reconnect the site in the Dzen Chat section.', 'dzen-chat'));
        }
        global $wpdb;
        [, $events] = Sync::tables();
        $sync = $wpdb->get_var($wpdb->prepare("SELECT status FROM $events WHERE post_id=%d AND integration_id=%s ORDER BY id DESC LIMIT 1", $post->ID, $connection['client_id']));
        $syncLabels = [
            'pending' => __('Saved changes are waiting to be sent to Dzen Chat.', 'dzen-chat'),
            'accepted' => __('The last saved change was submitted. This does not confirm indexing is complete.', 'dzen-chat'),
            'blocked' => __('Sending saved changes is waiting for service access.', 'dzen-chat'),
            'error' => __('The last saved change could not be sent. Check the Dzen Chat overview.', 'dzen-chat'),
        ];
        $result = ['state' => 'not_public', 'label' => __('Not public', 'dzen-chat'), 'description' => '', 'sync' => $syncLabels[$sync] ?? ''];
        if ($post->post_status !== 'publish' || $post->post_password !== '' || get_post_meta($post->ID, '_dzen_chat_exclude', true)) {
            $result['description'] = $post->post_status !== 'publish'
                ? __('Only published pages and posts are sent for indexing.', 'dzen-chat')
                : ($post->post_password !== '' ? __('Password-protected content is not sent for indexing.', 'dzen-chat')
                    : __('This page is excluded from automatic indexing.', 'dzen-chat'));
            if ($sync !== null) $result['description'] .= ' ' . __('Previously public URLs are rechecked; removal from the index is not immediate.', 'dzen-chat');
            return $result;
        }
        $page = $this->api->pageForUrl($connection['site_source_id'], get_permalink($post));
        if (is_wp_error($page)) return $page;
        if ($page === null) return array_replace($result, ['state' => 'not_found', 'label' => __('Not found in the index', 'dzen-chat'),
            'description' => __('Dzen Chat has no page at this URL in the connected source yet.', 'dzen-chat')]);
        $labels = [
            'pending' => __('Waiting to be crawled', 'dzen-chat'), 'processing' => __('Processing', 'dzen-chat'),
            'ready' => __('Ready in Dzen Chat', 'dzen-chat'), 'errors' => __('Indexing error', 'dzen-chat'),
            'excluded' => __('Excluded from indexing', 'dzen-chat'),
        ];
        return array_replace($result, ['state' => $page['index_status'], 'label' => $labels[$page['index_status']]]);
    }
}
