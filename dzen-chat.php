<?php
/**
 * Plugin Name: Dzen Chat
 * Description: Connect your website to Dzen Chat and manage widgets, indexed content and conversation history.
 * Version: 0.1.1
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Author: Dzen Platform
 * License: GPL-2.0-or-later
 * Text Domain: dzen-chat
 */

defined('ABSPATH') || exit;

define('DZEN_CHAT_VERSION', '0.1.1');
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
