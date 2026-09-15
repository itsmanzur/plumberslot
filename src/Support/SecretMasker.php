<?php
/**
 * Central redaction policy for API responses, errors and audit metadata.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Support;

defined( 'ABSPATH' ) || exit;

final class SecretMasker {

	public const MASK = '********';

	public static function register(): void {
		add_filter( 'rest_post_dispatch', array( self::class, 'filter_response' ), 10, 3 );
	}

	/**
	 * Final namespace-wide guard, including WP_Error responses and callbacks
	 * that do not extend TutorSlot's REST controller base.
	 *
	 * @param mixed            $response REST response.
	 * @param mixed            $server   REST server.
	 * @param \WP_REST_Request $request  REST request.
	 */
	public static function filter_response( mixed $response, mixed $server, \WP_REST_Request $request ): mixed {
		$route = $request->get_route();
		if ( ! str_starts_with( $route, '/tutorslot/v1/' ) || ! method_exists( $response, 'get_data' ) || ! method_exists( $response, 'set_data' ) ) {
			return $response;
		}

		$response->set_data( self::redact( $response->get_data() ) );

		return $response;
	}

	/**
	 * Encrypted settings that are write-only over the API.
	 *
	 * @return list<string>
	 */
	public static function encrypted_setting_keys(): array {
		return array(
			'stripe_secret_key',
			'stripe_webhook_secret',
			'bkash_app_secret',
			'bkash_password',
			'zoom_client_secret',
			'google_client_secret',
			'sms_api_key',
		);
	}

	/**
	 * Settings that may be stored in clear text but remain write-only in REST.
	 *
	 * @return list<string>
	 */
	public static function response_setting_keys(): array {
		return array_merge(
			self::encrypted_setting_keys(),
			array( 'bkash_app_key', 'bkash_username' )
		);
	}

	public static function is_masked_setting( string $key, mixed $value ): bool {
		return self::MASK === $value && in_array( $key, self::response_setting_keys(), true );
	}

	/**
	 * Recursively redact secret-shaped fields and configured secret values.
	 */
	public static function redact( mixed $value, string $key = '' ): mixed {
		if ( '' !== $key && self::is_secret_key( $key ) ) {
			return self::empty_value( $value ) ? $value : self::MASK;
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $item_key => $item ) {
				$out[ $item_key ] = self::redact( $item, is_string( $item_key ) ? $item_key : '' );
			}

			return $out;
		}

		if ( $value instanceof \JsonSerializable ) {
			return self::redact( $value->jsonSerialize(), $key );
		}

		if ( is_object( $value ) ) {
			$copy = new \stdClass();
			foreach ( get_object_vars( $value ) as $item_key => $item ) {
				$copy->{$item_key} = self::redact( $item, $item_key );
			}

			return $copy;
		}

		return is_string( $value ) ? self::redact_known_values( $value ) : $value;
	}

	/**
	 * Mask configured credentials while preserving non-secret settings.
	 *
	 * @param array<string, mixed> $settings Settings payload.
	 * @return array<string, mixed>
	 */
	public static function settings( array $settings ): array {
		foreach ( self::response_setting_keys() as $key ) {
			if ( isset( $settings[ $key ] ) && ! self::empty_value( $settings[ $key ] ) ) {
				$settings[ $key ] = self::MASK;
			}
		}

		return $settings;
	}

	private static function is_secret_key( string $key ): bool {
		$key = strtolower( str_replace( array( '-', ' ' ), '_', $key ) );

		if ( in_array( $key, self::response_setting_keys(), true ) ) {
			return true;
		}

		return 1 === preg_match( '/(?:^|_)(?:access_token|refresh_token|id_token|client_secret|app_secret|webhook_secret|api_key|password|authorization|credentials?)$/', $key );
	}

	private static function empty_value( mixed $value ): bool {
		return null === $value || '' === $value || array() === $value;
	}

	private static function redact_known_values( string $value ): string {
		foreach ( self::response_setting_keys() as $key ) {
			$stored = Settings::string( $key );
			// Avoid corrupting ordinary response text when a credential-like
			// setting contains an unusually short username or test value.
			if ( strlen( $stored ) < 6 ) {
				continue;
			}

			$value = str_replace( $stored, self::MASK, $value );

			if ( in_array( $key, self::encrypted_setting_keys(), true ) ) {
				try {
					$plain = Crypto::decrypt( $stored );
				} catch ( \Throwable ) {
					$plain = null;
				}

				if ( is_string( $plain ) && '' !== $plain ) {
					$value = str_replace( $plain, self::MASK, $value );
				}
			}
		}

		return $value;
	}
}
