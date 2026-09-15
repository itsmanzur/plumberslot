<?php
/**
 * Runs when the user deletes the plugin from the Plugins screen.
 *
 * Data is kept by default. A site owner has to opt in to destruction in
 * Settings → Advanced, because losing a booking history is unrecoverable.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$tutorslot_settings = get_option( 'tutorslot_settings', array() );

if ( empty( $tutorslot_settings['delete_data_on_uninstall'] ) ) {
	return;
}

require_once __DIR__ . '/src/Autoloader.php';
\TutorSlot\Autoloader::register();

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

\TutorSlot\Database\Schema::drop_all();

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'tutorslot' );
}

delete_option( 'tutorslot_settings' );
delete_option( 'tutorslot_db_version' );
delete_transient( 'tutorslot_show_onboarding' );

wp_cache_flush_group( 'tutorslot' );

\TutorSlot\Support\Capabilities::remove_all();
