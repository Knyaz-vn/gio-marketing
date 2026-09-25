<?php
/**
 * WP-CLI commands: wp edq import|export|test-notify.
 *
 * @package EcodomQuote
 */

declare(strict_types=1);

namespace EcodomQuote;

defined( 'ABSPATH' ) || exit;

/**
 * Manage EcoDom Quote models and prices.
 */
final class Cli {

	/**
	 * Imports models and prices from CSV.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to CSV (see the Import / export page for columns).
	 *
	 * ## EXAMPLES
	 *
	 *     wp edq import prices.csv
	 *
	 * @param list<string> $args Args.
	 */
	public function import( array $args ): void {
		$file = $args[0] ?? '';
		if ( ! is_readable( $file ) ) {
			\WP_CLI::error( "Cannot read {$file}" );
		}
		$r = Importer::import_file( $file );
		foreach ( $r['errors'] as $e ) {
			\WP_CLI::warning( $e );
		}
		\WP_CLI::success( sprintf( 'Created: %d, updated: %d', $r['created'], $r['updated'] ) );
	}

	/**
	 * Exports models and prices as CSV to STDOUT.
	 *
	 * ## EXAMPLES
	 *
	 *     wp edq export > prices.csv
	 */
	public function export(): void {
		Importer::write_csv( STDOUT );
	}

	/**
	 * Sends a test message to Telegram and the webhook with a fake lead.
	 *
	 * @subcommand test-notify
	 */
	public function test_notify(): void {
		$models = Model::all();
		if ( ! $models ) {
			\WP_CLI::error( 'Create a model first.' );
		}
		$model = $models[0];
		$t     = '';
		$c     = '';
		foreach ( $model['prices'] as $th => $by ) {
			foreach ( $by as $co => $p ) {
				if ( $p > 0 && '' === $t ) {
					$t = $th;
					$c = $co;
				}
			}
		}
		$input  = array(
			'mode'      => 'fence' === $model['type'] ? 'fence' : 'roof',
			'thickness' => $t,
			'coating'   => $c,
			'slopes'    => array( array( 'shape' => 'rect', 'a' => 8, 'h' => 5 ), array( 'shape' => 'rect', 'a' => 8, 'h' => 5 ) ),
			'fence'     => array( 'length' => 30, 'height' => 2 ),
		);
		$result = ( new Calculator( Settings::get() ) )->calculate( $model, $input );
		$data   = array(
			'id'          => 0,
			'created_at'  => current_time( 'mysql' ),
			'created_gmt' => gmdate( 'c' ),
			'contact'     => array( 'name' => 'Test', 'phone' => '+380000000000', 'channel' => 'call' ),
			'input'       => $input,
			'result'      => $result,
			'attribution' => array( 'utm_source' => 'test' ),
			'page_url'    => home_url( '/' ),
		);
		$pdf = null;
		if ( Pdf::available() ) {
			$pdf = ( new Pdf() )->generate( $data );
			\WP_CLI::log( "PDF: {$pdf}" );
		}
		\WP_CLI::log( 'Telegram: ' . ( new Telegram() )->send_lead( $data, $pdf ) );
		\WP_CLI::log( 'Webhook: ' . ( new Lead_Service() )->send_webhook( $data ) );
		if ( $pdf ) {
			wp_delete_file( $pdf );
		}
	}
}
