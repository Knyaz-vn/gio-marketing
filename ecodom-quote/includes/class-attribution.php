<?php
/**
 * Stores ad click identifiers and UTM tags in a first-party cookie for 90 days.
 *
 * @package EcodomQuote
 */

declare(strict_types=1);

namespace EcodomQuote;

defined( 'ABSPATH' ) || exit;

/**
 * UTM / gclid / fbclid persistence.
 */
final class Attribution {

	public const COOKIE = 'edq_attr';
	public const DAYS   = 90;
	public const KEYS   = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid' );

	public function register_hooks(): void {
		// Runs on every front-end page so tags are kept even if the visitor lands on a page without the calculator.
		add_action( 'template_redirect', array( $this, 'capture' ) );
	}

	public function capture(): void {
		if ( is_admin() || wp_doing_ajax() || headers_sent() ) {
			return;
		}
		$found = self::from_array( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $found ) {
			return;
		}
		// Last non-empty click wins: new tags replace the stored set.
		$value = (string) wp_json_encode( $found );
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => time() + self::DAYS * DAY_IN_SECONDS,
				'path'     => COOKIEPATH ?: '/',
				'domain'   => COOKIE_DOMAIN ?: '',
				'secure'   => is_ssl(),
				'httponly' => false, // The calculator script reads it to fill hidden fields.
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE ] = wp_slash( $value );
	}

	/**
	 * Picks and sanitizes attribution keys from an array.
	 *
	 * @param array<string, mixed> $source Source array (unslashed or slashed).
	 * @return array<string, string>
	 */
	public static function from_array( array $source ): array {
		$out = array();
		foreach ( self::KEYS as $key ) {
			if ( isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ) {
				$val = substr( sanitize_text_field( wp_unslash( (string) $source[ $key ] ) ), 0, 255 );
				if ( '' !== $val ) {
					$out[ $key ] = $val;
				}
			}
		}
		return $out;
	}

	/**
	 * Stored attribution from the cookie.
	 *
	 * @return array<string, string>
	 */
	public static function from_cookie(): array {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return array();
		}
		$data = json_decode( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in from_array().
		return is_array( $data ) ? self::from_array( $data ) : array();
	}
}
