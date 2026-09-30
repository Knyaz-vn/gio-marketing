<?php
/**
 * Plugin Name:       BP Attribution
 * Description:       Мультиканальна атрибуція заявок (перше і останнє непряме джерело, шлях дотиків) і відстеження кліків по номерах телефону з джерелом трафіку. Звіти "Атрибуція заявок" і "Кліки по телефону" в адмінці.
 * Version:           1.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            BP Medical
 * Text Domain:       bp-attribution
 */

defined( 'ABSPATH' ) || exit;

define( 'BP_ATTR_VERSION', '1.1.1' );
define( 'BP_ATTR_DB_VERSION', '2' );
define( 'BP_ATTR_FILE', __FILE__ );
define( 'BP_ATTR_DIR', plugin_dir_path( __FILE__ ) );
define( 'BP_ATTR_URL', plugin_dir_url( __FILE__ ) );

require_once BP_ATTR_DIR . 'includes/functions.php';
require_once BP_ATTR_DIR . 'includes/integrations.php';
require_once BP_ATTR_DIR . 'includes/booking.php';
require_once BP_ATTR_DIR . 'includes/phone.php';

if ( is_admin() ) {
	require_once BP_ATTR_DIR . 'includes/admin-report.php';
	require_once BP_ATTR_DIR . 'includes/admin-forms.php';
	require_once BP_ATTR_DIR . 'includes/admin-settings.php';
	require_once BP_ATTR_DIR . 'includes/admin-phone.php';
}

register_activation_hook( __FILE__, 'bp_attr_install' );
register_deactivation_hook( __FILE__, static function () { wp_clear_scheduled_hook( 'bp_phone_daily' ); } );
add_action( 'plugins_loaded', 'bp_attr_maybe_upgrade' );
add_action( 'wp_enqueue_scripts', 'bp_attr_enqueue' );
BP_Attr_Integrations::init();

/**
 * Скрипт на всіх публічних сторінках.
 */
function bp_attr_enqueue() {
	$s   = bp_attr_settings();
	$src = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? 'assets/dist/bp-attribution.js' : 'assets/dist/bp-attribution.min.js';
	wp_enqueue_script( 'bp-attribution', BP_ATTR_URL . $src, array(), BP_ATTR_VERSION, true );

	$self_reported = array();
	foreach ( bp_attr_self_reported_options() as $code => $label ) {
		$self_reported[] = array( $code, $label );
	}
	$config = array(
		'domain'            => $s['site_domain'],
		'cookieDomain'      => $s['cookie_domain'],
		'consent'           => $s['consent'],
		'excludeReferrers'  => bp_attr_lines( $s['exclude_referrers'] ),
		'selfReportedForms' => bp_attr_lines( $s['self_reported_forms'] ),
		'selfReported'      => $self_reported,
		'selfReportedLabel' => __( 'Звідки ви дізналися про нас?', 'bp-attribution' ),
	);
	wp_add_inline_script( 'bp-attribution', 'window.bpAttrConfig=' . wp_json_encode( apply_filters( 'bp_attr_js_config', $config ) ) . ';', 'before' );
}
