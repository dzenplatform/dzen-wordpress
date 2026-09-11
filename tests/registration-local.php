<?php
/** Development TLS trust only. Requests go to the real local service. */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local') return;
add_filter('http_request_args', static function ($args, $url) {
    if ($url === 'https://local.dzenchat.com/auth/exchange/'
        || preg_match('~^https://local[.]dzenchat[.]com/api/[A-Za-z0-9/_?&=%.+-]+$~D', $url)) {
        $args['sslcertificates'] = '/var/www/dzen-local-ca.crt';
    }
    return $args;
}, 10, 2);
