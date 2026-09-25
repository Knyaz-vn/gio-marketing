<?php
/**
 * Лог помилок в окремій таблиці {prefix}bpmg_log.
 */

defined( 'ABSPATH' ) || exit;

function bpmg_log_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'bpmg_log';
}

function bpmg_install_log_table(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = bpmg_log_table();
	$charset = $wpdb->get_charset_collate();

	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			level varchar(10) NOT NULL DEFAULT 'error',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			message text NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset};"
	);

	update_option( 'bpmg_db_version', BPMG_DB_VERSION, false );
}

/**
 * Створює таблицю, якщо плагін оновили без повторної активації (або на іншому сайті мережі).
 */
function bpmg_maybe_upgrade(): void {
	if ( get_option( 'bpmg_db_version' ) !== BPMG_DB_VERSION ) {
		bpmg_install_log_table();
	}
}
add_action( 'admin_init', 'bpmg_maybe_upgrade' );

/**
 * Записати подію в лог.
 *
 * @param string $level error|warning|info
 */
function bpmg_log( string $message, string $level = 'error', int $post_id = 0 ): void {
	global $wpdb;

	$level   = in_array( $level, array( 'error', 'warning', 'info' ), true ) ? $level : 'error';
	$message = mb_substr( wp_strip_all_tags( $message ), 0, 2000 );

	$ok = $wpdb->insert(
		bpmg_log_table(),
		array(
			'created_at' => current_time( 'mysql' ),
			'level'      => $level,
			'post_id'    => $post_id,
			'message'    => $message,
		),
		array( '%s', '%s', '%d', '%s' )
	);

	if ( false === $ok ) {
		error_log( '[BP Meta Generator] ' . $level . ': ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		return;
	}

	// Тримаємо таблицю компактною: не більше 500 останніх записів.
	if ( 0 === wp_rand( 0, 19 ) ) {
		$table = bpmg_log_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", (int) $wpdb->insert_id - 500 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}

/**
 * @return array<int, object>
 */
function bpmg_get_log( int $limit = 50 ): array {
	global $wpdb;
	$table = bpmg_log_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
	return is_array( $rows ) ? $rows : array();
}

function bpmg_clear_log(): void {
	global $wpdb;
	$table = bpmg_log_table();
	$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
