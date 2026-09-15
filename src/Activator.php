<?php
/**
 * Runs once on activation.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot;

use PlumberSlot\Database\Schema;
use PlumberSlot\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Activator {

	public static function activate(): void {
		if ( version_compare( get_bloginfo( 'version' ), MIN_WP, '<' ) ) {
			deactivate_plugins( plugin_basename( PLUGIN_FILE ) );
			wp_die(
				esc_html__( 'PlumberSlot needs WordPress 6.4 or newer.', 'plumberslot' ),
				esc_html__( 'Cannot activate PlumberSlot', 'plumberslot' ),
				array( 'back_link' => true )
			);
		}

		Schema::create_all();
		Capabilities::add_all();

		add_option(
			'plumberslot_settings',
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
			false // Never autoload: this option is only read on PlumberSlot requests.
		);

		update_option( 'plumberslot_db_version', DB_VERSION, false );
		set_transient( 'plumberslot_show_onboarding', 1, DAY_IN_SECONDS );

		flush_rewrite_rules();
	}
}
