<?php
/**
 * Rate limiting for public endpoints.
 *
 * The slot and booking endpoints answer to unauthenticated visitors, which
 * makes them both a scraping target and a cheap way to hold every slot a tutor
 * owns. A fixed window per identity is enough to stop both.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Support;

defined( 'ABSPATH' ) || exit;

final class RateLimiter {

	/**
	 * @param string $bucket   Logical action, for example 'create_booking'.
	 * @param int    $limit    Allowed hits per window.
	 * @param int    $window   Window length in seconds.
	 */
	public static function allow( string $bucket, int $limit = 10, int $window = MINUTE_IN_SECONDS ): bool {
		$key  = 'tutorslot_rl_' . md5( $bucket . '|' . self::identity() );
		$hits = (int) get_transient( $key );

		if ( $hits >= $limit ) {
			return false;
		}

		set_transient( $key, $hits + 1, $window );

		return true;
	}

	/**
	 * Logged-in user id, or a hashed IP. The raw address is never stored.
	 */
	private static function identity(): string {
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			return 'u' . $user_id;
		}

		return 'i' . self::ip_hash();
	}

	public static function ip_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		return hash( 'sha256', $ip . wp_salt( 'auth' ) );
	}
}
