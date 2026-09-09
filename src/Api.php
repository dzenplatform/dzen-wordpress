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

    public static function signature(string $secret, string $method, string $target, string $client,
        string $timestamp, string $nonce, string $key, string $body): string
    {
        return hash_hmac('sha256', implode("\n", ['DZEN-HMAC-V1', $method, $target,
            $client, $timestamp, $nonce, $key, hash('sha256', $body)]), $secret);
    }

    /** All paths are constructed by the plugin, never taken from an API response. */
    public function request(string $method, string $path, array $query = [], ?array $data = null,
        string $idempotency = '', bool $exchange = false): array|\WP_Error
    {
        if (str_starts_with($path, '/chats') && $method !== 'GET'
            && !($method === 'PATCH' && preg_match('~^/chats/[^/]+/visibility$~D', $path))) {
            return new \WP_Error('dzen_history_immutable', __('Историю чатов нельзя удалять или изменять. Доступно только скрытие и восстановление.', 'dzen-chat'));
        }
        try {
            if (!preg_match('~^/[a-zA-Z0-9/_%.-]+$~D', $path) || str_contains($path, '..')
                || !in_array($method, ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)) {
                throw new \RuntimeException('invalid_api_request');
            }
            ksort($query, SORT_STRING);
            $target = '/api/v1' . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
            $body = $data === null ? '' : wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
            if (!$exchange) {
                $credentials = $this->credentials->get();
                $timestamp = (string) time();
                $nonce = bin2hex(random_bytes(24));
                $headers += [
                    'X-Dzen-Auth-Version' => '1', 'X-Dzen-Client-Id' => $credentials['client_id'],
                    'X-Dzen-Timestamp' => $timestamp, 'X-Dzen-Nonce' => $nonce,
                    'X-Dzen-Signature' => self::signature($credentials['client_secret'], $method, $target,
                        $credentials['client_id'], $timestamp, $nonce, $idempotency, $body),
                ];
                if ($idempotency !== '') {
                    $headers['Idempotency-Key'] = $idempotency;
                }
            }
            $response = wp_safe_remote_request(self::origin() . $target, [
                'method' => $method, 'headers' => $headers, 'body' => $body,
                'timeout' => 15, 'redirection' => 0, 'sslverify' => true,
                'limit_response_size' => 2097153, 'cookies' => [],
            ]);
        } catch (\RuntimeException | \JsonException $error) {
            return new \WP_Error('dzen_configuration', __('Проверьте подключение и ключи WordPress. При смене адреса сайта подключите его заново.', 'dzen-chat'));
        }
        if (is_wp_error($response)) {
            return new \WP_Error('dzen_network', __('Dzen Chat недоступен. Изменение не подтверждено сервисом.', 'dzen-chat'), ['retryable' => true]);
        }
        $status = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $payload = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $messages = [
                401 => __('Нужно повторно подключить сайт к Dzen Chat.', 'dzen-chat'),
                403 => __('Действие недоступно: проверьте права и состояние проекта в Dzen Chat.', 'dzen-chat'),
                404 => __('Объект недоступен или API ещё не поддерживает эту операцию.', 'dzen-chat'),
                409 => __('Состояние изменилось. Обновите страницу и повторите действие.', 'dzen-chat'),
                429 => __('Слишком много запросов. Повторите позже.', 'dzen-chat'),
            ];
            $retryAfter = wp_remote_retrieve_header($response, 'retry-after');
            $seconds = is_numeric($retryAfter) ? (int) $retryAfter : max(0, (int) strtotime((string) $retryAfter) - time());
            return new \WP_Error('dzen_http_' . $status,
                $messages[$status] ?? __('Сервис не подтвердил операцию. Попробуйте позднее.', 'dzen-chat'),
                ['status' => $status, 'retryable' => $status === 429 || $status >= 500, 'retry_after' => min(86400, $seconds)]);
        }
        if (strlen($raw) > 2097152 || !is_array($payload)) {
            return new \WP_Error('dzen_protocol', __('Dzen Chat вернул некорректный ответ. Операция не подтверждена.', 'dzen-chat'));
        }
        return $payload;
    }

    public function status(): array|\WP_Error
    {
        $result = $this->request('GET', '/integration');
        if (is_wp_error($result)) {
            delete_transient('dzen_chat_status');
            return $result;
        }
        try {
            $local = $this->credentials->get();
            if (($result['integration']['id'] ?? '') !== $local['integration_id']
                || (string) ($result['project']['id'] ?? '') !== $local['project_id']
                || ($result['site_url'] ?? '') !== $local['site_url']
                || !is_array($result['allowed_operations'] ?? null) || !is_array($result['scopes'] ?? null)) {
                throw new \RuntimeException('integration_mismatch');
            }
        } catch (\RuntimeException $error) {
            delete_transient('dzen_chat_status');
            return new \WP_Error('dzen_identity', __('Сервис не подтвердил проект и сайт этой интеграции.', 'dzen-chat'));
        }
        $safe = [
            'integration' => ['id' => $local['integration_id'], 'status' => sanitize_key($result['integration']['status'] ?? '')],
            'project' => ['id' => $local['project_id'], 'title' => sanitize_text_field($result['project']['title'] ?? ''),
                'status' => sanitize_key($result['project']['status'] ?? ''), 'reason_code' => sanitize_key($result['project']['reason_code'] ?? '')],
            'allowed_operations' => array_map(static fn ($value) => $value === true, $result['allowed_operations']),
            'scopes' => array_values(array_filter($result['scopes'], 'is_string')), 'checked_at' => time(),
        ];
        set_transient('dzen_chat_status', $safe, 300);
        update_option('dzen_chat_verified', true, false);
        return $safe;
    }

    public static function segment(string $value): string
    {
        return rawurlencode($value);
    }
}
