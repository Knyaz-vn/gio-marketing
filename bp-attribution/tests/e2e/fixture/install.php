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
$mamolog = <<<'HTML'
<p>Гаряча лінія: 0 (800) 337 617.</p>
<p>Коріатовичів: (066) 211 9922, Стрілецька: <a href="tel:0502119922" class="existing-link">050-211-99-22</a></p>
<p><img alt="050-211-99-22" width="1" height="1"> Інший номер: 093 111 22 33</p>
HTML;
wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Мамологія', 'post_name' => 'mamolog', 'post_parent' => $parent, 'post_content' => $mamolog ) );
update_option( 'bp_phone_binotel_secret', 'e2e-secret-0123456789abcdef' );
flush_rewrite_rules();
echo "ok\n";
