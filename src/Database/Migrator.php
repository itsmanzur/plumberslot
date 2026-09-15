<?php
/**
 * Applies schema upgrades between plugin versions.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Database;

use PlumberSlot\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Migrator {

	/**
	 * Numbered steps. Each runs exactly once, in order.
	 *
	 * @var array<int, string>
	 */
	private const STEPS = array(
		1 => 'step_1_initial',
		2 => 'step_2_lock_ownership',
		3 => 'step_3_service_status',
		4 => 'step_4_drop_unique_slot_key',
		5 => 'step_5_payments',
		6 => 'step_6_performance_hardening',
		7 => 'step_7_service_address',
	);

	public function maybe_upgrade(): void {
		/** @var int $installed */
		$installed = (int) get_option( 'plumberslot_db_version', 0 );

		if ( $installed >= \PlumberSlot\DB_VERSION ) {
			return;
		}

		foreach ( self::STEPS as $version => $method ) {
			if ( $version <= $installed ) {
				continue;
			}
			$this->{$method}();
			update_option( 'plumberslot_db_version', $version, false );
		}

		wp_cache_flush_group( 'plumberslot' );
	}

	private function step_1_initial(): void {
		Schema::create_all();
		Capabilities::add_all();
	}

	private function step_2_lock_ownership(): void {
		Schema::create_all();
	}

	private function step_3_service_status(): void {
		Schema::create_all();
	}

	/**
	 * Moved/cancelled rows must not permanently block a start time. Overlap
	 * safety comes from the per-technician advisory lock plus has_overlap().
	 */
	private function step_4_drop_unique_slot_key(): void {
		global $wpdb;

		$table = Schema::table( Schema::BOOKINGS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- migration introspection on whitelist table.
		$has_unique = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'uq_slot'" );

		if ( $has_unique ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- migration DDL on whitelist table.
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX uq_slot" );
		}

		Schema::create_all();
	}

	private function step_5_payments(): void {
		Schema::create_all();
	}

	private function step_6_performance_hardening(): void {
		Schema::create_all();

		global $wpdb;

		$prefix = $wpdb->esc_like( 'plumberslot_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-time migration of the internal option prefix.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name LIKE %s AND autoload <> 'no'",
				$prefix
			)
		);

		if ( is_int( $updated ) && $updated > 0 ) {
			wp_cache_delete( 'alloptions', 'options' );
		}
	}

	/**
	 * Adds the structured service-address columns to bookings. Plumbing is an
	 * on-site trade, unlike the remote tutoring this plugin was forked from.
	 */
	private function step_7_service_address(): void {
		Schema::create_all();
	}
}
