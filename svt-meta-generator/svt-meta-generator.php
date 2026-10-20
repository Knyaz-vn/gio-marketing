<?php
/**
 * Plugin Name:       SVT Meta Generator
 * Description:       Генерація meta description для товарів WooCommerce, категорій, сторінок і записів через Claude API (Anthropic). Підтримує Polylang/WPML (RU + UK), Elementor, Yoast SEO і Rank Math.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            SVT Market
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       svt-meta-generator
 */

defined( 'ABSPATH' ) || exit;

define( 'SVTMG_VERSION', '1.0.0' );
define( 'SVTMG_DB_VERSION', '1' );
define( 'SVTMG_FILE', __FILE__ );
define( 'SVTMG_DIR', plugin_dir_path( __FILE__ ) );
define( 'SVTMG_URL', plugin_dir_url( __FILE__ ) );
define( 'SVTMG_API_URL', 'https://api.anthropic.com/v1/messages' );
define( 'SVTMG_DEFAULT_MODEL', 'claude-haiku-4-5-20251001' );

require_once SVTMG_DIR . 'includes/logger.php';
require_once SVTMG_DIR . 'includes/language.php';
require_once SVTMG_DIR . 'includes/settings.php';
require_once SVTMG_DIR . 'includes/storage.php';
require_once SVTMG_DIR . 'includes/elementor-parser.php';
require_once SVTMG_DIR . 'includes/sources.php';
require_once SVTMG_DIR . 'includes/generator.php';
require_once SVTMG_DIR . 'includes/output.php';
require_once SVTMG_DIR . 'includes/bulk.php';
require_once SVTMG_DIR . 'includes/admin.php';
require_once SVTMG_DIR . 'includes/terms-admin.php';

function svtmg_activate(): void {
	add_option( 'svtmg_settings', svtmg_defaults() );
	add_option( 'svtmg_bulk_queue', array(), '', false );
	add_option( 'svtmg_bulk_state', svtmg_bulk_default_state(), '', false );
	svtmg_install_log_table();
}
register_activation_hook( __FILE__, 'svtmg_activate' );

function svtmg_deactivate(): void {
	wp_clear_scheduled_hook( 'svtmg_bulk_process' );
	delete_transient( 'svtmg_bulk_lock' );
}
register_deactivation_hook( __FILE__, 'svtmg_deactivate' );

function svtmg_plugin_action_links( array $links ): array {
	$url = admin_url( 'options-general.php?page=svtmg-settings' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Налаштування', 'svt-meta-generator' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'svtmg_plugin_action_links' );
