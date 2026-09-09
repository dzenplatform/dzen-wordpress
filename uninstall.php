<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;
// This removes only local plugin data. Chats and billing records live in Dzen Chat.
foreach (['dzen_chat_objects', 'dzen_chat_events'] as $suffix) {
    $wpdb->query('DROP TABLE IF EXISTS `' . $wpdb->prefix . $suffix . '`');
}
$optionNames = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like('dzen_chat_') . '%', $wpdb->esc_like('_transient_dzen_chat_') . '%', $wpdb->esc_like('_transient_timeout_dzen_chat_') . '%'));
foreach ($optionNames as $name) {
    delete_option($name);
}
$wpdb->query($wpdb->prepare("DELETE FROM $wpdb->postmeta WHERE meta_key LIKE %s", $wpdb->esc_like('_dzen_chat_') . '%'));
wp_clear_scheduled_hook('dzen_chat_worker');
foreach (_get_cron_array() as $timestamp => $hooks) {
    foreach ($hooks['dzen_chat_clean_claim'] ?? [] as $event) {
        wp_unschedule_event($timestamp, 'dzen_chat_clean_claim', $event['args']);
    }
}
