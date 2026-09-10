<?php
/**
 * Plugin Name: Dzen Chat
 * Description: Authorize your website in Dzen Chat using a one-time code and PKCE.
 * Version: 0.2.0
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Author: Dzen Platform
 * License: GPL-2.0-or-later
 * Text Domain: dzen-chat
 */

defined('ABSPATH') || exit;

define('DZEN_CHAT_VERSION', '0.2.0');
define('DZEN_CHAT_FILE', __FILE__);
foreach (['Credentials', 'Api', 'Connection', 'Sync', 'Admin', 'Plugin'] as $dzen_class) {
    require_once __DIR__ . '/src/' . $dzen_class . '.php';
}
unset($dzen_class);

register_activation_hook(__FILE__, ['DzenChat\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['DzenChat\\Plugin', 'deactivate']);
add_action('plugins_loaded', static function () {
    (new DzenChat\Plugin())->register();
});
