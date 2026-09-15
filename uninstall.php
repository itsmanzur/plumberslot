<?php
/**
 * Runs when the user deletes the plugin from the Plugins screen.
 *
 * Data is kept by default. A site owner has to opt in to destruction in
 * Settings → Advanced, because losing a booking history is unrecoverable.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$plumberslot_settings = get_option( 'plumberslot_settings', array() );

if ( empty( $plumberslot_settings['delete_data_on_uninstall'] ) ) {
	return;
}

require_once __DIR__ . '/src/Autoloader.php';
\PlumberSlot\Autoloader::register();

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

\PlumberSlot\Database\Schema::drop_all();

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'plumberslot' );
}

delete_option( 'plumberslot_settings' );
delete_option( 'plumberslot_db_version' );
delete_transient( 'plumberslot_show_onboarding' );

wp_cache_flush_group( 'plumberslot' );

\PlumberSlot\Support\Capabilities::remove_all();
