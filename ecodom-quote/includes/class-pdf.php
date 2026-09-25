<?php
/**
 * PDF estimate generation with dompdf.
 *
 * @package EcodomQuote
 */

declare(strict_types=1);

namespace EcodomQuote;

defined( 'ABSPATH' ) || exit;

/**
 * Renders templates/pdf.php into a private file under uploads.
 */
final class Pdf {

	private const DIR = 'ecodom-quote-private';

	public static function available(): bool {
		return class_exists( \Dompdf\Dompdf::class );
	}

	/**
	 * Private storage directory (created on demand, closed for direct web access on Apache).
	 */
	public static function ensure_storage_dir(): string {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . self::DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$guards = array(
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
		);
		foreach ( $guards as $file => $content ) {
			if ( ! file_exists( $dir . '/' . $file ) ) {
				file_put_contents( $dir . '/' . $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		return $dir;
	}

	/**
	 * Absolute path of a lead's PDF or null.
	 */
	public static function path_for( int $lead_id ): ?string {
		$file = basename( (string) get_post_meta( $lead_id, '_edq_pdf', true ) );
		if ( '' === $file ) {
			return null;
		}
		$path = self::ensure_storage_dir() . '/' . $file;
		return is_readable( $path ) ? $path : null;
	}

	/**
	 * Renders the estimate HTML.
	 *
	 * @param array<string, mixed> $lead Lead data (see Lead_Service::create()).
	 */
	public function html( array $lead ): string {
		$settings = Settings::get();
		ob_start();
		include Shortcode::template( 'pdf' );
		return (string) ob_get_clean();
	}

	/**
	 * Generates the PDF and returns its path.
	 *
	 * @param array<string, mixed> $lead Lead data.
	 * @throws \RuntimeException When dompdf is missing.
	 */
	public function generate( array $lead ): string {
		if ( ! self::available() ) {
			throw new \RuntimeException( 'dompdf is not installed' );
		}
		$dir   = self::ensure_storage_dir();
		$fonts = $dir . '/fonts';
		if ( ! is_dir( $fonts ) ) {
			wp_mkdir_p( $fonts );
		}

		$options = new \Dompdf\Options();
		$options->set( 'defaultFont', 'DejaVu Sans' );
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'isPhpEnabled', false );
		$options->set( 'fontCache', $fonts );
		$options->set( 'tempDir', get_temp_dir() );
		$options->set( 'chroot', array( EDQ_DIR, $dir ) );

		$dompdf = new \Dompdf\Dompdf( $options );
		$dompdf->loadHtml( $this->html( $lead ), 'UTF-8' );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();

		$file = sprintf( 'estimate-%d-%s.pdf', (int) $lead['id'], wp_generate_password( 16, false ) );
		$path = $dir . '/' . $file;
		file_put_contents( $path, (string) $dompdf->output() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return $path;
	}
}
