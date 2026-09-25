<?php
/**
 * Видалення плагіна. Заявки пацієнтів є медичною документацією клініки, тому таблиці
 * й налаштування видаляються ЛИШЕ якщо в wp-config.php явно задано:
 *     define('BPMB_DELETE_DATA_ON_UNINSTALL', true);
 * Роль "Реєстратор" і cron-задачі прибираються завжди.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

foreach (['bpmb_sync', 'bpmb_sync_now', 'bpmb_hourly', 'bpmb_daily'] as $hook) {
    wp_clear_scheduled_hook($hook);
}
remove_role('bp_receptionist');
$admin = get_role('administrator');
if ($admin) {
    $admin->remove_cap('bpmb_manage_bookings');
    $admin->remove_cap('bpmb_manage_settings');
}

if (defined('BPMB_DELETE_DATA_ON_UNINSTALL') && BPMB_DELETE_DATA_ON_UNINSTALL) {
    global $wpdb;
    foreach (['bpmb_events', 'bpmb_holds', 'bpmb_bookings', 'bpmb_callbacks'] as $t) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$t}"); // phpcs:ignore
    }
    foreach (['bpmb_settings', 'bpmb_doctors', 'bpmb_specialties', 'bpmb_services', 'bpmb_locations', 'bpmb_watch_state', 'bpmb_sync_report', 'bpmb_cache_ver', 'bpmb_db_version'] as $o) {
        delete_option($o);
    }
}
