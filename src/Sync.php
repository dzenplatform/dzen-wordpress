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
        add_action('init', [$this, 'initialize']);
        add_action('wp_after_insert_post', [$this, 'saved'], 20, 4);
        add_action('before_delete_post', [$this, 'deleted'], 10, 2);
        add_action('update_option_permalink_structure', [$this, 'reconcile']);
        add_action('dzen_chat_worker', [$this, 'run']);
    }

    /** Also starts the initial scan when an already connected 0.3 site upgrades. */
    public function initialize(): void
    {
        if (!$this->credentials->exists()) return;
        try {
            $client = $this->credentials->get()['client_id'];
        } catch (\RuntimeException | \JsonException $error) {
            return;
        }
        if (get_option('dzen_chat_sync_client') !== $client) {
            $this->reconcile();
            update_option('dzen_chat_sync_client', $client, false);
        }
        if (!wp_next_scheduled('dzen_chat_worker')) {
            wp_schedule_event(time() + 60, 'dzen_chat_minute', 'dzen_chat_worker');
        }
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
        $public = $post->post_status === 'publish' && $post->post_password === '';
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

    /** The same object lock serializes saves, exclusions and active HTTP submissions. */
    public function exclude(\WP_Post $post): ?\WP_Error
    {
        return $this->enqueue($post, $post->post_status === 'publish' && $post->post_password === '', true);
    }

    private static function isExcluded(int $postId): bool
    {
        global $wpdb;
        // A long-running worker must not use a post-meta value cached before the button click.
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM $wpdb->postmeta WHERE post_id=%d AND meta_key='_dzen_chat_exclude' LIMIT 1", $postId));
    }

    private function enqueue(\WP_Post $post, bool $public, bool $exclude = false): ?\WP_Error
    {
        global $wpdb;
        [$objects, $events] = self::tables();
        $url = $public ? get_permalink($post) : '';
        $fingerprint = hash('sha256', wp_json_encode([$url, $public ? [$post->post_modified_gmt,
            $post->post_title, $post->post_content, $post->post_excerpt] : '']));
        try {
            $integration = $this->credentials->get()['client_id'];
        } catch (\RuntimeException | \JsonException $error) {
            update_option('dzen_chat_queue_error', true, false);
            return new \WP_Error('dzen_connection', __('Reconnect the site in the Dzen Chat section.', 'dzen-chat'));
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
            if ($exclude && !self::isExcluded($post->ID)) {
                $updated = $wpdb->update($wpdb->postmeta, ['meta_value' => '1'], ['post_id' => $post->ID, 'meta_key' => '_dzen_chat_exclude']);
                if ($updated === false || ($updated === 0 && $wpdb->insert($wpdb->postmeta, ['post_id' => $post->ID, 'meta_key' => '_dzen_chat_exclude', 'meta_value' => '1']) === false)) {
                    throw new \RuntimeException('queue_storage');
                }
            }
            $excluded = self::isExcluded($post->ID);
            if ($excluded) $fingerprint = hash('sha256', wp_json_encode(['exclude', $url ?: $current['url']]));
            if ($current['fingerprint'] === $fingerprint || (!$excluded && !$public && (int) $current['revision'] === 0)) {
                if ($exclude) {
                    $wpdb->query($wpdb->prepare("UPDATE $events SET status='pending', attempts=0, next_attempt=0 WHERE post_id=%d AND integration_id=%s AND status IN ('pending','error','blocked')", $post->ID, $integration));
                }
                $wpdb->query('COMMIT');
                wp_cache_delete($post->ID, 'post_meta');
                return null;
            }
            $payload = ['event_id' => wp_generate_uuid4(), 'external_id' => 'wp:post:' . $post->ID,
                'revision' => (int) $current['revision'] + 1, 'action' => $public ? 'upsert' : 'delete',
                'url' => $public ? $url : $current['url'], 'previous_url' => $current['url']];
            if ($excluded) {
                $urls = [$url, $current['url']];
                foreach ($wpdb->get_col($wpdb->prepare("SELECT payload FROM $events WHERE post_id=%d AND integration_id=%s", $post->ID, $integration)) as $stored) {
                    $old = json_decode($stored, true);
                    $urls = array_merge($urls, [$old['url'] ?? '', $old['previous_url'] ?? ''], $old['urls'] ?? []);
                }
                $payload['action'] = 'exclude';
                $payload['urls'] = array_values(array_unique(array_filter($urls, static fn ($url) => is_string($url) && $url !== '')));
                if ($wpdb->query($wpdb->prepare("UPDATE $events SET status='cancelled', error_code='' WHERE post_id=%d AND integration_id=%s AND status IN ('pending','blocked','error')", $post->ID, $integration)) === false) {
                    throw new \RuntimeException('queue_storage');
                }
            }
            if ($wpdb->insert($events, ['event_id' => $payload['event_id'], 'post_id' => $post->ID, 'integration_id' => $integration,
                'payload' => wp_json_encode($payload), 'status' => $excluded && !$payload['urls'] ? 'excluded' : 'pending',
                'created_at' => current_time('mysql', true)]) === false
                || $wpdb->update($objects, ['url' => $url ?: $current['url'], 'revision' => $payload['revision'],
                    'fingerprint' => $fingerprint], ['post_id' => $post->ID, 'integration_id' => $integration]) === false) {
                throw new \RuntimeException('queue_storage');
            }
            $wpdb->query('COMMIT');
            wp_cache_delete($post->ID, 'post_meta');
            return null;
        } catch (\RuntimeException $error) {
            $wpdb->query('ROLLBACK');
            update_option('dzen_chat_queue_error', true, false);
            return new \WP_Error('dzen_queue', __('Could not save the indexing exclusion. Try again.', 'dzen-chat'));
        }
    }

    public function run(?int $onlyPost = null): void
    {
        global $wpdb;
        if (!$this->credentials->exists()) {
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
            try {
                $connection = $this->credentials->get();
                $integration = $connection['client_id'];
            } catch (\RuntimeException | \JsonException $error) {
                update_option('dzen_chat_queue_error', true, false);
                return;
            }
            $cursor = get_option('dzen_chat_reconcile_cursor', false);
            if ($cursor !== false && $onlyPost === null) {
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
            [$objects, $events] = self::tables();
            $postFilter = $onlyPost === null ? '' : $wpdb->prepare(' AND post_id=%d', $onlyPost);
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $events WHERE integration_id=%s AND status IN ('pending','blocked') AND next_attempt <= %d $postFilter ORDER BY id LIMIT 10", $integration, time()), ARRAY_A);
            foreach ($rows as $row) {
                $wpdb->query('START TRANSACTION');
                $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $objects WHERE post_id=%d AND integration_id=%s FOR UPDATE", $row['post_id'], $integration));
                $state = $wpdb->get_var($wpdb->prepare("SELECT status FROM $events WHERE id=%d", $row['id']));
                if (!in_array($state, ['pending', 'blocked'], true)) {
                    $wpdb->query('COMMIT');
                    continue;
                }
                $payload = json_decode($row['payload'], true);
                $excluded = ($payload['action'] ?? '') === 'exclude';
                if (!$excluded && self::isExcluded((int) $row['post_id'])) {
                    $wpdb->update($events, ['status' => 'cancelled'], ['id' => $row['id']]);
                    $wpdb->query('COMMIT');
                    continue;
                }
                // A permalink change requires revisiting the old public URL as well.
                $urls = $excluded ? $payload['urls'] : array_values(array_unique(array_filter([
                    $payload['previous_url'] ?? '', $payload['url'] ?? '',
                ], 'is_string')));
                $urls = array_values(array_filter($urls, static fn ($url) => $url !== ''));
                $response = null;
                foreach ($urls as $url) {
                    $response = $excluded ? $this->api->excludeUrl($connection['site_source_id'], $url) : $this->api->reindex($url);
                    if (is_wp_error($response)) break;
                }
                if (!$urls) $response = new \WP_Error('dzen_queue_url', 'Missing public URL');
                $changes = ['attempts' => (int) $row['attempts'] + 1];
                if (is_wp_error($response)) {
                    $error = $response->get_error_data() ?: [];
                    $changes += ['error_code' => $response->get_error_code(),
                        'status' => !empty($error['retryable']) && $changes['attempts'] < 6 ? 'pending' : 'error',
                        'next_attempt' => time() + max((int) ($error['retry_after'] ?? 0), min(3600, 30 * 2 ** $changes['attempts']))];
                } else {
                    // Updates confirm scheduling; exclusions confirm removal from search.
                    $changes += ['status' => $excluded ? 'excluded' : 'accepted', 'operation_id' => '', 'error_code' => '', 'next_attempt' => 0];
                }
                $wpdb->update($events, $changes, ['id' => $row['id']]);
                $wpdb->query('COMMIT');
            }
            update_option('dzen_chat_worker_ran', time(), false);
        } finally {
            $wpdb->query('ROLLBACK'); // Also releases the object lock if a transport hook throws.
            $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name=%s AND option_value=%s", 'dzen_chat_worker_lock', $lock));
            wp_cache_delete('dzen_chat_worker_lock', 'options');
        }
    }

    public function retry(): void
    {
        global $wpdb;
        [, $events] = self::tables();
        try {
            $integration = $this->credentials->get()['client_id'];
        } catch (\RuntimeException | \JsonException $error) {
            return;
        }
        $wpdb->query($wpdb->prepare("UPDATE $events SET status='pending', attempts=0, next_attempt=0 WHERE integration_id=%s AND status IN ('error','blocked')", $integration));
        delete_option('dzen_chat_queue_error');
        $this->reconcile();
    }
}
