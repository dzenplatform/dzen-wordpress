<?php
namespace DzenChat;

defined('ABSPATH') || exit;

final class Credentials
{
    private const OPTION = 'dzen_chat_credentials';

    public static function siteUrl(): string
    {
        return trailingslashit(home_url());
    }

    public function key(): string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \RuntimeException('sodium_required');
        }
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY'] as $constant) {
            if (!defined($constant) || strlen(constant($constant)) < 32
                || str_contains(constant($constant), 'put your unique phrase here')) {
                throw new \RuntimeException('wordpress_keys_required');
            }
        }
        $installation = get_option('dzen_chat_installation');
        if (!$installation) {
            add_option('dzen_chat_installation', wp_generate_uuid4(), '', false);
            $installation = get_option('dzen_chat_installation');
        }
        return hash_hkdf('sha256', AUTH_KEY . "\0" . SECURE_AUTH_KEY, 32,
            'dzen-chat.credentials.v1|' . $installation);
    }

    public function encrypt(array $data): string
    {
        $key = $this->key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox(wp_json_encode($data), $nonce, $key));
    }

    public function decrypt(string $value): array
    {
        $key = $this->key();
        if (!str_starts_with($value, 'v1:')) {
            throw new \RuntimeException('credentials_invalid');
        }
        $raw = base64_decode(substr($value, 3), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('credentials_invalid');
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
        if ($plain === false) {
            throw new \RuntimeException('credentials_unreadable');
        }
        $data = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('credentials_invalid');
        }
        return $data;
    }

    public function save(array $data): void
    {
        foreach (['client_id', 'client_secret', 'integration_id', 'site_url'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
                throw new \RuntimeException('invalid_exchange_response');
            }
        }
        if ($data['site_url'] !== self::siteUrl() || strlen($data['client_secret']) < 24
            || !isset($data['project']['id'])) {
            throw new \RuntimeException('invalid_exchange_response');
        }
        $value = $this->encrypt([
            'client_id' => $data['client_id'], 'client_secret' => $data['client_secret'],
            'integration_id' => $data['integration_id'], 'project_id' => (string) $data['project']['id'],
            'site_url' => $data['site_url'],
        ]);
        update_option(self::OPTION, $value, false);
        if (get_option(self::OPTION) !== $value) {
            throw new \RuntimeException('credentials_not_saved');
        }
        delete_option('dzen_chat_verified');
        delete_transient('dzen_chat_status');
        delete_transient('dzen_chat_status_retry');
    }

    public function get(): array
    {
        $data = $this->decrypt((string) get_option(self::OPTION, ''));
        if (($data['site_url'] ?? '') !== self::siteUrl()) {
            throw new \RuntimeException('site_changed');
        }
        return $data;
    }

    public function exists(): bool
    {
        return (bool) get_option(self::OPTION, false);
    }

    public function forget(): void
    {
        delete_option(self::OPTION);
        delete_option('dzen_chat_verified');
        delete_transient('dzen_chat_status');
        delete_transient('dzen_chat_status_retry');
        delete_option('dzen_chat_widgets');
        delete_option('dzen_chat_widget');
    }
}
