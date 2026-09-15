<?php
/**
 * Bootstrap for tests that run against WordPress and a real MySQL database.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

if ( ! extension_loaded( 'mysqli' ) || ! function_exists( 'mysqli_connect' ) ) {
	fwrite( STDERR, "Integration tests need the mysqli PHP extension.\n" );
	exit( 1 );
}

$tutorslot_tests_dir = getenv( 'WP_TESTS_DIR' );
$tutorslot_db_name   = getenv( 'TUTORSLOT_TEST_DB_NAME' );
$tutorslot_prefix    = getenv( 'TUTORSLOT_TEST_TABLE_PREFIX' );
$tutorslot_allow     = getenv( 'TUTORSLOT_TEST_ALLOW_DESTRUCTIVE' );

if ( '1' !== $tutorslot_allow
	|| ! is_string( $tutorslot_db_name )
	|| 1 !== preg_match( '/^tutorslot_test(?:_[a-z0-9_]+)?$/', $tutorslot_db_name )
	|| ! is_string( $tutorslot_prefix )
	|| 1 !== preg_match( '/^ts_test_[a-z0-9_]*$/', $tutorslot_prefix ) ) {
	fwrite(
		STDERR,
		"Integration tests are destructive. Use a tutorslot_test* database, a ts_test_* prefix, and explicitly set TUTORSLOT_TEST_ALLOW_DESTRUCTIVE=1.\n"
	);
	exit( 1 );
}

if ( ! is_string( $tutorslot_tests_dir ) || '' === $tutorslot_tests_dir ) {
	$tutorslot_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! is_readable( $tutorslot_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found. Set WP_TESTS_DIR before running integration tests.\n" );
	exit( 1 );
}

$tutorslot_config = __DIR__ . '/wp-tests-config.php';

if ( ! is_readable( $tutorslot_config ) ) {
	fwrite( STDERR, "Copy tests/wp-tests-config.php.dist to tests/wp-tests-config.php before running integration tests.\n" );
	exit( 1 );
}

define( 'WP_TESTS_CONFIG_FILE_PATH', $tutorslot_config );
define( 'TUTORSLOT_ENCRYPTION_KEY', 'tutorslot-integration-test-key-not-for-production' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $tutorslot_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/tutorslot.php';
	}
);

require $tutorslot_tests_dir . '/includes/bootstrap.php';
