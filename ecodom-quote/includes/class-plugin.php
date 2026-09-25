<?php
/**
 * Plugin bootstrap.
 *
 * @package EcodomQuote
 */

declare(strict_types=1);

namespace EcodomQuote;

defined( 'ABSPATH' ) || exit;

/**
 * Wires all components together.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );

		( new Post_Types() )->register_hooks();
		( new Settings() )->register_hooks();
		( new Shortcode() )->register_hooks();
		( new Rest() )->register_hooks();
		( new Attribution() )->register_hooks();
		( new Lead_Admin() )->register_hooks();
		( new Importer() )->register_hooks();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'edq', Cli::class );
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'ecodom-quote', false, dirname( plugin_basename( EDQ_FILE ) ) . '/languages' );
	}

	public static function activate(): void {
		load_plugin_textdomain( 'ecodom-quote', false, dirname( plugin_basename( EDQ_FILE ) ) . '/languages' );
		( new Post_Types() )->register();
		Pdf::ensure_storage_dir();
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
