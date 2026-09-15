<?php
/**
 * Read-only probe for a Local WordPress installation.
 *
 * Run with:
 * php tests/Runtime/wordpress-probe.php
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

$wordpress_root = dirname( __DIR__, 5 );
$wp_load        = $wordpress_root . '/wp-load.php';

if ( ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "WordPress bootstrap not found.\n" );
	exit( 1 );
}

require $wp_load;

if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

global $wpdb;

$checks = array(
	'home'                  => home_url( '/' ),
	'wordpress'             => get_bloginfo( 'version' ),
	'database'              => $wpdb->check_connection( false ) ? 'ok' : 'failed',
	'plugin_active'         => is_plugin_active( 'tutorslot/tutorslot.php' ) ? 'yes' : 'no',
	'plugin_version'        => defined( 'TutorSlot\VERSION' ) ? \TutorSlot\VERSION : 'not-loaded',
	'admin_assets_present'  => \TutorSlot\Admin\AdminMenu::assets_available() ? 'yes' : 'no',
	'widget_assets_present' => \TutorSlot\Frontend\AssetManager::available() ? 'yes' : 'no',
);

foreach ( $checks as $label => $value ) {
	printf( "%s=%s\n", strtoupper( $label ), (string) $value );
}

exit( in_array( 'failed', $checks, true ) ? 1 : 0 );
