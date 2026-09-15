<?php
/**
 * Secret storage and signed URLs.
 *
 * Third-party API keys are encrypted at rest with a key that lives in
 * wp-config.php, so a database dump on its own does not hand an attacker the
 * site's Zoom, Twilio and payment credentials.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Support;

defined( 'ABSPATH' ) || exit;

final class Crypto {

	/**
	 * @throws \RuntimeException When no key is configured.
	 */
	private static function key(): string {
		$key = defined( 'PLUMBERSLOT_ENCRYPTION_KEY' ) ? (string) constant( 'PLUMBERSLOT_ENCRYPTION_KEY' ) : '';

		if ( '' === $key && defined( 'AUTH_KEY' ) ) {
			$key = (string) constant( 'AUTH_KEY' );
		}

		if ( '' === $key ) {
			throw new \RuntimeException( 'PlumberSlot: no encryption key available.' );
		}

		return sodium_crypto_generichash( $key, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	public static function encrypt( string $plaintext ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return sodium_bin2base64(
			$nonce . sodium_crypto_secretbox( $plaintext, $nonce, self::key() ),
			SODIUM_BASE64_VARIANT_ORIGINAL
		);
	}

	public static function decrypt( string $ciphertext ): ?string {
		try {
			$raw = sodium_base642bin( $ciphertext, SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( \SodiumException ) {
			return null;
		}

		if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );

		return false === $plain ? null : $plain;
	}

	/**
	 * A short-lived signed URL for a meeting link.
	 *
	 * The join link never travels in an email body. A forwarded confirmation
	 * cannot be used to walk into a live appointment at someone else's address.
	 */
	public static function signed_join_url( int $booking_id, string $token, int $ttl = HOUR_IN_SECONDS * 3 ): string {
		$expires   = time() + $ttl;
		$signature = hash_hmac( 'sha256', $booking_id . '|' . $token . '|' . $expires, self::key() );

		return add_query_arg(
			array(
				'ts_booking' => $booking_id,
				'ts_expires' => $expires,
				'ts_sig'     => $signature,
			),
			home_url( '/plumberslot/join' )
		);
	}

	public static function verify_join( int $booking_id, string $token, int $expires, string $signature ): bool {
		if ( $expires < time() ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $booking_id . '|' . $token . '|' . $expires, self::key() );

		return hash_equals( $expected, $signature );
	}
}
