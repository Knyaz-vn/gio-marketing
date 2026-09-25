<?php
/**
 * Telegram Bot API client.
 *
 * @package EcodomQuote
 */

declare(strict_types=1);

namespace EcodomQuote;

defined( 'ABSPATH' ) || exit;

/**
 * Sends lead notifications to a Telegram chat.
 */
final class Telegram {

	private const API = 'https://api.telegram.org/bot';

	/**
	 * @param array<string, mixed> $data Lead data.
	 * @param string|null          $pdf  PDF path.
	 * @return string Status for the log.
	 */
	public function send_lead( array $data, ?string $pdf ): string {
		$token = trim( (string) Settings::get( 'tg_token' ) );
		$chat  = trim( (string) Settings::get( 'tg_chat_id' ) );
		if ( '' === $token || '' === $chat ) {
			return 'skipped';
		}
		if ( ! preg_match( '/^\d+:[A-Za-z0-9_-]+$/', $token ) ) {
			return 'error: invalid token format';
		}

		$status = $this->request(
			$token,
			'sendMessage',
			array(
				'chat_id'                  => $chat,
				'text'                     => $this->message( $data ),
				'parse_mode'               => 'HTML',
				'disable_web_page_preview' => 'true',
			)
		);

		if ( $pdf && is_readable( $pdf ) ) {
			$status .= '; pdf ' . $this->request(
				$token,
				'sendDocument',
				array(
					'chat_id' => $chat,
					/* translators: %d: lead id */
					'caption' => sprintf( __( 'Estimate for lead #%d', 'ecodom-quote' ), (int) $data['id'] ),
				),
				$pdf,
				sprintf( 'estimate-%d.pdf', (int) $data['id'] )
			);
		}
		return $status;
	}

	/**
	 * Builds the HTML message (Telegram subset).
	 *
	 * @param array<string, mixed> $data Lead data.
	 */
	public function message( array $data ): string {
		$r   = $data['result'];
		$c   = $data['contact'];
		$e   = static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$out = array();

		/* translators: %d: lead id */
		$out[] = '<b>' . $e( sprintf( __( 'New lead #%d', 'ecodom-quote' ), (int) $data['id'] ) ) . '</b>';
		$out[] = '👤 ' . $e( $c['name'] );
		$out[] = '📞 ' . $e( $c['phone'] ) . ' · ' . $e( Lead_Service::channels()[ $c['channel'] ] ?? $c['channel'] );
		$out[] = '';
		$out[] = '🏠 ' . $e( 'fence' === $r['mode'] ? __( 'Fence', 'ecodom-quote' ) : __( 'Roof', 'ecodom-quote' ) ) . ': ' . $e( $r['model_title'] ) . ', ' . $e( $r['thickness'] ) . ' ' . $e( __( 'mm', 'ecodom-quote' ) ) . ', ' . $e( $r['coating_txt'] );
		/* translators: %s: area */
		$out[] = '📐 ' . $e( sprintf( __( 'Sheet area: %s m²', 'ecodom-quote' ), Util::num( (float) $r['sqm'] ) ) );
		foreach ( $r['positions'] as $p ) {
			$out[] = '• ' . $e( $p['name'] ) . ' — ' . $e( $p['qty'] . ' ' . $p['unit'] ) . ' = ' . $e( Util::money( (float) $p['sum'] ) );
		}
		/* translators: 1: min price, 2: max price */
		$out[] = '💰 <b>' . $e( sprintf( __( '%1$s – %2$s', 'ecodom-quote' ), Util::money( (float) $r['min'] ), Util::money( (float) $r['max'] ) ) ) . '</b>';

		if ( ! empty( $data['attribution'] ) ) {
			$out[] = '';
			foreach ( $data['attribution'] as $k => $v ) {
				$out[] = $e( $k ) . ': <code>' . $e( $v ) . '</code>';
			}
		}
		if ( ! empty( $data['page_url'] ) ) {
			$out[] = '🔗 ' . $e( $data['page_url'] );
		}
		$out[] = '🗂 ' . $e( admin_url( 'post.php?post=' . (int) $data['id'] . '&action=edit' ) );

		// Telegram limit is 4096 characters.
		return mb_substr( implode( "\n", $out ), 0, 4000 );
	}

	/**
	 * Calls a Bot API method; attaches a file as multipart/form-data when given.
	 *
	 * @param array<string, string> $fields   Fields.
	 * @param string|null           $file     File to upload as "document".
	 * @param string|null           $filename File name shown in Telegram.
	 */
	public function request( string $token, string $method, array $fields, ?string $file = null, ?string $filename = null ): string {
		$args = array( 'timeout' => 10 );
		if ( $file ) {
			$boundary = 'edq' . wp_generate_password( 24, false );
			$body     = '';
			foreach ( $fields as $name => $value ) {
				$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
			}
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"document\"; filename=\"" . ( $filename ?? basename( $file ) ) . "\"\r\nContent-Type: application/pdf\r\n\r\n";
			$body .= (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$body .= "\r\n--{$boundary}--\r\n";

			$args['headers'] = array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary );
			$args['body']    = $body;
		} else {
			$args['body'] = $fields;
		}

		$response = wp_remote_post( self::API . $token . '/' . $method, $args );
		if ( is_wp_error( $response ) ) {
			return 'error: ' . $response->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			return 'http ' . $code . ( isset( $json['description'] ) ? ' ' . sanitize_text_field( (string) $json['description'] ) : '' );
		}
		return 'ok';
	}
}
