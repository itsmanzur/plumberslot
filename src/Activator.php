<?php
/**
 * Runs once on activation.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot;

use TutorSlot\Database\Schema;
use TutorSlot\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Activator {

	public static function activate(): void {
		if ( version_compare( get_bloginfo( 'version' ), MIN_WP, '<' ) ) {
			deactivate_plugins( plugin_basename( PLUGIN_FILE ) );
			wp_die(
				esc_html__( 'TutorSlot needs WordPress 6.4 or newer.', 'tutorslot' ),
				esc_html__( 'Cannot activate TutorSlot', 'tutorslot' ),
				array( 'back_link' => true )
			);
		}

		Schema::create_all();
		Capabilities::add_all();

		add_option(
			'tutorslot_settings',
			array(
				'timezone'                 => wp_timezone_string(),
				'slot_granularity_minutes' => 30,
				'default_lesson_minutes'   => 60,
				'buffer_minutes'           => 10,
				'lead_time_minutes'        => 240,
				'hold_window_minutes'      => 10,
				'slot_cache_ttl'           => 900,
				'auto_confirm'             => true,
				'copy_parent_on_all_mail'  => true,
				'delete_data_on_uninstall' => false,
			),
			'',
			false // Never autoload: this option is only read on TutorSlot requests.
		);

		update_option( 'tutorslot_db_version', DB_VERSION, false );
		set_transient( 'tutorslot_show_onboarding', 1, DAY_IN_SECONDS );

		flush_rewrite_rules();
	}
}
