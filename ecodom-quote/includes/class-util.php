<?php
/**
 * Small helpers.
 *
 * @package EcodomQuote
 */

declare(strict_types=1);

namespace EcodomQuote;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helper functions.
 */
final class Util {

	/**
	 * Converts user input like "1 250,50" to float.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function to_float( mixed $value ): float {
		if ( is_int( $value ) || is_float( $value ) ) {
			return is_finite( (float) $value ) ? (float) $value : 0.0;
		}
		$value = str_replace( array( ' ', "\u{00A0}", "\u{202F}", ',' ), array( '', '', '', '.' ), trim( (string) $value ) );
		return is_numeric( $value ) ? (float) $value : 0.0;
	}

	/**
	 * Formats money in UAH, e.g. "12 345 грн".
	 *
	 * @param float $amount Amount.
	 */
	public static function money( float $amount ): string {
		/* translators: %s: formatted amount */
		return sprintf( __( '%s UAH', 'ecodom-quote' ), number_format( $amount, 0, ',', "\u{00A0}" ) );
	}

	/**
	 * Formats a number with a comma decimal separator.
	 *
	 * @param float $value    Number.
	 * @param int   $decimals Decimals.
	 */
	public static function num( float $value, int $decimals = 2 ): string {
		return number_format( $value, $decimals, ',', "\u{00A0}" );
	}

	/**
	 * Visitor IP (REMOTE_ADDR unless a trusted proxy header is enabled via filter).
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the client IP, e.g. to trust CF-Connecting-IP behind Cloudflare.
		 *
		 * @param string $ip Detected IP.
		 */
		$ip = (string) apply_filters( 'edq_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Normalizes a Ukrainian phone to +380XXXXXXXXX or returns '' if invalid.
	 *
	 * @param string $phone Raw phone.
	 */
	public static function normalize_phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone ) ?? '';
		if ( 10 === strlen( $digits ) && str_starts_with( $digits, '0' ) ) {
			$digits = '38' . $digits;
		}
		if ( 12 !== strlen( $digits ) || ! str_starts_with( $digits, '380' ) ) {
			return '';
		}
		return '+' . $digits;
	}
}
