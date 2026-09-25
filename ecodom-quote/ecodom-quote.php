<?php
/**
 * Plugin Name:       EcoDom Quote
 * Description:       Roof and fence calculator for metal tile and corrugated sheet: estimate, lead capture, PDF, Telegram and webhook.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            EcoDom
 * License:           GPL-2.0-or-later
 * Text Domain:       ecodom-quote
 * Domain Path:       /languages
 *
 * @package EcodomQuote
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

const EDQ_VERSION = '1.0.0';
const EDQ_FILE    = __FILE__;
define( 'EDQ_DIR', plugin_dir_path( __FILE__ ) );
define( 'EDQ_URL', plugin_dir_url( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'EcoDom Quote requires PHP 8.1 or newer.', 'ecodom-quote' ) . '</p></div>';
		}
	);
	return;
}

// Optional bundled dependencies (dompdf). Built with `composer install --no-dev`.
if ( is_readable( EDQ_DIR . 'vendor/autoload.php' ) ) {
	require_once EDQ_DIR . 'vendor/autoload.php';
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'EcodomQuote\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$name = substr( $class, strlen( $prefix ) );
		$file = EDQ_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( \EcodomQuote\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \EcodomQuote\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \EcodomQuote\Plugin::class, 'instance' ) );
