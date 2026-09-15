<?php
/**
 * PHPUnit bootstrap for domain-level tests that do not load WordPress.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
}

$GLOBALS['tutorslot_test_cache']      = array();
$GLOBALS['tutorslot_test_transients'] = array();
$GLOBALS['tutorslot_test_settings']   = array(
	'delete_data_on_uninstall' => false,
);
$GLOBALS['tutorslot_lifecycle_calls'] = array();

function as_unschedule_all_actions( string $hook, array $args, string $group ): void {
	$GLOBALS['tutorslot_lifecycle_calls']['unschedule'] = array( $hook, $args, $group );
}

function as_has_scheduled_action( string $hook, array $args, string $group ): bool {
	foreach ( $GLOBALS['tutorslot_lifecycle_calls']['scheduled'] ?? array() as $action ) {
		if ( $action['hook'] === $hook && $action['args'] === $args && $action['group'] === $group ) {
			return true;
		}
	}

	return false;
}

function as_schedule_single_action( int $timestamp, string $hook, array $args, string $group ): int {
	$GLOBALS['tutorslot_lifecycle_calls']['scheduled'][] = compact( 'timestamp', 'hook', 'args', 'group' );

	return count( $GLOBALS['tutorslot_lifecycle_calls']['scheduled'] );
}

function wp_cache_flush_group( string $group ): void {
	$GLOBALS['tutorslot_lifecycle_calls']['cache'] = $group;
}

function flush_rewrite_rules(): void {
	$GLOBALS['tutorslot_lifecycle_calls']['rewrite'] = true;
}

function get_option( string $name, mixed $fallback = false ): mixed {
	if ( 'tutorslot_settings' === $name ) {
		return $GLOBALS['tutorslot_test_settings'];
	}

	return $fallback;
}

function wp_cache_get( string $key, string $group = '' ): mixed {
	return $GLOBALS['tutorslot_test_cache'][ $group ][ $key ] ?? false;
}

function wp_cache_set( string $key, mixed $value, string $group = '', int $ttl = 0 ): bool {
	$GLOBALS['tutorslot_test_cache'][ $group ][ $key ] = $value;

	return true;
}

function get_transient( string $name ): mixed {
	return $GLOBALS['tutorslot_test_transients'][ $name ] ?? false;
}

function set_transient( string $name, mixed $value, int $ttl = 0 ): bool {
	$GLOBALS['tutorslot_test_transients'][ $name ] = $value;

	return true;
}

function delete_transient( string $name ): bool {
	$exists = array_key_exists( $name, $GLOBALS['tutorslot_test_transients'] );
	unset( $GLOBALS['tutorslot_test_transients'][ $name ] );

	return $exists;
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	return $value;
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @var array<string, list<string>> */
		private array $errors = array();

		/** @var array<string, mixed> */
		private array $error_data = array();

		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;
				if ( '' !== $data ) {
					$this->error_data[ $code ] = $data;
				}
			}
		}

		public function get_error_code(): string {
			$codes = array_keys( $this->errors );

			return (string) ( $codes[0] ?? '' );
		}

		public function get_error_message( string $code = '' ): string {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}

			return $this->errors[ $code ][0] ?? '';
		}

		public function get_error_data( string $code = '' ): mixed {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}

			return $this->error_data[ $code ] ?? null;
		}
	}
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

$tutorslot_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! is_readable( $tutorslot_autoload ) ) {
	fwrite( STDERR, "TutorSlot unit tests need Composer dependencies. Run composer install.\n" );
	exit( 1 );
}

require_once $tutorslot_autoload;
