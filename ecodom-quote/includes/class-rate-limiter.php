<?php
/**
 * Sliding-window rate limiter on transients (works with or without an object cache).
 *
 * @package EcodomQuote
 */

declare(strict_types=1);

namespace EcodomQuote;

defined( 'ABSPATH' ) || exit;

/**
 * Per-IP request limiting.
 */
final class Rate_Limiter {

	/**
	 * Registers a hit and returns false when the limit is exceeded.
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $limit  Max hits per window.
	 * @param int    $window Window in seconds.
	 */
	public static function hit( string $bucket, int $limit, int $window ): bool {
		$key  = 'edq_rl_' . $bucket . '_' . substr( hash_hmac( 'sha256', Util::client_ip(), wp_salt( 'nonce' ) ), 0, 20 );
		$now  = time();
		$hits = get_transient( $key );
		$hits = is_array( $hits ) ? array_values( array_filter( $hits, static fn( $t ): bool => (int) $t > $now - $window ) ) : array();

		if ( count( $hits ) >= $limit ) {
			return false;
		}
		$hits[] = $now;
		set_transient( $key, $hits, $window );
		return true;
	}
}
