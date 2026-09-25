<?php
/**
 * Public REST endpoints: /calc and /lead.
 *
 * @package EcodomQuote
 */

declare(strict_types=1);

namespace EcodomQuote;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller.
 */
final class Rest {

	public const NS           = 'ecodom-quote/v1';
	public const NONCE_ACTION = 'edq_public';

	private const LEAD_LIMIT  = 5;   // Per IP per hour.
	private const CALC_LIMIT  = 120; // Per IP per hour.
	private const MIN_FILL_S  = 3;   // Faster submissions are treated as bots.

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			self::NS,
			'/calc',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'calc' ),
				'permission_callback' => array( $this, 'check_nonce' ),
			)
		);
		register_rest_route(
			self::NS,
			'/lead',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'lead' ),
				'permission_callback' => array( $this, 'check_nonce' ),
			)
		);
	}

	/**
	 * Public endpoints protected by a nonce printed on the calculator page.
	 */
	public function check_nonce( \WP_REST_Request $request ): bool|\WP_Error {
		$nonce = (string) ( $request->get_param( 'nonce' ) ?? '' );
		if ( ! wp_verify_nonce( sanitize_text_field( $nonce ), self::NONCE_ACTION ) ) {
			return new \WP_Error( 'edq_nonce', __( 'The page has expired. Please refresh it and try again.', 'ecodom-quote' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public function calc( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! Rate_Limiter::hit( 'calc', self::CALC_LIMIT, HOUR_IN_SECONDS ) ) {
			return $this->too_many();
		}
		$result = $this->run_calc( $request );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $this->public_result( $result ) );
	}

	public function lead( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$lead = $request->get_param( 'lead' );
		$lead = is_array( $lead ) ? $lead : array();

		// Honeypot and time trap: pretend success so bots do not retry.
		$ts = (int) ( $lead['edq_ts'] ?? 0 );
		if ( ! empty( $lead['edq_website'] ) || ( $ts > 0 && ( time() - $ts ) < self::MIN_FILL_S ) ) {
			return rest_ensure_response(
				array(
					'ok'      => true,
					'lead_id' => 0,
				)
			);
		}

		if ( ! Rate_Limiter::hit( 'lead', self::LEAD_LIMIT, HOUR_IN_SECONDS ) ) {
			return $this->too_many();
		}

		$name    = sanitize_text_field( (string) ( $lead['name'] ?? '' ) );
		$name    = mb_substr( $name, 0, 80 );
		$phone   = Util::normalize_phone( (string) ( $lead['phone'] ?? '' ) );
		$channel = sanitize_key( (string) ( $lead['channel'] ?? 'call' ) );
		if ( mb_strlen( $name ) < 2 ) {
			return $this->invalid( __( 'Enter your name.', 'ecodom-quote' ) );
		}
		if ( '' === $phone ) {
			return $this->invalid( __( 'Enter a phone number in the format +380 XX XXX XX XX.', 'ecodom-quote' ) );
		}
		if ( ! isset( Lead_Service::channels()[ $channel ] ) ) {
			$channel = 'call';
		}

		$result = $this->run_calc( $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Hidden fields first, cookie as a fallback (e.g. when JS could not read it).
		$attribution = array_merge( Attribution::from_cookie(), Attribution::from_array( $lead ) );
		$page_url    = esc_url_raw( (string) ( $lead['page_url'] ?? '' ) );
		if ( $page_url && wp_parse_url( $page_url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			$page_url = '';
		}
		if ( '' === $page_url ) {
			$page_url = esc_url_raw( (string) wp_get_referer() );
		}

		$lead_id = ( new Lead_Service() )->create(
			array(
				'name'    => $name,
				'phone'   => $phone,
				'channel' => $channel,
			),
			$this->calc_input( $request ),
			$result,
			$attribution,
			$page_url
		);

		if ( is_wp_error( $lead_id ) ) {
			return $lead_id;
		}

		return rest_ensure_response(
			array(
				'ok'       => true,
				'lead_id'  => $lead_id,
				'model'    => $result['model_title'],
				'sqm'      => $result['sqm'],
				'value'    => $result['total'],
				'currency' => 'UAH',
			)
		);
	}

	/**
	 * Extracts the calculation part of a request.
	 *
	 * @return array<string, mixed>
	 */
	private function calc_input( \WP_REST_Request $request ): array {
		$slopes = $request->get_param( 'slopes' );
		$fence  = $request->get_param( 'fence' );
		return array(
			'mode'      => sanitize_key( (string) $request->get_param( 'mode' ) ),
			'model_id'  => absint( $request->get_param( 'model_id' ) ),
			'thickness' => sanitize_text_field( (string) $request->get_param( 'thickness' ) ),
			'coating'   => sanitize_key( (string) $request->get_param( 'coating' ) ),
			// Numbers are validated and cast inside Calculator.
			'slopes'    => is_array( $slopes ) ? array_slice( $slopes, 0, Calculator::MAX_SLOPES ) : array(),
			'fence'     => is_array( $fence ) ? array(
				'length' => Util::to_float( $fence['length'] ?? 0 ),
				'height' => Util::to_float( $fence['height'] ?? 0 ),
				'post'   => sanitize_key( (string) ( $fence['post'] ?? '' ) ),
			) : array(),
		);
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private function run_calc( \WP_REST_Request $request ): array|\WP_Error {
		$input = $this->calc_input( $request );
		$model = Model::get( $input['model_id'] );
		if ( ! $model ) {
			return $this->invalid( __( 'Select a model.', 'ecodom-quote' ) );
		}
		try {
			return ( new Calculator( Settings::get() ) )->calculate( $model, $input );
		} catch ( \InvalidArgumentException $e ) {
			return $this->invalid( $e->getMessage() );
		}
	}

	/**
	 * Adds formatted strings for the browser.
	 *
	 * @param array<string, mixed> $r Result.
	 * @return array<string, mixed>
	 */
	private function public_result( array $r ): array {
		foreach ( $r['positions'] as &$p ) {
			$p['qty_txt']   = $p['qty'] . "\u{00A0}" . $p['unit'] . ( null !== $p['sqm'] ? ' (' . Util::num( (float) $p['sqm'] ) . "\u{00A0}" . __( 'm²', 'ecodom-quote' ) . ')' : '' );
			$p['price_txt'] = Util::money( (float) $p['price'] );
			$p['sum_txt']   = Util::money( (float) $p['sum'] );
		}
		unset( $p );
		$r['min_txt']   = Util::money( (float) $r['min'] );
		$r['max_txt']   = Util::money( (float) $r['max'] );
		$r['total_txt'] = Util::money( (float) $r['total'] );
		return $r;
	}

	private function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'edq_invalid', $message, array( 'status' => 422 ) );
	}

	private function too_many(): \WP_Error {
		return new \WP_Error( 'edq_rate_limited', __( 'Too many requests. Please try again later or call us.', 'ecodom-quote' ), array( 'status' => 429 ) );
	}
}
