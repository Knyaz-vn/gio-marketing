<?php
// Друкує останню заявку з wp_bp_leads і останній лист адміністратору (JSON).
$_SERVER['HTTP_HOST'] = 'localhost';
require $argv[1] . '/wp-load.php';
global $wpdb;
echo wp_json_encode(
	array(
		'lead'  => $wpdb->get_row( 'SELECT * FROM ' . bp_attr_table() . ' ORDER BY id DESC LIMIT 1', ARRAY_A ),
		'count' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . bp_attr_table() ),
		'mail'  => get_option( 'bp_e2e_last_mail' ),
	),
	JSON_UNESCAPED_UNICODE
);
