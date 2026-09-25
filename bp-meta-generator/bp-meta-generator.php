<?php
/**
 * Plugin Name:       BP Meta Generator
 * Description:       Автоматична генерація meta description для записів, сторінок і CPT через Claude API (Anthropic). Підтримує Elementor, Yoast SEO і Rank Math.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            BP
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bp-meta-generator
 */

defined( 'ABSPATH' ) || exit;

define( 'BPMG_VERSION', '1.0.0' );
define( 'BPMG_DB_VERSION', '1' );
define( 'BPMG_FILE', __FILE__ );
define( 'BPMG_DIR', plugin_dir_path( __FILE__ ) );
define( 'BPMG_URL', plugin_dir_url( __FILE__ ) );
define( 'BPMG_API_URL', 'https://api.anthropic.com/v1/messages' );
define( 'BPMG_DEFAULT_MODEL', 'claude-haiku-4-5-20251001' );

require_once BPMG_DIR . 'includes/logger.php';
require_once BPMG_DIR . 'includes/settings.php';
require_once BPMG_DIR . 'includes/elementor-parser.php';
require_once BPMG_DIR . 'includes/generator.php';
require_once BPMG_DIR . 'includes/output.php';
require_once BPMG_DIR . 'includes/bulk.php';
require_once BPMG_DIR . 'includes/admin.php';

/**
 * Активація: дефолтні опції + таблиця логу.
 */
function bpmg_activate(): void {
	add_option( 'bpmg_settings', bpmg_defaults() );
	add_option( 'bpmg_bulk_queue', array(), '', false );
	add_option( 'bpmg_bulk_state', bpmg_bulk_default_state(), '', false );
	bpmg_install_log_table();
}
register_activation_hook( __FILE__, 'bpmg_activate' );

/**
 * Деактивація: прибрати заплановані події (опції та описи лишаються).
 */
function bpmg_deactivate(): void {
	wp_clear_scheduled_hook( 'bpmg_bulk_process' );
	wp_unschedule_hook( 'bpmg_generate_single' );
	delete_transient( 'bpmg_bulk_lock' );
}
register_deactivation_hook( __FILE__, 'bpmg_deactivate' );

/**
 * Посилання "Налаштування" у списку плагінів.
 */
function bpmg_plugin_action_links( array $links ): array {
	$url = admin_url( 'options-general.php?page=bpmg-settings' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Налаштування', 'bp-meta-generator' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'bpmg_plugin_action_links' );
