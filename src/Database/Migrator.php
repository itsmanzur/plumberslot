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
		1  => 'step_1_initial',
		2  => 'step_2_lock_ownership',
		3  => 'step_3_service_status',
		4  => 'step_4_drop_unique_slot_key',
		5  => 'step_5_payments',
		6  => 'step_6_performance_hardening',
		7  => 'step_7_service_address',
		8  => 'step_8_booking_photos',
		9  => 'step_9_emergency_booking',
		10 => 'step_10_service_plan_payments',
		11 => 'step_11_recurrence_cadence',
		12 => 'step_12_deposit_payments',
		13 => 'step_13_job_status_tracking',
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

	/**
	 * Adds the `photos` column: a JSON array of WordPress attachment ids for
	 * the job-site photos a customer optionally attaches to a booking.
	 */
	private function step_8_booking_photos(): void {
		Schema::create_all();
	}

	/**
	 * Adds `is_emergency_available` to services (a technician marks which jobs
	 * are bookable ASAP) and `is_emergency` to bookings (whether this specific
	 * appointment was made through that path).
	 */
	private function step_9_emergency_booking(): void {
		Schema::create_all();
	}

	/**
	 * A Service Plan can now be bought with a real charge instead of being
	 * issued for free: CREDITS gains a pending/active/failed `status` (default
	 * 'active', so every existing row and the still-unchanged free/admin
	 * purchase() path keep today's "usable immediately" behaviour), a
	 * `currency` to freeze at purchase time (mirrors BOOKINGS.currency rather
	 * than trusting a live Settings read at webhook time), `payment_ref`, and
	 * `reminded_at` (dedupe for the expiry-reminder job, the same
	 * durable-column pattern the webhooks table uses instead of a transient).
	 *
	 * PAYMENTS.booking_id moves from NOT NULL to NULL and gains a nullable
	 * `credit_id`, so one payment row can point at either a booking or a
	 * credit package. This step relies on dbDelta() altering an existing
	 * column's NOT NULL -> NULL -- see the migration report for how
	 * confident that is without a live install to verify against.
	 */
	private function step_10_service_plan_payments(): void {
		Schema::create_all();
	}

	/**
	 * Adds `interval_weeks` to SERIES: how many weeks apart the recurring
	 * plan's qualifying weeks are, not just which weekdays. Every existing
	 * series row keeps its current every-matching-weekday-every-week
	 * behaviour because the column default is 1.
	 */
	private function step_11_recurrence_cadence(): void {
		Schema::create_all();
	}

	/**
	 * Adds deposit config to SERVICES (`deposit_type`, `deposit_value`) and the
	 * computed split to BOOKINGS (`deposit_minor`, `balance_minor`). Every
	 * existing service defaults to `deposit_type = 'none'`, so every existing
	 * and newly-created booking with no deposit configured keeps today's
	 * charge-the-full-price-now behaviour exactly.
	 */
	private function step_12_deposit_payments(): void {
		Schema::create_all();
	}

	/**
	 * Adds `job_stage` to BOOKINGS: a one-tap status the technician updates
	 * (scheduled / on_the_way / in_progress) while the booking's own `status`
	 * stays 'confirmed'. Every existing booking defaults to 'scheduled'.
	 */
	private function step_13_job_status_tracking(): void {
		Schema::create_all();
	}
}
