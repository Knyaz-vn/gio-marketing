<?php
/**
 * Custom post types: models and leads. Model meta box.
 *
 * @package EcodomQuote
 */

declare(strict_types=1);

namespace EcodomQuote;

defined( 'ABSPATH' ) || exit;

/**
 * Registers ecodom_model and ecodom_lead.
 */
final class Post_Types {

	public const MODEL = 'ecodom_model';
	public const LEAD  = 'ecodom_lead';

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'add_meta_boxes_' . self::MODEL, array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . self::MODEL, array( $this, 'save' ), 10, 2 );
		add_filter( 'manage_' . self::MODEL . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::MODEL . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
	}

	public function register(): void {
		register_post_type(
			self::MODEL,
			array(
				'labels'          => array(
					'name'          => __( 'Models', 'ecodom-quote' ),
					'singular_name' => __( 'Model', 'ecodom-quote' ),
					'menu_name'     => __( 'EcoDom Quote', 'ecodom-quote' ),
					'all_items'     => __( 'Models', 'ecodom-quote' ),
					'add_new'       => __( 'Add model', 'ecodom-quote' ),
					'add_new_item'  => __( 'Add model', 'ecodom-quote' ),
					'edit_item'     => __( 'Edit model', 'ecodom-quote' ),
					'search_items'  => __( 'Search models', 'ecodom-quote' ),
					'not_found'     => __( 'No models found', 'ecodom-quote' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-calculator',
				'menu_position'   => 26,
				'supports'        => array( 'title', 'thumbnail', 'page-attributes' ),
				'capability_type' => 'page',
				'map_meta_cap'    => true,
				'show_in_rest'    => false,
			)
		);

		register_post_type(
			self::LEAD,
			array(
				'labels'          => array(
					'name'          => __( 'Leads', 'ecodom-quote' ),
					'singular_name' => __( 'Lead', 'ecodom-quote' ),
					'all_items'     => __( 'Leads', 'ecodom-quote' ),
					'edit_item'     => __( 'Lead', 'ecodom-quote' ),
					'search_items'  => __( 'Search leads', 'ecodom-quote' ),
					'not_found'     => __( 'No leads yet', 'ecodom-quote' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . self::MODEL,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
				'show_in_rest'    => false,
			)
		);
	}

	public function add_meta_box(): void {
		add_meta_box( 'edq-model', __( 'Model parameters and prices', 'ecodom-quote' ), array( $this, 'render_meta_box' ), self::MODEL, 'normal', 'high' );
	}

	public function render_meta_box( \WP_Post $post ): void {
		$m = Model::from_post( $post );
		wp_nonce_field( 'edq_model_save', 'edq_model_nonce' );

		$dims = array(
			'width_total'  => __( 'Total width, mm', 'ecodom-quote' ),
			'width_useful' => __( 'Useful (effective) width, mm', 'ecodom-quote' ),
			'wave_step'    => __( 'Wave step, mm', 'ecodom-quote' ),
			'length_min'   => __( 'Min sheet length, mm', 'ecodom-quote' ),
			'length_max'   => __( 'Max sheet length, mm', 'ecodom-quote' ),
		);
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="edq-type"><?php esc_html_e( 'Type', 'ecodom-quote' ); ?></label></th>
				<td>
					<select id="edq-type" name="edq[type]">
						<?php foreach ( Model::types() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $m['type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Roof — metal tile (the wave step is along the sheet); corrugated sheet can be used in both roof and fence calculators.', 'ecodom-quote' ); ?></p>
				</td>
			</tr>
			<?php foreach ( $dims as $key => $label ) : ?>
				<tr>
					<th scope="row"><label for="edq-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td><input type="number" min="0" step="1" class="small-text" id="edq-<?php echo esc_attr( $key ); ?>" name="edq[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $m[ $key ] ); ?>"></td>
				</tr>
			<?php endforeach; ?>
		</table>

		<h4><?php esc_html_e( 'Price per m², UAH (leave 0 if the variant is not available)', 'ecodom-quote' ); ?></h4>
		<table class="widefat striped" style="max-width:520px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Thickness, mm', 'ecodom-quote' ); ?></th>
					<?php foreach ( Model::coatings() as $label ) : ?>
						<th><?php echo esc_html( $label ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( Model::THICKNESSES as $t ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $t ); ?></th>
						<?php foreach ( array_keys( Model::coatings() ) as $c ) : ?>
							<td>
								<input type="number" min="0" step="0.01" style="width:110px"
									name="edq[prices][<?php echo esc_attr( $t ); ?>][<?php echo esc_attr( $c ); ?>]"
									value="<?php echo esc_attr( (string) ( $m['prices'][ $t ][ $c ] ?? 0 ) ); ?>"
									aria-label="<?php echo esc_attr( $t . ' / ' . Model::coatings()[ $c ] ); ?>">
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php
			/* translators: %d: post ID */
			printf( esc_html__( 'Shortcode for this model: [ecodom_calc model="%d"]', 'ecodom-quote' ), (int) $post->ID );
			?>
		</p>
		<?php
	}

	public function save( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$nonce = isset( $_POST['edq_model_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['edq_model_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'edq_model_save' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// Sanitized field by field in Model::save_meta().
		$raw = isset( $_POST['edq'] ) && is_array( $_POST['edq'] ) ? wp_unslash( $_POST['edq'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		Model::save_meta( $post_id, $raw );
	}

	/**
	 * @param array<string, string> $cols Columns.
	 * @return array<string, string>
	 */
	public function columns( array $cols ): array {
		$date = $cols['date'] ?? null;
		unset( $cols['date'] );
		$cols['edq_type']  = __( 'Type', 'ecodom-quote' );
		$cols['edq_width'] = __( 'Width total / useful', 'ecodom-quote' );
		$cols['edq_price'] = __( 'Price from, UAH/m²', 'ecodom-quote' );
		$cols['edq_code']  = __( 'Shortcode', 'ecodom-quote' );
		if ( $date ) {
			$cols['date'] = $date;
		}
		return $cols;
	}

	public function column( string $col, int $post_id ): void {
		$m = Model::get( $post_id );
		if ( ! $m ) {
			return;
		}
		switch ( $col ) {
			case 'edq_type':
				echo esc_html( Model::types()[ $m['type'] ] ?? $m['type'] );
				break;
			case 'edq_width':
				echo esc_html( $m['width_total'] . ' / ' . $m['width_useful'] );
				break;
			case 'edq_price':
				$prices = array_filter( array_merge( ...array_map( 'array_values', array_values( $m['prices'] ) ) ) );
				echo $prices ? esc_html( Util::num( (float) min( $prices ) ) ) : '—';
				break;
			case 'edq_code':
				echo '<code>[ecodom_calc model="' . (int) $post_id . '"]</code>';
				break;
		}
	}
}
