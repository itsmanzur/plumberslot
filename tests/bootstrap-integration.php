<?php
/**
 * Bootstrap for tests that run against WordPress and a real MySQL database.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

if ( ! extension_loaded( 'mysqli' ) || ! function_exists( 'mysqli_connect' ) ) {
	fwrite( STDERR, "Integration tests need the mysqli PHP extension.\n" );
	exit( 1 );
}

$plumberslot_tests_dir = getenv( 'WP_TESTS_DIR' );
$plumberslot_db_name   = getenv( 'PLUMBERSLOT_TEST_DB_NAME' );
$plumberslot_prefix    = getenv( 'PLUMBERSLOT_TEST_TABLE_PREFIX' );
$plumberslot_allow     = getenv( 'PLUMBERSLOT_TEST_ALLOW_DESTRUCTIVE' );

if ( '1' !== $plumberslot_allow
	|| ! is_string( $plumberslot_db_name )
	|| 1 !== preg_match( '/^plumberslot_test(?:_[a-z0-9_]+)?$/', $plumberslot_db_name )
	|| ! is_string( $plumberslot_prefix )
	|| 1 !== preg_match( '/^ts_test_[a-z0-9_]*$/', $plumberslot_prefix ) ) {
	fwrite(
		STDERR,
		"Integration tests are destructive. Use a plumberslot_test* database, a ts_test_* prefix, and explicitly set PLUMBERSLOT_TEST_ALLOW_DESTRUCTIVE=1.\n"
	);
	exit( 1 );
}

if ( ! is_string( $plumberslot_tests_dir ) || '' === $plumberslot_tests_dir ) {
	$plumberslot_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! is_readable( $plumberslot_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found. Set WP_TESTS_DIR before running integration tests.\n" );
	exit( 1 );
}

$plumberslot_config = __DIR__ . '/wp-tests-config.php';

if ( ! is_readable( $plumberslot_config ) ) {
	fwrite( STDERR, "Copy tests/wp-tests-config.php.dist to tests/wp-tests-config.php before running integration tests.\n" );
	exit( 1 );
}

define( 'WP_TESTS_CONFIG_FILE_PATH', $plumberslot_config );
define( 'PLUMBERSLOT_ENCRYPTION_KEY', 'plumberslot-integration-test-key-not-for-production' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $plumberslot_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/plumberslot.php';
	}
);

require $plumberslot_tests_dir . '/includes/bootstrap.php';
