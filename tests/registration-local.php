<?php
/** Development TLS trust only. Every registration request goes to the real local service. */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local') return;
add_filter('http_request_args', static function ($args, $url) {
    if ($url === 'https://local.dzenchat.com/auth/exchange/') {
        $args['sslcertificates'] = '/var/www/dzen-local-ca.crt';
    }
    return $args;
}, 10, 2);
