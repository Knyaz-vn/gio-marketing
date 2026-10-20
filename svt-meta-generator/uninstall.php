<?php
/**
 * Видалення плагіна: прибираємо опції, лог і заплановані події.
 * Згенеровані описи (Yoast / Rank Math / _svtmg_meta_description) НЕ видаляються.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

function svtmg_uninstall_site(): void {
	global $wpdb;

	foreach ( array( 'svtmg_settings', 'svtmg_bulk_queue', 'svtmg_bulk_state', 'svtmg_db_version' ) as $option ) {
		delete_option( $option );
	}
	delete_transient( 'svtmg_bulk_lock' );
	wp_clear_scheduled_hook( 'svtmg_bulk_process' );

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}svtmg_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
		switch_to_blog( (int) $site_id );
		svtmg_uninstall_site();
		restore_current_blog();
	}
} else {
	svtmg_uninstall_site();
}
