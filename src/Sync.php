<?php
namespace DzenChat;

defined('ABSPATH') || exit;

final class Sync
{
    public function __construct(private Credentials $credentials, private Api $api) {}

    public static function tables(): array
    {
        global $wpdb;
        return [$wpdb->prefix . 'dzen_chat_objects', $wpdb->prefix . 'dzen_chat_events'];
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        [$objects, $events] = self::tables();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $objects (
            post_id bigint(20) unsigned NOT NULL,
            integration_id varchar(128) NOT NULL,
            revision bigint(20) unsigned NOT NULL DEFAULT 0,
            fingerprint varchar(64) NOT NULL DEFAULT '',
            url text NOT NULL,
            PRIMARY KEY  (post_id,integration_id)
        ) $charset;");
        dbDelta("CREATE TABLE $events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id varchar(36) NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            integration_id varchar(128) NOT NULL,
            payload longtext NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'pending',
            operation_id varchar(128) NOT NULL DEFAULT '',
            attempts int NOT NULL DEFAULT 0,
            next_attempt bigint(20) NOT NULL DEFAULT 0,
            error_code varchar(100) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_id (event_id),
            KEY ready (status,next_attempt),
            KEY post_id (post_id)
        ) $charset;");
    }

    public function register(): void
    {
        add_action('wp_after_insert_post', [$this, 'saved'], 20, 4);
        add_action('before_delete_post', [$this, 'deleted'], 10, 2);
        add_action('update_option_permalink_structure', [$this, 'reconcile']);
        add_action('dzen_chat_worker', [$this, 'run']);
    }

    public function reconcile(): void
    {
        update_option('dzen_chat_reconcile_cursor', 0, false);
    }

    public function saved(int $id, \WP_Post $post, bool $update, ?\WP_Post $before): void
    {
        if (wp_is_post_autosave($id) || wp_is_post_revision($id)
            || !in_array($post->post_type, ['page', 'post'], true) || !$this->credentials->exists()) {
            return;
        }
        $public = $post->post_status === 'publish' && $post->post_password === ''
            && !get_post_meta($id, '_dzen_chat_exclude', true);
        $this->enqueue($post, $public);
        if ($before && $post->post_type === 'page'
            && ($before->post_name !== $post->post_name || $before->post_parent !== $post->post_parent)) {
            $this->reconcile(); // Descendant page URLs may have changed too.
        }
    }

    public function deleted(int $id, \WP_Post $post): void
    {
        if (in_array($post->post_type, ['post', 'page'], true) && $this->credentials->exists()) {
            $this->enqueue($post, false);
        }
    }

    private function enqueue(\WP_Post $post, bool $public): void
    {
        global $wpdb;
        [$objects, $events] = self::tables();
        $url = $public ? get_permalink($post) : '';
        $policy = get_post_meta($post->ID, '_dzen_chat_triggers', true) ?: 'inherit';
        $fingerprint = hash('sha256', wp_json_encode([$url, $public ? [$post->post_modified_gmt,
            $post->post_title, $post->post_content, $post->post_excerpt] : '', $policy]));
        try {
            if ($this->credentials->registrationOnly()) {
                return;
            }
            $integration = $this->credentials->get()['integration_id'];
        } catch (\RuntimeException | \JsonException $error) {
            update_option('dzen_chat_queue_error', true, false);
            return;
        }
        $wpdb->query('START TRANSACTION');
        try {
            $inserted = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $objects (post_id,integration_id,url) VALUES (%d,%s,'')", $post->ID, $integration));
            if ($inserted === false) {
                throw new \RuntimeException('queue_storage');
            }
            $current = $wpdb->get_row($wpdb->prepare("SELECT * FROM $objects WHERE post_id=%d AND integration_id=%s FOR UPDATE", $post->ID, $integration), ARRAY_A);
            if (!$current) {
                throw new \RuntimeException('queue_storage');
            }
            if ($current['fingerprint'] === $fingerprint || (!$public && (int) $current['revision'] === 0)) {
                $wpdb->query('COMMIT');
                return;
            }
            $payload = ['event_id' => wp_generate_uuid4(), 'external_id' => 'wp:post:' . $post->ID,
                'revision' => (int) $current['revision'] + 1, 'action' => $public ? 'upsert' : 'delete',
                'url' => $public ? $url : $current['url'], 'previous_url' => $current['url'],
                'trigger_policy' => $policy];
            if ($wpdb->insert($events, ['event_id' => $payload['event_id'], 'post_id' => $post->ID, 'integration_id' => $integration,
                'payload' => wp_json_encode($payload), 'created_at' => current_time('mysql', true)]) === false
                || $wpdb->update($objects, ['url' => $url ?: $current['url'], 'revision' => $payload['revision'],
                    'fingerprint' => $fingerprint], ['post_id' => $post->ID, 'integration_id' => $integration]) === false) {
                throw new \RuntimeException('queue_storage');
            }
            $wpdb->query('COMMIT');
        } catch (\RuntimeException $error) {
            $wpdb->query('ROLLBACK');
            update_option('dzen_chat_queue_error', true, false);
        }
    }

    public function run(): void
    {
        global $wpdb;
        if (!$this->credentials->exists() || !get_option('dzen_chat_verified')) {
            return;
        }
        $lock = time() . ':' . wp_generate_uuid4();
        $old = (string) get_option('dzen_chat_worker_lock', '');
        if ($old !== '' && (int) $old < time() - 600) {
            $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name=%s AND option_value=%s", 'dzen_chat_worker_lock', $old));
            wp_cache_delete('dzen_chat_worker_lock', 'options');
        }
        if (!add_option('dzen_chat_worker_lock', $lock, '', false)) {
            return;
        }
        try {
            $status = $this->api->status();
            if (is_wp_error($status)) {
                return;
            }
            $integration = $status['integration']['id'];
            $cursor = get_option('dzen_chat_reconcile_cursor', false);
            if ($cursor !== false) {
                $posts = $wpdb->get_results($wpdb->prepare("SELECT * FROM $wpdb->posts WHERE ID > %d AND post_type IN ('page','post') ORDER BY ID LIMIT 50", (int) $cursor));
                foreach ($posts as $row) {
                    $post = new \WP_Post($row);
                    $this->saved($post->ID, $post, false, null);
                }
                if (count($posts) < 50) {
                    delete_option('dzen_chat_reconcile_cursor');
                } else {
                    update_option('dzen_chat_reconcile_cursor', (int) end($posts)->ID, false);
                }
            }
            [, $events] = self::tables();
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $events WHERE integration_id=%s AND status IN ('pending','accepted','blocked') AND next_attempt <= %d ORDER BY id LIMIT 10", $integration, time()), ARRAY_A);
            foreach ($rows as $row) {
                $payload = json_decode($row['payload'], true);
                $permission = $payload['action'] === 'delete' ? 'documents_delete' : 'documents_upsert';
                if (($status['allowed_operations'][$permission] ?? false) !== true) {
                    $wpdb->update($events, ['status' => 'blocked', 'next_attempt' => time() + 300], ['id' => $row['id']]);
                    continue;
                }
                $response = $row['operation_id'] !== ''
                    ? $this->api->request('GET', '/operations/' . Api::segment($row['operation_id']))
                    : $this->api->request('POST', '/document-events', [], $payload, $row['event_id']);
                $changes = [];
                if (is_wp_error($response)) {
                    $error = $response->get_error_data() ?: [];
                    $changes['attempts'] = (int) $row['attempts'] + 1;
                    $changes += ['error_code' => $response->get_error_code(),
                        'status' => !empty($error['retryable']) && $changes['attempts'] < 6 ? 'pending' : 'error',
                        'next_attempt' => time() + max((int) ($error['retry_after'] ?? 0), min(3600, 30 * 2 ** $changes['attempts']))];
                } elseif ($row['operation_id'] === '' && !empty($response['operation_id'])) {
                    $changes += ['status' => 'accepted', 'operation_id' => $response['operation_id'],
                        'next_attempt' => time() + 30, 'error_code' => '', 'attempts' => 0];
                } elseif ($row['operation_id'] !== '' && in_array($response['status'] ?? '', ['succeeded', 'superseded'], true)) {
                    $changes += ['status' => 'done', 'error_code' => ''];
                } elseif ($row['operation_id'] !== '' && in_array($response['status'] ?? '', ['queued', 'running'], true)) {
                    $changes += ['status' => 'accepted', 'next_attempt' => time() + 60, 'attempts' => 0];
                } else {
                    $changes += ['status' => 'error', 'error_code' => 'operation_failed'];
                }
                $wpdb->update($events, $changes, ['id' => $row['id']]);
            }
            update_option('dzen_chat_worker_ran', time(), false);
        } finally {
            $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name=%s AND option_value=%s", 'dzen_chat_worker_lock', $lock));
            wp_cache_delete('dzen_chat_worker_lock', 'options');
        }
    }

    public function retry(): void
    {
        global $wpdb;
        [, $events] = self::tables();
        try {
            $integration = $this->credentials->get()['integration_id'];
        } catch (\RuntimeException | \JsonException $error) {
            return;
        }
        $wpdb->query($wpdb->prepare("UPDATE $events SET status='pending', attempts=0, next_attempt=0 WHERE integration_id=%s AND status IN ('error','blocked')", $integration));
        delete_option('dzen_chat_queue_error');
        $this->reconcile();
    }
}
