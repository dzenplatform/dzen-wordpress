<?php
namespace DzenChat;

defined('ABSPATH') || exit;

final class Api
{
    public function __construct(private Credentials $credentials) {}

    public static function origin(): string
    {
        $url = defined('DZEN_CHAT_API_URL') ? rtrim(DZEN_CHAT_API_URL, '/') : 'https://chat.dzen.dev';
        $parts = wp_parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || !empty($parts['path'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \RuntimeException('invalid_api_origin');
        }
        return $url;
    }

    /** The implemented registration endpoint exchanges credentials separately from the public API. */
    public function exchange(string $code, string $verifier, string $redirectUri): array|\WP_Error
    {
        $response = wp_safe_remote_post(self::origin() . '/auth/exchange/', [
            'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirectUri],
                JSON_UNESCAPED_SLASHES),
            'timeout' => 15, 'redirection' => 0, 'sslverify' => true,
            'limit_response_size' => 16385, 'cookies' => [],
        ]);
        if (is_wp_error($response)) {
            return new \WP_Error('dzen_exchange_network', __('Could not exchange the code. Start the connection again.', 'dzen-chat'));
        }
        $raw = wp_remote_retrieve_body($response);
        $payload = json_decode($raw, true);
        if (wp_remote_retrieve_response_code($response) !== 200 || strlen($raw) > 16384 || !is_array($payload)) {
            return new \WP_Error('dzen_exchange_failed', __('Dzen Chat did not confirm the code exchange. Start the connection again.', 'dzen-chat'));
        }
        return $payload;
    }

    /** All paths are constructed by the plugin, never taken from an API response. */
    public function request(string $method, string $path, array $query = [], ?array $data = null,
        int $timeout = 15): array|\WP_Error
    {
        if (str_starts_with($path, '/chats') && $method !== 'GET') {
            return new \WP_Error('dzen_history_immutable', __('Conversation history is read-only in this version.', 'dzen-chat'));
        }
        try {
            if (!preg_match('~^/[a-zA-Z0-9/_%.-]+$~D', $path) || str_contains($path, '..')
                || !in_array($method, ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)) {
                throw new \RuntimeException('invalid_api_request');
            }
            ksort($query, SORT_STRING);
            $target = '/api' . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
            $body = $data === null ? '' : wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
            $credentials = $this->credentials->get();
            $headers['Authorization'] = 'Bearer ' . $credentials['client_id'] . '.' . $credentials['client_secret'];
            $response = wp_safe_remote_request(self::origin() . $target, [
                'method' => $method, 'headers' => $headers, 'body' => $body,
                'timeout' => $timeout, 'redirection' => 0, 'sslverify' => true,
                'limit_response_size' => 2097153, 'cookies' => [],
            ]);
        } catch (\RuntimeException | \JsonException $error) {
            return new \WP_Error('dzen_configuration', __('Check the connection and WordPress security keys. Reconnect if the site URL has changed.', 'dzen-chat'));
        }
        if (is_wp_error($response)) {
            return new \WP_Error('dzen_network', __('Dzen Chat is unavailable. The service has not confirmed the change.', 'dzen-chat'), ['retryable' => true]);
        }
        $status = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $payload = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $messages = [
                400 => __('Check the values you entered.', 'dzen-chat'),
                401 => __('Reconnect the site to Dzen Chat.', 'dzen-chat'),
                403 => __('This action is unavailable. Check permissions and the project status in Dzen Chat.', 'dzen-chat'),
                404 => __('The item is unavailable or the API does not support this operation yet.', 'dzen-chat'),
                409 => __('The state has changed. Refresh the page and try again.', 'dzen-chat'),
                422 => __('Check the values you entered.', 'dzen-chat'),
                429 => __('Too many requests. Try again later.', 'dzen-chat'),
            ];
            $retryAfter = wp_remote_retrieve_header($response, 'retry-after');
            $seconds = is_numeric($retryAfter) ? (int) $retryAfter : max(0, (int) strtotime((string) $retryAfter) - time());
            return new \WP_Error('dzen_http_' . $status,
                $messages[$status] ?? __('The service did not confirm the operation. Try again later.', 'dzen-chat'),
                ['status' => $status, 'retryable' => $status === 429 || $status >= 500, 'retry_after' => min(86400, $seconds)]);
        }
        if ($status === 204 && $raw === '') return [];
        if (strlen($raw) > 2097152 || !is_array($payload)) {
            return new \WP_Error('dzen_protocol', __('Dzen Chat returned an invalid response. The operation is not confirmed.', 'dzen-chat'));
        }
        return $payload;
    }

    /** HttpUrl on the service normalizes origins and Unicode URLs before acknowledging them. */
    private static function canonicalUrl(mixed $url): ?string
    {
        if (!is_string($url)) return null;
        $parts = wp_parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
        try {
            // Requests is bundled with the supported WordPress versions and its HTTP client.
            $iri = new \WpOrg\Requests\Iri($url);
            $iri->ihost = \WpOrg\Requests\IdnaEncoder::encode($iri->ihost);
            if ($iri->path === '') $iri->path = '/';
            return $iri->uri;
        } catch (\WpOrg\Requests\Exception $error) {
            return null;
        }
    }

    public function reindex(string $url): array|\WP_Error
    {
        $expected = self::canonicalUrl($url);
        if ($expected === null) return new \WP_Error('dzen_url', __('Invalid public page URL.', 'dzen-chat'));
        $result = $this->request('POST', '/update', [], ['url' => $url]);
        if (is_wp_error($result)) return $result;
        if (($result['status'] ?? '') !== 'ok' || ($result['action'] ?? '') !== 'queued'
            || self::canonicalUrl($result['url'] ?? null) !== $expected) {
            return new \WP_Error('dzen_protocol', __('The service did not confirm the reindexing request.', 'dzen-chat'));
        }
        return $result;
    }

    public function excludeUrl(string $sourceId, string $url): array|\WP_Error
    {
        $expected = self::canonicalUrl($url);
        if ($expected === null) return new \WP_Error('dzen_url', __('Invalid public page URL.', 'dzen-chat'));
        $result = $this->request('POST', '/pages/exclude', [], ['url' => $url]);
        if (is_wp_error($result)) return $result;
        if (($result['index_status'] ?? '') !== 'excluded' || ($result['source_id'] ?? null) !== $sourceId
            || self::canonicalUrl($result['url'] ?? null) !== $expected) {
            return new \WP_Error('dzen_protocol', __('The service did not confirm removal from the index.', 'dzen-chat'));
        }
        return $result;
    }

    /** Lists stay request-local; chat messages and source text are never persisted here. */
    public function items(string $path, array $query = []): array|\WP_Error
    {
        $result = $this->request('GET', $path, $query);
        if (is_wp_error($result)) return $result;
        if (!isset($result['items']) || !is_array($result['items']) || !array_is_list($result['items'])
            || array_filter($result['items'], static fn ($item) => !is_array($item)
                || (!is_string($item['id'] ?? null) && !is_int($item['id'] ?? null)) || (string) $item['id'] === '')) {
            return new \WP_Error('dzen_protocol', __('The service returned an invalid list.', 'dzen-chat'));
        }
        return $result['items'];
    }

    /** Exact server-side lookup; absence from a recent-document list is not proof of absence. */
    public function pageForUrl(string $sourceId, string $url): array|null|\WP_Error
    {
        $expected = self::canonicalUrl($url);
        if ($expected === null) return new \WP_Error('dzen_url', __('Invalid public page URL.', 'dzen-chat'));
        $items = $this->items('/pages', ['url' => $expected, 'source_id' => $sourceId, 'limit' => 1]);
        if (is_wp_error($items)) return $items;
        if (!$items) return null;
        $page = $items[0];
        if (count($items) !== 1 || ($page['source_id'] ?? null) !== $sourceId
            || self::canonicalUrl($page['url'] ?? null) !== $expected
            || !in_array($page['index_status'] ?? '', ['errors', 'pending', 'processing', 'ready', 'excluded'], true)) {
            return new \WP_Error('dzen_protocol', __('The service returned an invalid page status.', 'dzen-chat'));
        }
        return $page;
    }

    /** Aggregate status is always scoped to an explicitly requested, assigned source. */
    public function sourceStatus(string $sourceId): array|\WP_Error
    {
        $result = $this->request('GET', '/sources/' . self::segment($sourceId) . '/status');
        if (is_wp_error($result)) return $result;
        $invalid = new \WP_Error('dzen_protocol', __('The service returned an invalid indexing status.', 'dzen-chat'));
        if (($result['id'] ?? null) !== $sourceId || !is_string($result['title'] ?? null)
            || !is_bool($result['is_paused'] ?? null) || !array_key_exists('blocked_reason', $result)
            || ($result['blocked_reason'] !== null && !is_string($result['blocked_reason']))
            || !is_array($result['counts'] ?? null) || !is_int($result['total'] ?? null)
            || $result['total'] < 0) return $invalid;
        $total = 0;
        foreach (['errors', 'pending', 'processing', 'ready', 'excluded'] as $key) {
            $count = $result['counts'][$key] ?? null;
            if (!is_int($count) || $count < 0) return $invalid;
            $total += $count;
        }
        if ($total !== $result['total'] || !is_string($result['details_url'] ?? null)
            || !preg_match('~^' . preg_quote(self::origin(), '~') . '/projects/[A-Za-z0-9_-]+/sources/'
                . preg_quote($sourceId, '~') . '$~D', $result['details_url'])) return $invalid;
        return $result;
    }

    public function feedback(): array|\WP_Error
    {
        $items = $this->items('/feedback', ['limit' => 100]);
        if (is_wp_error($items)) return $items;
        $invalid = new \WP_Error('dzen_feedback_protocol', __('The service returned invalid feedback. Refresh the page.', 'dzen-chat'));
        foreach ($items as $chat) {
            $feedback = $chat['feedback'] ?? null;
            if (!is_string($chat['id']) || !preg_match('/^[A-Za-z0-9_-]+$/D', $chat['id'])
                || !is_string($chat['visitor'] ?? null) || !is_array($feedback)
                || !is_int($feedback['rating'] ?? null) || $feedback['rating'] < 1 || $feedback['rating'] > 5
                || !is_array($feedback['selected'] ?? []) || !array_is_list($feedback['selected'] ?? [])
                || !is_string($feedback['text'] ?? '')
                || (isset($feedback['submitted_at']) && !is_string($feedback['submitted_at']))
                || !is_string($chat['details_url'] ?? null)
                || !preg_match('~^' . preg_quote(self::origin(), '~') . '/projects/[A-Za-z0-9_-]+/history/'
                    . preg_quote($chat['id'], '~') . '$~D', $chat['details_url'])) return $invalid;
            foreach ($feedback['selected'] ?? [] as $reason) {
                if (!is_string($reason) || trim($reason) === '') return $invalid;
            }
        }
        return $items;
    }

    public function conversation(string $id): array|\WP_Error
    {
        $chat = $this->request('GET', '/chats/' . self::segment($id));
        if (is_wp_error($chat)) return $chat;
        if (($chat['id'] ?? null) !== $id || !is_string($chat['details_url'] ?? null)
            || !preg_match('~^' . preg_quote(self::origin(), '~') . '/projects/[A-Za-z0-9_-]+/history/'
                . preg_quote($id, '~') . '$~D', $chat['details_url'])) {
            return new \WP_Error('dzen_chat_protocol', __('The service returned invalid conversation details. Refresh the page.', 'dzen-chat'));
        }
        return $chat;
    }

    public function pageTriggers(string $id, string $sourceId, string $url): array|\WP_Error
    {
        $expected = self::canonicalUrl($url);
        if ($expected === null) return new \WP_Error('dzen_url', __('Invalid public page URL.', 'dzen-chat'));
        $data = $this->request('GET', '/pages/' . self::segment($id) . '/triggers', timeout: 5);
        if (is_wp_error($data)) return $data;
        $invalid = new \WP_Error('dzen_protocol', __('The service returned invalid page triggers. Refresh the status.', 'dzen-chat'));
        if (($data['id'] ?? null) !== $id || ($data['source_id'] ?? null) !== $sourceId
            || self::canonicalUrl($data['url'] ?? null) !== $expected
            || !in_array($data['status'] ?? '', ['available', 'source_disabled', 'not_matched', 'not_generated'], true)
            || !is_array($data['triggers'] ?? null) || !array_is_list($data['triggers'])
            || ($data['status'] === 'available') !== (count($data['triggers']) > 0)
            || !is_string($data['details_url'] ?? null)
            || !preg_match('~^' . preg_quote(self::origin(), '~') . '/projects/[A-Za-z0-9_-]+/pages/'
                . preg_quote($id, '~') . '$~D', $data['details_url'])) return $invalid;
        $items = [];
        foreach ($data['triggers'] as $trigger) {
            if (!is_array($trigger) || !is_string($trigger['key'] ?? null) || $trigger['key'] === ''
                || isset($items[$trigger['key']]) || !is_string($trigger['text'] ?? null)
                || trim($trigger['text']) === '') return $invalid;
            $items[$trigger['key']] = ['key' => $trigger['key'], 'text' => $trigger['text']];
        }
        $data['triggers'] = array_values($items);
        return $data;
    }

    public function conversationMessages(string $id): array|\WP_Error
    {
        $messages = $this->items('/chats/' . self::segment($id) . '/messages', ['limit' => 100]);
        if (is_wp_error($messages)) return $messages;
        $invalid = new \WP_Error('dzen_chat_protocol', __('The service returned invalid conversation messages. Refresh the page.', 'dzen-chat'));
        foreach ($messages as $message) {
            if (!in_array($message['role'] ?? '', ['user', 'assistant'], true)) continue;
            $actions = $message['suggested_actions'] ?? null;
            $status = $message['suggestions_status'] ?? null;
            if (!is_string($message['text'] ?? null) || !is_array($actions) || !array_is_list($actions)
                || !in_array($status, ['available', 'failed', 'none'], true)
                || ($status === 'available') !== (count($actions) > 0)
                || !array_key_exists('selected_suggested_action', $message)) return $invalid;
            foreach ($actions as $action) {
                if (!is_string($action) || trim($action) === '') return $invalid;
            }
            $selected = $message['selected_suggested_action'];
            if ($selected !== null && (!is_string($selected) || !in_array($selected, $actions, true))) return $invalid;
        }
        return $messages;
    }

    public static function segment(string $value): string
    {
        return rawurlencode($value);
    }
}
