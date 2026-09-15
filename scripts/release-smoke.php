<?php
/**
 * Bootstrap a disposable WordPress install for the release ZIP smoke test.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This script is CLI-only.\n" );
	exit( 1 );
}

$action = $argv[1] ?? '';
$root   = getenv( 'TUTORSLOT_SMOKE_WP_ROOT' );

if ( ! is_string( $root ) || '' === $root || ! is_readable( $root . '/wp-settings.php' ) ) {
	fwrite( STDERR, "TUTORSLOT_SMOKE_WP_ROOT is not a WordPress root.\n" );
	exit( 1 );
}

define( 'TUTORSLOT_SMOKE_ACTION', $action );

function smoke_env( string $name, string $fallback = '' ): string {
	$value = getenv( $name );

	return is_string( $value ) && '' !== $value ? $value : $fallback;
}

define( 'ABSPATH', rtrim( str_replace( '\\', '/', $root ), '/' ) . '/' );
define( 'DB_NAME', smoke_env( 'TUTORSLOT_SMOKE_DB_NAME' ) );
define( 'DB_USER', smoke_env( 'TUTORSLOT_SMOKE_DB_USER', 'root' ) );
define( 'DB_PASSWORD', smoke_env( 'TUTORSLOT_SMOKE_DB_PASSWORD', 'root' ) );
define( 'DB_HOST', smoke_env( 'TUTORSLOT_SMOKE_DB_HOST', '127.0.0.1' ) . ':' . smoke_env( 'TUTORSLOT_SMOKE_DB_PORT' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_HOME', 'http://tutorslot-smoke.invalid' );
define( 'WP_SITEURL', 'http://tutorslot-smoke.invalid' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'WP_CONTENT_URL', WP_SITEURL . '/wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'WP_PLUGIN_URL', WP_CONTENT_URL . '/plugins' );
define( 'FS_METHOD', 'direct' );
define( 'WP_DEBUG', false );
define( 'WP_CACHE', false );
define( 'AUTH_KEY', 'tutorslot-disposable-auth-key' );
define( 'SECURE_AUTH_KEY', 'tutorslot-disposable-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'tutorslot-disposable-logged-in-key' );
define( 'NONCE_KEY', 'tutorslot-disposable-nonce-key' );
define( 'AUTH_SALT', 'tutorslot-disposable-auth-salt' );
define( 'SECURE_AUTH_SALT', 'tutorslot-disposable-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'tutorslot-disposable-logged-in-salt' );
define( 'NONCE_SALT', 'tutorslot-disposable-nonce-salt' );

$table_prefix = 'wp_';

if ( 'install' === TUTORSLOT_SMOKE_ACTION ) {
	define( 'WP_INSTALLING', true );
}

require ABSPATH . 'wp-settings.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( 'install' === TUTORSLOT_SMOKE_ACTION ) {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$result = wp_install(
		'TutorSlot release smoke',
		'tutorslot-smoke@example.invalid',
		true,
		'',
		'smoke_admin',
		'TutorSlot-Smoke-Only-2026!'
	);

	$activation = activate_plugin( 'tutorslot/tutorslot.php' );
	if ( is_wp_error( $activation ) ) {
		fwrite( STDERR, $activation->get_error_message() . "\n" );
		exit( 1 );
	}

	echo wp_json_encode(
		array(
			'installed' => true,
			'user_id'   => (int) $result['user_id'],
			'active'    => is_plugin_active( 'tutorslot/tutorslot.php' ),
		)
	) . PHP_EOL;
	exit( 0 );
}

if ( 'verify' === TUTORSLOT_SMOKE_ACTION ) {
	global $wpdb, $wp_version;

	if ( ! did_action( 'rest_api_init' ) ) {
		do_action( 'rest_api_init' );
	}

	$routes        = rest_get_server()->get_routes();
	$tutorslot_api = array_filter(
		array_keys( $routes ),
		static fn ( string $route ): bool => str_starts_with( $route, '/tutorslot/v1/' )
	);
	$table_pattern = $wpdb->esc_like( $wpdb->prefix . 'tutorslot_' ) . '%';
	$table_count   = (int) $wpdb->get_var(
		$wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s', DB_NAME, $table_pattern )
	);
	$plugin_root   = WP_PLUGIN_DIR . '/tutorslot';
	$checks        = array(
		'active'             => is_plugin_active( 'tutorslot/tutorslot.php' ),
		'plugin_booted'      => class_exists( 'TutorSlot\\Plugin' ),
		'action_scheduler'   => function_exists( 'as_schedule_single_action' ),
		'db_version'         => 6 === (int) get_option( 'tutorslot_db_version', 0 ),
		'tables'             => 13 === $table_count,
		'rest_routes'        => 0 < count( $tutorslot_api ),
		'admin_asset'        => is_readable( $plugin_root . '/assets/dist/admin.js' ),
		'widget_asset'       => is_readable( $plugin_root . '/assets/dist/widget.js' ),
		'pot'                => is_readable( $plugin_root . '/languages/tutorslot.pot' ),
		'security_policy'    => is_readable( $plugin_root . '/SECURITY.md' ),
		'support_guide'      => is_readable( $plugin_root . '/SUPPORT.md' ),
		'third_party_notice' => is_readable( $plugin_root . '/THIRD-PARTY-LICENSES.txt' ),
	);
	$failed        = array_keys( array_filter( $checks, static fn ( bool $passed ): bool => ! $passed ) );

	echo wp_json_encode(
		array(
			'wordpress_version' => $wp_version,
			'table_count'       => $table_count,
			'rest_route_count'  => count( $tutorslot_api ),
			'checks'            => $checks,
			'failed'            => $failed,
		)
	) . PHP_EOL;
	exit( empty( $failed ) ? 0 : 1 );
}

if ( 'deactivate' === TUTORSLOT_SMOKE_ACTION ) {
	deactivate_plugins( 'tutorslot/tutorslot.php', false, false );
	$inactive = ! is_plugin_active( 'tutorslot/tutorslot.php' );

	echo wp_json_encode( array( 'deactivated' => $inactive ) ) . PHP_EOL;
	exit( $inactive ? 0 : 1 );
}

fwrite( STDERR, "Expected install, verify or deactivate.\n" );
exit( 1 );
