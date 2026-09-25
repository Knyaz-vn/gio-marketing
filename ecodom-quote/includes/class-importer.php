<?php
/**
 * CSV import/export of models and prices.
 *
 * @package EcodomQuote
 */

declare(strict_types=1);

namespace EcodomQuote;

defined( 'ABSPATH' ) || exit;

/**
 * Columns: slug;title;type;width_total;width_useful;wave_step;length_min;length_max;
 *          p040_gloss;p040_matt;p045_gloss;p045_matt;p050_gloss;p050_matt
 */
final class Importer {

	public const PAGE = 'edq-import';

	/**
	 * CSV columns.
	 *
	 * @return list<string>
	 */
	public static function columns(): array {
		$cols = array( 'slug', 'title', 'type', 'width_total', 'width_useful', 'wave_step', 'length_min', 'length_max' );
		foreach ( Model::THICKNESSES as $t ) {
			foreach ( array_keys( Model::coatings() ) as $c ) {
				$cols[] = self::price_col( $t, $c );
			}
		}
		return $cols;
	}

	private static function price_col( string $thickness, string $coating ): string {
		return 'p' . str_replace( '.', '', $thickness ) . '_' . $coating;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_edq_import', array( $this, 'handle_import' ) );
		add_action( 'admin_post_edq_export', array( $this, 'handle_export' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Post_Types::MODEL,
			__( 'Import / export prices', 'ecodom-quote' ),
			__( 'Import / export', 'ecodom-quote' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$report = get_transient( 'edq_import_report_' . get_current_user_id() );
		delete_transient( 'edq_import_report_' . get_current_user_id() );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php if ( is_array( $report ) ) : ?>
				<div class="notice notice-<?php echo empty( $report['errors'] ) ? 'success' : 'warning'; ?>"><p>
					<?php
					/* translators: 1: created, 2: updated */
					echo esc_html( sprintf( __( 'Import finished: %1$d created, %2$d updated.', 'ecodom-quote' ), (int) $report['created'], (int) $report['updated'] ) );
					?>
				</p>
				<?php foreach ( (array) $report['errors'] as $err ) : ?>
					<p><?php echo esc_html( (string) $err ); ?></p>
				<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Export', 'ecodom-quote' ); ?></h2>
			<p><?php esc_html_e( 'Download all models as CSV (UTF-8, semicolon separated — opens in Excel / Google Sheets). Use it as a template.', 'ecodom-quote' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=edq_export' ), 'edq_export' ) ); ?>"><?php esc_html_e( 'Download CSV', 'ecodom-quote' ); ?></a></p>

			<h2><?php esc_html_e( 'Import', 'ecodom-quote' ); ?></h2>
			<p><?php esc_html_e( 'Models are matched by slug (or by title when slug is empty): existing ones are updated, new ones are created. Separator ";" or ",", decimal comma is allowed. Empty price cells keep the current price.', 'ecodom-quote' ); ?></p>
			<p><code><?php echo esc_html( implode( ';', self::columns() ) ); ?></code></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="edq_import">
				<?php wp_nonce_field( 'edq_import' ); ?>
				<input type="file" name="edq_csv" accept=".csv,text/csv" required>
				<?php submit_button( __( 'Import', 'ecodom-quote' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_import(): void {
		check_admin_referer( 'edq_import' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'ecodom-quote' ), 403 );
		}
		$tmp = isset( $_FILES['edq_csv']['tmp_name'] ) ? (string) $_FILES['edq_csv']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			$report = array(
				'created' => 0,
				'updated' => 0,
				'errors'  => array( __( 'File was not uploaded.', 'ecodom-quote' ) ),
			);
		} else {
			$report = self::import_file( $tmp );
		}
		set_transient( 'edq_import_report_' . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Post_Types::MODEL . '&page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Imports a CSV file.
	 *
	 * @return array{created:int,updated:int,errors:list<string>}
	 */
	public static function import_file( string $path ): array {
		$report = array(
			'created' => 0,
			'updated' => 0,
			'errors'  => array(),
		);
		$raw = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw ) ?? $raw;
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = (string) mb_convert_encoding( $raw, 'UTF-8', 'Windows-1251' );
		}
		$lines = preg_split( '/\r\n|\r|\n/', trim( $raw ) ) ?: array();
		if ( count( $lines ) < 2 ) {
			$report['errors'][] = __( 'The file is empty.', 'ecodom-quote' );
			return $report;
		}
		$sep    = substr_count( $lines[0], ';' ) >= substr_count( $lines[0], ',' ) ? ';' : ',';
		$header = array_map( static fn( $h ) => sanitize_key( trim( (string) $h ) ), str_getcsv( array_shift( $lines ), $sep, '"', '' ) );
		if ( ! in_array( 'title', $header, true ) && ! in_array( 'slug', $header, true ) ) {
			$report['errors'][] = __( 'The header must contain a "title" or "slug" column.', 'ecodom-quote' );
			return $report;
		}

		foreach ( $lines as $n => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells = str_getcsv( $line, $sep, '"', '' );
			$row   = array();
			foreach ( $header as $i => $key ) {
				$row[ $key ] = trim( (string) ( $cells[ $i ] ?? '' ) );
			}
			$result = self::import_row( $row );
			if ( is_string( $result ) ) {
				/* translators: 1: line number, 2: error */
				$report['errors'][] = sprintf( __( 'Line %1$d: %2$s', 'ecodom-quote' ), $n + 2, $result );
			} else {
				++$report[ $result ? 'created' : 'updated' ];
			}
		}
		return $report;
	}

	/**
	 * Upserts one model.
	 *
	 * @param array<string, string> $row Row.
	 * @return bool|string True if created, false if updated, string on error.
	 */
	public static function import_row( array $row ): bool|string {
		$slug  = sanitize_title( $row['slug'] ?? '' );
		$title = sanitize_text_field( $row['title'] ?? '' );
		if ( '' === $slug && '' === $title ) {
			return __( 'slug or title is required', 'ecodom-quote' );
		}

		$existing = null;
		if ( '' !== $slug ) {
			$existing = get_page_by_path( $slug, OBJECT, Post_Types::MODEL );
		}
		if ( ! $existing && '' !== $title ) {
			$found    = get_posts(
				array(
					'post_type'      => Post_Types::MODEL,
					'post_status'    => 'any',
					'title'          => $title,
					'posts_per_page' => 1,
				)
			);
			$existing = $found[0] ?? null;
		}

		$current = $existing instanceof \WP_Post ? Model::from_post( $existing ) : null;
		$post_id = $existing instanceof \WP_Post ? $existing->ID : 0;
		if ( ! $post_id ) {
			$post_id = wp_insert_post(
				array(
					'post_type'   => Post_Types::MODEL,
					'post_status' => 'publish',
					'post_title'  => $title ?: $slug,
					'post_name'   => $slug,
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				return $post_id->get_error_message();
			}
		} elseif ( '' !== $title && $title !== $existing->post_title ) {
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => $title,
				)
			);
		}

		$meta = array( 'type' => '' !== ( $row['type'] ?? '' ) ? $row['type'] : ( $current['type'] ?? 'roof' ) );
		foreach ( array( 'width_total', 'width_useful', 'wave_step', 'length_min', 'length_max' ) as $k ) {
			$meta[ $k ] = '' !== ( $row[ $k ] ?? '' ) ? $row[ $k ] : ( $current[ $k ] ?? 0 );
		}
		$meta['prices'] = $current['prices'] ?? array();
		foreach ( Model::THICKNESSES as $t ) {
			foreach ( array_keys( Model::coatings() ) as $c ) {
				$col = self::price_col( $t, $c );
				if ( isset( $row[ $col ] ) && '' !== $row[ $col ] ) {
					$meta['prices'][ $t ][ $c ] = $row[ $col ];
				}
			}
		}
		Model::save_meta( (int) $post_id, $meta );
		return null === $current;
	}

	/**
	 * CSV rows for all models.
	 *
	 * @return list<list<string>>
	 */
	public static function export_rows(): array {
		$rows  = array( self::columns() );
		$posts = get_posts(
			array(
				'post_type'      => Post_Types::MODEL,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);
		foreach ( $posts as $post ) {
			$m   = Model::from_post( $post );
			$row = array( $m['slug'], $m['title'], $m['type'], $m['width_total'], $m['width_useful'], $m['wave_step'], $m['length_min'], $m['length_max'] );
			foreach ( Model::THICKNESSES as $t ) {
				foreach ( array_keys( Model::coatings() ) as $c ) {
					$row[] = str_replace( '.', ',', (string) $m['prices'][ $t ][ $c ] );
				}
			}
			$rows[] = array_map( 'strval', $row );
		}
		return $rows;
	}

	/**
	 * Writes CSV to a stream.
	 *
	 * @param resource $fh Stream.
	 */
	public static function write_csv( $fh ): void {
		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM for Excel.
		foreach ( self::export_rows() as $row ) {
			fputcsv( $fh, $row, ';', '"', '' );
		}
	}

	public function handle_export(): void {
		check_admin_referer( 'edq_export' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'ecodom-quote' ), 403 );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ecodom-models-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $out ) {
			self::write_csv( $out );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		exit;
	}
}
