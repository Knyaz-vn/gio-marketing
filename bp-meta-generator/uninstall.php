<?php
/**
 * Видалення плагіна: прибираємо опції, лог і заплановані події.
 * Згенеровані описи (_yoast_wpseo_metadesc, rank_math_description, _bpmg_meta_description) НЕ видаляються.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

function bpmg_uninstall_site(): void {
	global $wpdb;

	foreach ( array( 'bpmg_settings', 'bpmg_bulk_queue', 'bpmg_bulk_state', 'bpmg_db_version' ) as $option ) {
		delete_option( $option );
	}
	delete_transient( 'bpmg_bulk_lock' );

	wp_clear_scheduled_hook( 'bpmg_bulk_process' );
	wp_unschedule_hook( 'bpmg_generate_single' );

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bpmg_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
		switch_to_blog( (int) $site_id );
		bpmg_uninstall_site();
		restore_current_blog();
	}
} else {
	bpmg_uninstall_site();
}
