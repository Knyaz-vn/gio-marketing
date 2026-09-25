<?php
/**
 * [ecodom_calc] shortcode and conditional assets.
 *
 * @package EcodomQuote
 */

declare(strict_types=1);

namespace EcodomQuote;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the calculator.
 */
final class Shortcode {

	public const TAG = 'ecodom_calc';

	private static int $instances = 0;

	private static bool $config_added = false;

	public function register_hooks(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_early' ), 20 );
	}

	public function register_assets(): void {
		if ( wp_script_is( 'edq-calc', 'registered' ) ) {
			return;
		}
		$css = 'assets/css/calc.css';
		$js  = 'assets/js/calc.js';
		wp_register_style( 'edq-calc', EDQ_URL . $css, array(), EDQ_VERSION . '.' . (int) @filemtime( EDQ_DIR . $css ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		wp_register_script(
			'edq-calc',
			EDQ_URL . $js,
			array(),
			EDQ_VERSION . '.' . (int) @filemtime( EDQ_DIR . $js ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Enqueues CSS in <head> when the current singular post contains the shortcode (avoids a flash of unstyled content).
	 * Other placements (widgets, builders) are covered by enqueueing inside render().
	 */
	public function maybe_enqueue_early(): void {
		$post = get_post();
		if ( is_singular() && $post instanceof \WP_Post && has_shortcode( $post->post_content, self::TAG ) ) {
			wp_enqueue_style( 'edq-calc' );
		}
	}

	private function enqueue(): void {
		// Block themes render content before wp_enqueue_scripts fires, so register on demand.
		if ( ! wp_script_is( 'edq-calc', 'registered' ) ) {
			$this->register_assets();
		}
		wp_enqueue_style( 'edq-calc' );
		wp_enqueue_script( 'edq-calc' );
		if ( self::$config_added ) {
			return;
		}
		self::$config_added = true;

		$config = array(
			'restUrl'   => esc_url_raw( rest_url( Rest::NS ) ),
			'nonce'     => wp_create_nonce( Rest::NONCE_ACTION ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'cookie'    => array(
				'name' => Attribution::COOKIE,
				'days' => Attribution::DAYS,
				'keys' => Attribution::KEYS,
				'path' => COOKIEPATH ?: '/',
			),
			'currency'   => 'UAH',
			'serverTime' => time(),
			'i18n'      => array(
				'slope'        => __( 'Slope', 'ecodom-quote' ),
				'remove'       => __( 'Remove slope', 'ecodom-quote' ),
				'calculating'  => __( 'Calculating…', 'ecodom-quote' ),
				'sending'      => __( 'Sending…', 'ecodom-quote' ),
				'error'        => __( 'Something went wrong. Please try again or call us.', 'ecodom-quote' ),
				'phoneInvalid' => __( 'Enter a phone number in the format +380 XX XXX XX XX.', 'ecodom-quote' ),
				'nameInvalid'  => __( 'Enter your name.', 'ecodom-quote' ),
				'chooseModel'  => __( 'Select a model.', 'ecodom-quote' ),
				'uah'          => __( 'UAH', 'ecodom-quote' ),
				'sqm'          => __( 'm²', 'ecodom-quote' ),
				'from'         => __( 'from', 'ecodom-quote' ),
				'to'           => __( 'to', 'ecodom-quote' ),
				'sheetArea'    => __( 'Sheet area', 'ecodom-quote' ),
				'usefulArea'   => __( 'Covered area', 'ecodom-quote' ),
				'sheets'       => __( 'Sheets', 'ecodom-quote' ),
				'thickness'    => __( 'mm', 'ecodom-quote' ),
				'coatings'     => Model::coatings(),
			),
		);
		wp_add_inline_script( 'edq-calc', 'window.edqConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Resolves a template path: theme override first (your-theme/ecodom-quote/{name}.php), then the plugin.
	 *
	 * @param string $name calculator|pdf.
	 */
	public static function template( string $name ): string {
		$path = locate_template( 'ecodom-quote/' . $name . '.php' ) ?: EDQ_DIR . 'templates/' . $name . '.php';
		/**
		 * Filters a template path.
		 *
		 * @param string $path Absolute path.
		 * @param string $name Template name.
		 */
		return (string) apply_filters( 'edq_template', $path, $name );
	}

	/**
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'type'  => '',
				'model' => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		$type  = in_array( $atts['type'], array( 'roof', 'fence' ), true ) ? $atts['type'] : '';
		$fixed = Model::find( (string) $atts['model'] );

		if ( $fixed ) {
			$modes = match ( $fixed['type'] ) {
				'roof'  => array( 'roof' ),
				'fence' => array( 'fence' ),
				default => $type ? array( $type ) : array( 'roof', 'fence' ),
			};
			$models = array( $fixed );
		} else {
			$modes  = $type ? array( $type ) : array( 'roof', 'fence' );
			$models = Model::all( $type ?: null );
		}

		if ( ! $models ) {
			return current_user_can( 'edit_pages' )
				? '<p class="edq-notice">' . esc_html__( 'EcoDom calculator: add at least one published model with prices.', 'ecodom-quote' ) . '</p>'
				: '';
		}

		$this->enqueue();
		++self::$instances;

		$data = array(
			'modes'  => $modes,
			'fixed'  => (bool) $fixed,
			'models' => array_map( array( Model::class, 'to_public' ), $models ),
			'posts'  => array_map(
				static fn( array $p ): array => array(
					'id'   => $p['id'],
					'name' => $p['name'],
				),
				Settings::post_types()
			),
		);

		$uid      = 'edq-' . self::$instances;
		$warranty = (string) Settings::get( 'warranty_text' );

		ob_start();
		include self::template( 'calculator' );
		return (string) ob_get_clean();
	}
}
