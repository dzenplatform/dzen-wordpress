<?php
namespace DzenChat;

defined('ABSPATH') || exit;

final class Connection
{
    public function __construct(private Credentials $credentials, private Api $api) {}

    public function register(): void
    {
        add_action('admin_post_dzen_chat_connect', [$this, 'start']);
        add_action('admin_post_dzen_chat_authorize', [$this, 'callback']);
        add_action('admin_post_nopriv_dzen_chat_authorize', static function () {
            nocache_headers();
            header('Referrer-Policy: no-referrer');
            wp_safe_redirect(wp_login_url(Admin::url('dzen-chat', ['notice' => 'authorization_failed'])), 303);
            exit;
        });
        add_action('admin_post_dzen_chat_disconnect', [$this, 'disconnect']);
    }

    public static function authorize(string $nonce): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'dzen-chat'), '', ['response' => 403]);
        }
        check_admin_referer($nonce);
    }

    public static function callbackUrl(): string
    {
        return admin_url('admin-post.php?action=dzen_chat_authorize', 'https');
    }

    public function start(): void
    {
        self::authorize('dzen_chat_connect');
        try {
            $this->credentials->key();
            $site = wp_parse_url(Credentials::siteUrl());
            $callback = wp_parse_url(self::callbackUrl());
            if (($site['scheme'] ?? '') !== 'https' || $site['host'] !== $callback['host']
                || ($site['port'] ?? 443) !== ($callback['port'] ?? 443)) {
                throw new \RuntimeException('https_site_required');
            }
            $state = bin2hex(random_bytes(32));
            $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $attempt = ['state_hash' => hash('sha256', $state), 'verifier' => $verifier,
                'session' => hash('sha256', wp_get_session_token()), 'expires' => time() + 300,
                'site_url' => Credentials::siteUrl(), 'redirect_uri' => self::callbackUrl()];
            set_transient('dzen_chat_auth_' . get_current_user_id(), $this->credentials->encrypt($attempt), 300);
            $url = add_query_arg(['host' => $site['host'], 'type' => 'wordpress',
                'site_url' => Credentials::siteUrl(), 'redirect_uri' => self::callbackUrl(),
                'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256'], Api::origin() . '/auth/add');
            wp_redirect($url, 303, 'Dzen Chat');
            exit;
        } catch (\RuntimeException $error) {
            wp_die(esc_html__('Для подключения нужны HTTPS сайта и админки на одном домене, sodium и ключи безопасности WordPress в wp-config.php.', 'dzen-chat'), '', ['response' => 400]);
        }
    }

    public function callback(): void
    {
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Повторите подключение из админки WordPress.', 'dzen-chat'), '', ['response' => 403]);
        }
        try {
            $key = 'dzen_chat_auth_' . get_current_user_id();
            $attempt = $this->credentials->decrypt((string) get_transient($key));
            $state = isset($_GET['state']) && is_string($_GET['state']) ? wp_unslash($_GET['state']) : '';
            if (!hash_equals($attempt['state_hash'], hash('sha256', $state)) || $attempt['expires'] < time()
                || !hash_equals($attempt['session'], hash('sha256', wp_get_session_token()))
                || $attempt['site_url'] !== Credentials::siteUrl() || $attempt['redirect_uri'] !== self::callbackUrl()) {
                throw new \RuntimeException('invalid_state');
            }
            // Claim the callback once even when two requests read the same transient.
            $claim = 'dzen_chat_auth_claim_' . $attempt['state_hash'];
            if (!add_option($claim, time(), '', false)) {
                throw new \RuntimeException('code_replayed');
            }
            delete_transient($key);
            wp_schedule_single_event(time() + 600, 'dzen_chat_clean_claim', [$claim]);
            if (isset($_GET['error'])) {
                wp_safe_redirect(Admin::url('dzen-chat', ['notice' => 'cancelled']), 303);
                exit;
            }
            $code = isset($_GET['code']) && is_string($_GET['code']) ? wp_unslash($_GET['code']) : '';
            if (!preg_match('/^[A-Za-z0-9_-]{16,512}$/D', $code)) {
                throw new \RuntimeException('invalid_code');
            }
            $result = $this->api->request('POST', '/integrations/exchange', [], [
                'code' => $code, 'code_verifier' => $attempt['verifier'], 'redirect_uri' => $attempt['redirect_uri'],
            ], '', true);
            if (is_wp_error($result)) {
                throw new \RuntimeException('exchange_failed');
            }
            $this->credentials->save($result);
            $verified = $this->api->status();
            if (is_wp_error($verified)) {
                wp_safe_redirect(Admin::url('dzen-chat', ['notice' => 'unverified']), 303);
                exit;
            }
            update_option('dzen_chat_reconcile_cursor', 0, false);
            wp_safe_redirect(Admin::url('dzen-chat', ['notice' => 'connected']), 303);
            exit;
        } catch (\RuntimeException | \JsonException $error) {
            // Redirect to remove the one-time code from the visible URL and referrer.
            wp_safe_redirect(Admin::url('dzen-chat', ['notice' => 'authorization_failed']), 303);
            exit;
        }
    }

    public function disconnect(): void
    {
        self::authorize('dzen_chat_disconnect');
        $result = $this->api->request('DELETE', '/integration', [], null, wp_generate_uuid4());
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()), '', ['response' => 502, 'back_link' => true]);
        }
        $this->credentials->forget();
        wp_safe_redirect(Admin::url('dzen-chat', ['notice' => 'disconnected']), 303);
        exit;
    }
}
