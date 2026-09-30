<?php
// php phone-db.php <WP_DIR> [reset] - останні кліки і дзвінки (JSON) або очищення таблиць.
$_SERVER['HTTP_HOST'] = 'localhost';
require $argv[1] . '/wp-load.php';
global $wpdb;
if ( in_array( $argv[2] ?? '', array( 'block-rest', 'unblock-rest' ), true ) ) {
	update_option( 'bp_e2e_block_rest', 'block-rest' === $argv[2] ? 1 : 0 );
	exit;
}
if ( ( $argv[2] ?? '' ) === 'reset' ) {
	$wpdb->query( 'DELETE FROM ' . bp_phone_clicks_table() );
	$wpdb->query( 'DELETE FROM ' . bp_phone_calls_table() );
	delete_option( 'bp_phone_unknown' );
	exit;
}
echo wp_json_encode(
	array(
		'clicks'       => $wpdb->get_results( 'SELECT * FROM ' . bp_phone_clicks_table() . ' ORDER BY id DESC LIMIT 20', ARRAY_A ),
		'calls'        => $wpdb->get_results( 'SELECT * FROM ' . bp_phone_calls_table() . ' ORDER BY id', ARRAY_A ),
		'call_columns' => $wpdb->get_col( 'SHOW COLUMNS FROM ' . bp_phone_calls_table(), 0 ),
		'unknown'      => get_option( 'bp_phone_unknown' ),
	),
	JSON_UNESCAPED_UNICODE
);
