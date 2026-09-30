<?php
// Встановлення WP для E2E: php install.php <WP_DIR>
define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST'] = 'localhost';
require $argv[1] . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

wp_install( 'BP Medical E2E', 'admin', 'admin@example.com', true, '', 'admin' );
update_option( 'permalink_structure', '/%postname%/' );
$r = activate_plugin( 'bp-attribution/bp-attribution.php' );
if ( is_wp_error( $r ) ) {
	fwrite( STDERR, $r->get_error_message() . "\n" );
	exit( 1 );
}
$parent = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Відділення', 'post_name' => 'departments' ) );
wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Стоматологія', 'post_name' => 'stomatologiya', 'post_parent' => $parent, 'post_content' => 'Сторінка відділення' ) );
flush_rewrite_rules();
echo "ok\n";
