<?php
/**
 * Settings page and option accessors.
 *
 * @package EcodomQuote
 */

declare(strict_types=1);

namespace EcodomQuote;

defined( 'ABSPATH' ) || exit;

/**
 * Stores all plugin settings in a single option array.
 */
final class Settings {

	public const OPTION = 'edq_settings';
	public const PAGE   = 'edq-settings';

	/**
	 * Default values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'tg_token'             => '',
			'tg_chat_id'           => '',
			'webhook_url'          => '',
			'webhook_secret'       => '',
			'warranty_text'        => '',
			'company_name'         => get_bloginfo( 'name' ),
			'company_phone'        => '',
			'price_spread'         => 10,
			'roof_overlap_mm'      => 150,
			'trim_piece_m'         => 2.0,
			'trim_overlap_m'       => 0.1,
			'price_ridge'          => 0,
			'price_end'            => 0,
			'price_eave'           => 0,
			'price_screw'          => 0,
			'screws_per_sqm'       => 8,
			'screws_per_sqm_fence' => 6,
			'fence_post_step'      => 2.5,
			'fence_rail_price'     => 0,
			'fence_rails_limit'    => 1.8,
			'post_types'           => implode(
				"\n",
				array(
					__( 'Profile pipe 60×40', 'ecodom-quote' ) . '|650',
					__( 'Profile pipe 60×60', 'ecodom-quote' ) . '|820',
					__( 'Brick pillar', 'ecodom-quote' ) . '|3500',
				)
			),
		);
	}

	/**
	 * Returns the merged settings array or one value.
	 *
	 * @param string|null $key Optional key.
	 * @return mixed
	 */
	public static function get( ?string $key = null ): mixed {
		$saved = get_option( self::OPTION, array() );
		$all   = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		return null === $key ? $all : ( $all[ $key ] ?? null );
	}

	/**
	 * Parses "Name|price" lines into post type definitions.
	 *
	 * @return list<array{id:string,name:string,price:float}>
	 */
	public static function post_types(): array {
		$lines = preg_split( '/\r\n|\r|\n/', (string) self::get( 'post_types' ) ) ?: array();
		$out   = array();
		foreach ( $lines as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( '' === $parts[0] ) {
				continue;
			}
			$out[] = array(
				'id'    => substr( md5( $parts[0] ), 0, 8 ),
				'name'  => $parts[0],
				'price' => isset( $parts[1] ) ? Util::to_float( $parts[1] ) : 0.0,
			);
		}
		return $out;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Post_Types::MODEL,
			__( 'EcoDom Quote settings', 'ecodom-quote' ),
			__( 'Settings', 'ecodom-quote' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Field definitions grouped by section.
	 *
	 * @return array<string, array{title:string, fields: array<string, array<string, mixed>>}>
	 */
	private function schema(): array {
		return array(
			'edq_notify'  => array(
				'title'  => __( 'Notifications', 'ecodom-quote' ),
				'fields' => array(
					'tg_token'       => array(
						'label' => __( 'Telegram bot token', 'ecodom-quote' ),
						'type'  => 'password',
						'help'  => __( 'Create a bot via @BotFather and paste its token.', 'ecodom-quote' ),
					),
					'tg_chat_id'     => array(
						'label' => __( 'Telegram chat_id', 'ecodom-quote' ),
						'type'  => 'text',
						'help'  => __( 'User, group (starts with -) or channel id. The bot must be a member of the chat.', 'ecodom-quote' ),
					),
					'webhook_url'    => array(
						'label' => __( 'Webhook URL', 'ecodom-quote' ),
						'type'  => 'url',
						'help'  => __( 'Each lead is sent here as JSON via POST (CRM, Make, Zapier, n8n).', 'ecodom-quote' ),
					),
					'webhook_secret' => array(
						'label' => __( 'Webhook secret', 'ecodom-quote' ),
						'type'  => 'password',
						'help'  => __( 'Optional. When set, the X-EDQ-Signature header contains the HMAC-SHA256 of the body.', 'ecodom-quote' ),
					),
				),
			),
			'edq_company' => array(
				'title'  => __( 'Texts and company', 'ecodom-quote' ),
				'fields' => array(
					'company_name'  => array(
						'label' => __( 'Company name', 'ecodom-quote' ),
						'type'  => 'text',
					),
					'company_phone' => array(
						'label' => __( 'Company phone', 'ecodom-quote' ),
						'type'  => 'text',
					),
					'warranty_text' => array(
						'label' => __( 'Warranty text', 'ecodom-quote' ),
						'type'  => 'textarea',
						'help'  => __( 'Shown under the result and in the PDF.', 'ecodom-quote' ),
					),
					'price_spread'  => array(
						'label' => __( 'Price range, ±%', 'ecodom-quote' ),
						'type'  => 'number',
						'step'  => '1',
					),
				),
			),
			'edq_roof'    => array(
				'title'  => __( 'Roof: accessories and fasteners', 'ecodom-quote' ),
				'fields' => array(
					'roof_overlap_mm' => array(
						'label' => __( 'Vertical sheet overlap, mm', 'ecodom-quote' ),
						'type'  => 'number',
						'step'  => '1',
					),
					'trim_piece_m'    => array(
						'label' => __( 'Trim piece length, m', 'ecodom-quote' ),
						'type'  => 'number',
						'step'  => '0.01',
					),
					'trim_overlap_m'  => array(
						'label' => __( 'Trim overlap, m', 'ecodom-quote' ),
						'type'  => 'number',
						'step'  => '0.01',
					),
					'price_ridge'     => array(
						'label' => __( 'Ridge price, UAH/pc', 'ecodom-quote' ),
						'type'  => 'number',
						'step'  => '0.01',
					),
					'price_end'       => array(
						'label' => __( 'End (gable) trim price, UAH/pc', 'ecodom-quote' ),
						'type'  => 'number',
						'step'  => '0.01',
					),
					'price_eave'      => array(
						'label' => __( 'Eave trim price, UAH/pc', 'ecodom-quote' ),
						'type'  => 'number',
						'step'  => '0.01',
					),
					'price_screw'     => array(
						'label' => __( 'Roofing screw price, UAH/pc', 'ecodom-quote' ),
						'type'  => 'number',
						'step'  => '0.01',
					),
					'screws_per_sqm'  => array(
						'label' => __( 'Screws per m² (roof)', 'ecodom-quote' ),
						'type'  => 'number',
						'step'  => '1',
					),
				),
			),
			'edq_fence'   => array(
				'title'  => __( 'Fence', 'ecodom-quote' ),
				'fields' => array(
					'fence_post_step'      => array(
						'label' => __( 'Post spacing, m', 'ecodom-quote' ),
						'type'  => 'number',
						'step'  => '0.01',
					),
					'fence_rail_price'     => array(
						'label' => __( 'Rail (cross bar) price, UAH/m', 'ecodom-quote' ),
						'type'  => 'number',
						'step'  => '0.01',
					),
					'fence_rails_limit'    => array(
						'label' => __( 'Height above which 3 rails are used, m', 'ecodom-quote' ),
						'type'  => 'number',
						'step'  => '0.01',
					),
					'screws_per_sqm_fence' => array(
						'label' => __( 'Screws per m² (fence)', 'ecodom-quote' ),
						'type'  => 'number',
						'step'  => '1',
					),
					'post_types'           => array(
						'label' => __( 'Post types', 'ecodom-quote' ),
						'type'  => 'textarea',
						'help'  => __( 'One per line: "Name|price per post, UAH".', 'ecodom-quote' ),
					),
				),
			),
		);
	}

	public function register(): void {
		register_setting(
			'edq_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		foreach ( $this->schema() as $section_id => $section ) {
			add_settings_section( $section_id, $section['title'], '__return_null', self::PAGE );
			foreach ( $section['fields'] as $key => $field ) {
				add_settings_field(
					$key,
					$field['label'],
					array( $this, 'field' ),
					self::PAGE,
					$section_id,
					array_merge( $field, array( 'key' => $key, 'label_for' => 'edq-' . $key ) )
				);
			}
		}
	}

	/**
	 * Sanitizes the whole option.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? wp_unslash( $input ) : array();
		$out   = self::defaults();
		foreach ( $this->schema() as $section ) {
			foreach ( $section['fields'] as $key => $field ) {
				$raw = $input[ $key ] ?? '';
				$out[ $key ] = match ( $field['type'] ) {
					'number'   => max( 0.0, Util::to_float( $raw ) ),
					'url'      => esc_url_raw( trim( (string) $raw ), array( 'https', 'http' ) ),
					'textarea' => sanitize_textarea_field( (string) $raw ),
					default    => sanitize_text_field( (string) $raw ),
				};
			}
		}
		$out['price_spread'] = min( 50.0, (float) $out['price_spread'] );
		if ( $out['trim_overlap_m'] >= $out['trim_piece_m'] ) {
			$out['trim_overlap_m'] = 0.0;
		}
		return $out;
	}

	/**
	 * Renders a single field.
	 *
	 * @param array<string, mixed> $args Field args.
	 */
	public function field( array $args ): void {
		$key   = (string) $args['key'];
		$value = self::get( $key );
		$name  = self::OPTION . '[' . $key . ']';
		$id    = 'edq-' . $key;

		switch ( $args['type'] ) {
			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="5" class="large-text">%3$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_textarea( (string) $value )
				);
				break;
			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%2$s" value="%3$s" step="%4$s" min="0" class="small-text">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( (string) ( $args['step'] ?? 'any' ) )
				);
				break;
			default:
				printf(
					'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text" autocomplete="off">',
					esc_attr( (string) $args['type'] ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
		}
		if ( ! empty( $args['help'] ) ) {
			echo '<p class="description">' . esc_html( (string) $args['help'] ) . '</p>';
		}
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p><?php esc_html_e( 'Shortcode:', 'ecodom-quote' ); ?> <code>[ecodom_calc]</code>, <code>[ecodom_calc type="roof"]</code>, <code>[ecodom_calc type="fence" model="123"]</code></p>
			<?php if ( ! Pdf::available() ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'dompdf is not installed: leads are saved and sent, but without a PDF. Run "composer install --no-dev" in the plugin folder or install the release ZIP.', 'ecodom-quote' ); ?>
				</p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'edq_settings_group' );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
