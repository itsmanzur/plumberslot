<?php
/**
 * Deletes job-site photos a customer uploaded but never attached to a booking.
 *
 * Uploads happen before a booking row exists (the customer picks photos on
 * the Address step, well before Confirm), so an abandoned checkout leaves a
 * real Media Library attachment behind with nothing pointing at it. This
 * sweeps those up on the same recurring-Action-Scheduler shape used for
 * MeetingCleanup's retries and Scheduler's lock purge.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Media;

use PlumberSlot\Support\AuditLog;

defined( 'ABSPATH' ) || exit;

final class PendingPhotoCleanup {

	private const ACTION   = 'plumberslot_cleanup_pending_photos';
	private const GROUP    = 'plumberslot';
	private const INTERVAL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Deleted per run, so one very large backlog cannot turn a cron tick into
	 * an unbounded batch of `wp_delete_attachment()` calls.
	 */
	private const BATCH_SIZE = 100;

	public function register(): void {
		add_action( 'init', array( $this, 'ensure_recurring' ) );
		add_action( self::ACTION, array( $this, 'run' ) );
	}

	public function ensure_recurring(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( self::ACTION, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, self::ACTION, array(), self::GROUP );
		}
	}

	/**
	 * Delete every pending photo whose hold has expired.
	 */
	public function run(): void {
		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'fields'           => 'ids',
				'posts_per_page'   => self::BATCH_SIZE,
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, infrequent cleanup sweep against a whitelisted meta key.
					array(
						'key'     => PendingPhoto::META_PENDING,
						'value'   => 1,
						'compare' => '=',
					),
					array(
						'key'     => PendingPhoto::META_EXPIRES,
						'value'   => time(),
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		if ( array() === $ids ) {
			return;
		}

		foreach ( $ids as $id ) {
			wp_delete_attachment( (int) $id, true );
		}

		AuditLog::record( 'photo.cleanup_expired', 'attachment', 0, array( 'count' => count( $ids ) ) );
	}
}
