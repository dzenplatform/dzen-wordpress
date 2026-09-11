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
            return new \WP_Error('dzen_exchange_network', __('Не удалось обменять код. Начните подключение заново.', 'dzen-chat'));
        }
        $raw = wp_remote_retrieve_body($response);
        $payload = json_decode($raw, true);
        if (wp_remote_retrieve_response_code($response) !== 200 || strlen($raw) > 16384 || !is_array($payload)) {
            return new \WP_Error('dzen_exchange_failed', __('Dzen Chat не подтвердил обмен кода. Начните подключение заново.', 'dzen-chat'));
        }
        return $payload;
    }

    /** All paths are constructed by the plugin, never taken from an API response. */
    public function request(string $method, string $path, array $query = [], ?array $data = null,
        int $timeout = 15): array|\WP_Error
    {
        if (str_starts_with($path, '/chats') && $method !== 'GET') {
            return new \WP_Error('dzen_history_immutable', __('В этой версии доступен только просмотр истории чатов.', 'dzen-chat'));
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
                400 => __('Проверьте введённые данные.', 'dzen-chat'),
                401 => __('Нужно повторно подключить сайт к Dzen Chat.', 'dzen-chat'),
                403 => __('Действие недоступно: проверьте права и состояние проекта в Dzen Chat.', 'dzen-chat'),
                404 => __('Объект недоступен или API ещё не поддерживает эту операцию.', 'dzen-chat'),
                409 => __('Состояние изменилось. Обновите страницу и повторите действие.', 'dzen-chat'),
                422 => __('Проверьте введённые данные.', 'dzen-chat'),
                429 => __('Слишком много запросов. Повторите позже.', 'dzen-chat'),
            ];
            $retryAfter = wp_remote_retrieve_header($response, 'retry-after');
            $seconds = is_numeric($retryAfter) ? (int) $retryAfter : max(0, (int) strtotime((string) $retryAfter) - time());
            return new \WP_Error('dzen_http_' . $status,
                $messages[$status] ?? __('Сервис не подтвердил операцию. Попробуйте позднее.', 'dzen-chat'),
                ['status' => $status, 'retryable' => $status === 429 || $status >= 500, 'retry_after' => min(86400, $seconds)]);
        }
        if ($status === 204 && $raw === '') return [];
        if (strlen($raw) > 2097152 || !is_array($payload)) {
            return new \WP_Error('dzen_protocol', __('Dzen Chat вернул некорректный ответ. Операция не подтверждена.', 'dzen-chat'));
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
        if ($expected === null) return new \WP_Error('dzen_url', __('Некорректный публичный адрес страницы.', 'dzen-chat'));
        $result = $this->request('POST', '/update', [], ['url' => $url]);
        if (is_wp_error($result)) return $result;
        if (($result['status'] ?? '') !== 'ok' || ($result['action'] ?? '') !== 'queued'
            || self::canonicalUrl($result['url'] ?? null) !== $expected) {
            return new \WP_Error('dzen_protocol', __('Сервис не подтвердил запрос переиндексации.', 'dzen-chat'));
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
            return new \WP_Error('dzen_protocol', __('Сервис вернул некорректный список.', 'dzen-chat'));
        }
        return $result['items'];
    }

    public static function segment(string $value): string
    {
        return rawurlencode($value);
    }
}
