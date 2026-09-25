<?php
/**
 * Lead persistence and delivery (PDF, Telegram, webhook).
 *
 * @package EcodomQuote
 */

declare(strict_types=1);

namespace EcodomQuote;

defined( 'ABSPATH' ) || exit;

/**
 * Creates ecodom_lead posts and dispatches notifications.
 */
final class Lead_Service {

	public const META = '_edq_lead';

	/**
	 * Contact channels.
	 *
	 * @return array<string, string>
	 */
	public static function channels(): array {
		return array(
			'call'     => __( 'Phone call', 'ecodom-quote' ),
			'viber'    => 'Viber',
			'telegram' => 'Telegram',
			'whatsapp' => 'WhatsApp',
		);
	}

	/**
	 * Saves the lead, generates the PDF and sends notifications.
	 *
	 * @param array{name:string,phone:string,channel:string} $contact     Contact.
	 * @param array<string, mixed>                           $input       Calculator input.
	 * @param array<string, mixed>                           $result      Calculator result.
	 * @param array<string, string>                          $attribution UTM & click ids.
	 * @param string                                         $page_url    Page URL.
	 */
	public function create( array $contact, array $input, array $result, array $attribution, string $page_url ): int|\WP_Error {
		$lead_id = wp_insert_post(
			array(
				'post_type'   => Post_Types::LEAD,
				'post_status' => 'publish',
				'post_title'  => sprintf( '%s, %s', $contact['name'], $contact['phone'] ),
			),
			true
		);
		if ( is_wp_error( $lead_id ) ) {
			return new \WP_Error( 'edq_save', __( 'Could not save the request. Please call us.', 'ecodom-quote' ), array( 'status' => 500 ) );
		}

		$data = array(
			'id'          => $lead_id,
			'created_at'  => current_time( 'mysql' ),
			'created_gmt' => gmdate( 'c' ),
			'contact'     => $contact,
			'input'       => $input,
			'result'      => $result,
			'attribution' => $attribution,
			'page_url'    => $page_url,
			'ip_hash'     => substr( hash_hmac( 'sha256', Util::client_ip(), wp_salt( 'auth' ) ), 0, 16 ),
			'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '',
		);

		// Title gets the numeric id for quick search in admin.
		wp_update_post(
			array(
				'ID'         => $lead_id,
				'post_title' => sprintf( '#%d %s, %s', $lead_id, $contact['name'], $contact['phone'] ),
			)
		);
		update_post_meta( $lead_id, self::META, wp_slash( (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) ) );
		update_post_meta( $lead_id, '_edq_phone', $contact['phone'] );
		update_post_meta( $lead_id, '_edq_total', (float) $result['total'] );
		update_post_meta( $lead_id, '_edq_utm_source', $attribution['utm_source'] ?? '' );

		$pdf = null;
		if ( Pdf::available() ) {
			try {
				$pdf = ( new Pdf() )->generate( $data );
				update_post_meta( $lead_id, '_edq_pdf', basename( $pdf ) );
			} catch ( \Throwable $e ) {
				$this->log( $lead_id, 'pdf', 'error: ' . $e->getMessage() );
			}
		}

		$this->log( $lead_id, 'telegram', ( new Telegram() )->send_lead( $data, $pdf ) );
		$this->log( $lead_id, 'webhook', $this->send_webhook( $data ) );

		/**
		 * Fires after a lead is stored and notifications are sent.
		 *
		 * @param int                  $lead_id Lead post ID.
		 * @param array<string, mixed> $data    Lead data.
		 * @param string|null          $pdf     PDF path.
		 */
		do_action( 'edq_lead_created', $lead_id, $data, $pdf );

		return $lead_id;
	}

	/**
	 * Returns stored lead data.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( int $lead_id ): ?array {
		$raw  = get_post_meta( $lead_id, self::META, true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $data ) ? $data : null;
	}

	/**
	 * POSTs the lead as JSON to the configured webhook.
	 *
	 * @param array<string, mixed> $data Lead data.
	 */
	public function send_webhook( array $data ): string {
		$url = (string) Settings::get( 'webhook_url' );
		if ( '' === $url ) {
			return 'skipped';
		}
		$payload = array(
			'event'       => 'lead.created',
			'lead_id'     => $data['id'],
			'created_at'  => $data['created_gmt'],
			'site'        => home_url( '/' ),
			'contact'     => $data['contact'],
			'calc'        => array(
				'mode'        => $data['result']['mode'],
				'model_id'    => $data['result']['model_id'],
				'model'       => $data['result']['model_title'],
				'thickness'   => $data['result']['thickness'],
				'coating'     => $data['result']['coating'],
				'sqm'         => $data['result']['sqm'],
				'total'       => $data['result']['total'],
				'price_min'   => $data['result']['min'],
				'price_max'   => $data['result']['max'],
				'currency'    => 'UAH',
				'positions'   => $data['result']['positions'],
				'input'       => $data['input'],
			),
			'attribution' => (object) $data['attribution'],
			'page_url'    => $data['page_url'],
		);
		/**
		 * Filters the webhook payload.
		 *
		 * @param array<string, mixed> $payload Payload.
		 * @param array<string, mixed> $data    Lead data.
		 */
		$payload = apply_filters( 'edq_webhook_payload', $payload, $data );
		$body    = (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$headers = array(
			'Content-Type' => 'application/json; charset=utf-8',
			'User-Agent'   => 'EcoDom-Quote/' . EDQ_VERSION,
		);
		$secret = (string) Settings::get( 'webhook_secret' );
		if ( '' !== $secret ) {
			$headers['X-EDQ-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		}

		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout' => 8,
				'headers' => $headers,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return 'error: ' . $response->get_error_message();
		}
		return 'http ' . wp_remote_retrieve_response_code( $response );
	}

	private function log( int $lead_id, string $channel, string $status ): void {
		update_post_meta( $lead_id, '_edq_status_' . $channel, mb_substr( $status, 0, 300 ) );
	}
}
