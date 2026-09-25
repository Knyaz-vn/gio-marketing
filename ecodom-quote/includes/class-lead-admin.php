<?php
/**
 * Lead screens in wp-admin.
 *
 * @package EcodomQuote
 */

declare(strict_types=1);

namespace EcodomQuote;

defined( 'ABSPATH' ) || exit;

/**
 * List columns, details meta box and private PDF download.
 */
final class Lead_Admin {

	public function register_hooks(): void {
		add_filter( 'manage_' . Post_Types::LEAD . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . Post_Types::LEAD . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
		add_action( 'add_meta_boxes_' . Post_Types::LEAD, array( $this, 'meta_boxes' ) );
		add_action( 'admin_post_edq_pdf', array( $this, 'download_pdf' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'delete_pdf' ) );
	}

	/**
	 * Removes the PDF file together with the lead.
	 */
	public function delete_pdf( int $post_id ): void {
		if ( Post_Types::LEAD === get_post_type( $post_id ) ) {
			$path = Pdf::path_for( $post_id );
			if ( $path ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * @param array<string, string> $cols Columns.
	 * @return array<string, string>
	 */
	public function columns( array $cols ): array {
		return array(
			'cb'          => $cols['cb'] ?? '',
			'title'       => __( 'Lead', 'ecodom-quote' ),
			'edq_channel' => __( 'Channel', 'ecodom-quote' ),
			'edq_model'   => __( 'Calculation', 'ecodom-quote' ),
			'edq_total'   => __( 'Sum', 'ecodom-quote' ),
			'edq_source'  => __( 'Source', 'ecodom-quote' ),
			'edq_status'  => __( 'Delivery', 'ecodom-quote' ),
			'date'        => $cols['date'] ?? __( 'Date', 'ecodom-quote' ),
		);
	}

	public function column( string $col, int $post_id ): void {
		$d = Lead_Service::get( $post_id );
		if ( ! $d ) {
			return;
		}
		switch ( $col ) {
			case 'edq_channel':
				echo esc_html( Lead_Service::channels()[ $d['contact']['channel'] ] ?? '' );
				break;
			case 'edq_model':
				echo esc_html( sprintf( '%s · %s · %s m²', 'fence' === $d['result']['mode'] ? __( 'Fence', 'ecodom-quote' ) : __( 'Roof', 'ecodom-quote' ), $d['result']['model_title'], Util::num( (float) $d['result']['sqm'] ) ) );
				break;
			case 'edq_total':
				echo esc_html( Util::money( (float) $d['result']['total'] ) );
				break;
			case 'edq_source':
				$a = $d['attribution'];
				echo esc_html( trim( ( $a['utm_source'] ?? '' ) . ' / ' . ( $a['utm_medium'] ?? '' ), ' /' ) ?: ( isset( $a['gclid'] ) ? 'gclid' : ( isset( $a['fbclid'] ) ? 'fbclid' : '—' ) ) );
				break;
			case 'edq_status':
				foreach ( array( 'telegram' => 'TG', 'webhook' => 'Hook' ) as $ch => $label ) {
					$status = (string) get_post_meta( $post_id, '_edq_status_' . $ch, true );
					$ok     = 'ok' === $status || str_starts_with( $status, 'ok;' ) || str_starts_with( $status, 'http 2' );
					printf(
						'<span title="%1$s" style="margin-right:6px;color:%2$s">%3$s %4$s</span>',
						esc_attr( $status ),
						esc_attr( $ok ? '#1a7f37' : ( 'skipped' === $status ? '#8c8f94' : '#d63638' ) ),
						esc_html( $label ),
						$ok ? '✓' : ( 'skipped' === $status ? '–' : '✕' )
					);
				}
				break;
		}
	}

	/**
	 * @param array<string, string> $actions Row actions.
	 * @return array<string, string>
	 */
	public function row_actions( array $actions, \WP_Post $post ): array {
		if ( Post_Types::LEAD === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'] );
			if ( Pdf::path_for( $post->ID ) ) {
				$actions['edq_pdf'] = '<a href="' . esc_url( self::pdf_url( $post->ID ) ) . '">PDF</a>';
			}
		}
		return $actions;
	}

	public static function pdf_url( int $lead_id ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=edq_pdf&lead=' . $lead_id ), 'edq_pdf_' . $lead_id );
	}

	public function meta_boxes(): void {
		add_meta_box( 'edq-lead', __( 'Lead details', 'ecodom-quote' ), array( $this, 'render' ), Post_Types::LEAD, 'normal', 'high' );
	}

	public function render( \WP_Post $post ): void {
		$d = Lead_Service::get( $post->ID );
		if ( ! $d ) {
			esc_html_e( 'No data.', 'ecodom-quote' );
			return;
		}
		$r    = $d['result'];
		$rows = array(
			__( 'Name', 'ecodom-quote' )        => $d['contact']['name'],
			__( 'Phone', 'ecodom-quote' )       => $d['contact']['phone'],
			__( 'Channel', 'ecodom-quote' )     => Lead_Service::channels()[ $d['contact']['channel'] ] ?? '',
			__( 'Model', 'ecodom-quote' )       => $r['model_title'] . ', ' . $r['thickness'] . ' ' . __( 'mm', 'ecodom-quote' ) . ', ' . $r['coating_txt'],
			__( 'Sheet area', 'ecodom-quote' )  => Util::num( (float) $r['sqm'] ) . ' ' . __( 'm²', 'ecodom-quote' ),
			__( 'Price range', 'ecodom-quote' ) => Util::money( (float) $r['min'] ) . ' – ' . Util::money( (float) $r['max'] ),
			__( 'Page', 'ecodom-quote' )        => $d['page_url'],
		);
		foreach ( $d['attribution'] as $k => $v ) {
			$rows[ $k ] = $v;
		}
		foreach ( array( 'telegram', 'webhook', 'pdf' ) as $ch ) {
			$status = (string) get_post_meta( $post->ID, '_edq_status_' . $ch, true );
			if ( '' !== $status ) {
				/* translators: %s: channel */
				$rows[ sprintf( __( 'Status: %s', 'ecodom-quote' ), $ch ) ] = $status;
			}
		}
		?>
		<table class="widefat striped">
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr>
					<th style="width:180px"><?php echo esc_html( (string) $label ); ?></th>
					<td>
						<?php
						$phone = ( __( 'Phone', 'ecodom-quote' ) === $label );
						if ( $phone ) {
							echo '<a href="' . esc_url( 'tel:' . $value ) . '">' . esc_html( (string) $value ) . '</a>';
						} elseif ( is_string( $value ) && str_starts_with( $value, 'http' ) ) {
							echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener">' . esc_html( $value ) . '</a>';
						} else {
							echo esc_html( (string) $value );
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>

		<h4><?php esc_html_e( 'Materials', 'ecodom-quote' ); ?></h4>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Item', 'ecodom-quote' ); ?></th>
				<th><?php esc_html_e( 'Qty', 'ecodom-quote' ); ?></th>
				<th><?php esc_html_e( 'Price', 'ecodom-quote' ); ?></th>
				<th><?php esc_html_e( 'Sum', 'ecodom-quote' ); ?></th>
			</tr></thead>
			<?php foreach ( $r['positions'] as $p ) : ?>
				<tr>
					<td><?php echo esc_html( $p['name'] ); ?></td>
					<td><?php echo esc_html( $p['qty'] . ' ' . $p['unit'] ); ?></td>
					<td><?php echo esc_html( Util::money( (float) $p['price'] ) ); ?></td>
					<td><?php echo esc_html( Util::money( (float) $p['sum'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
		<p>
			<?php if ( Pdf::path_for( $post->ID ) ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( self::pdf_url( $post->ID ) ); ?>"><?php esc_html_e( 'Download PDF', 'ecodom-quote' ); ?></a>
			<?php endif; ?>
		</p>
		<details>
			<summary><?php esc_html_e( 'Raw input', 'ecodom-quote' ); ?></summary>
			<pre style="white-space:pre-wrap"><?php echo esc_html( (string) wp_json_encode( $d['input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
		</details>
		<?php
	}

	public function download_pdf(): void {
		$lead_id = isset( $_GET['lead'] ) ? absint( $_GET['lead'] ) : 0;
		check_admin_referer( 'edq_pdf_' . $lead_id );
		if ( ! current_user_can( 'edit_post', $lead_id ) || Post_Types::LEAD !== get_post_type( $lead_id ) ) {
			wp_die( esc_html__( 'Access denied.', 'ecodom-quote' ), 403 );
		}
		$path = Pdf::path_for( $lead_id );
		if ( ! $path ) {
			wp_die( esc_html__( 'PDF not found.', 'ecodom-quote' ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="estimate-' . $lead_id . '.pdf"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
