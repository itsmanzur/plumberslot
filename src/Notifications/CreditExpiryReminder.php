<?php
/**
 * Reminds a customer before their Service Plan (job credit package) expires.
 *
 * Same recurring-Action-Scheduler shape as PendingPhotoCleanup: a daily tick
 * registered in Container.php/Plugin.php, not WP-Cron, for the same reason
 * Scheduler.php avoids it -- a quiet site simply never fires a stray WP-Cron
 * event, and a missed expiry reminder is a package quietly lapsing unused.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Notifications;

use PlumberSlot\Database\Repository\CreditRepository;
use PlumberSlot\Notifications\Channel\EmailChannel;
use PlumberSlot\Support\AuditLog;
use PlumberSlot\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class CreditExpiryReminder {

	private const ACTION   = 'plumberslot_credit_expiry_reminder';
	private const GROUP    = 'plumberslot';
	private const INTERVAL = DAY_IN_SECONDS;

	/**
	 * Reminded per run, so one very large backlog cannot turn a daily tick
	 * into an unbounded batch of emails -- mirrors PendingPhotoCleanup's
	 * BATCH_SIZE guard.
	 */
	private const BATCH_SIZE = 200;

	public function __construct( private readonly CreditRepository $credits ) {}

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
	 * Email every active, not-yet-reminded package expiring soon. reminded_at
	 * is claimed (compare-and-set) before sending, not after, so a mail
	 * transport failure cannot leave a package to be re-emailed forever on
	 * every subsequent tick -- the same "claim then act" order
	 * claim_webhook()/apply_event() use for payment idempotency.
	 */
	public function run(): void {
		$days = max( 1, Settings::int( 'credit_expiry_reminder_days', 7 ) );
		$rows = $this->credits->expiring_soon( $days, self::BATCH_SIZE );

		if ( array() === $rows ) {
			return;
		}

		$channel = new EmailChannel();
		$sent    = 0;

		foreach ( $rows as $row ) {
			$remaining = (int) $row->total - (int) $row->used;
			if ( $remaining <= 0 ) {
				continue;
			}

			if ( ! $this->credits->mark_reminded( (int) $row->id ) ) {
				continue;
			}

			$channel->send_credit_expiring( (int) $row->owner_id, $row, $remaining );
			++$sent;
		}

		if ( $sent > 0 ) {
			AuditLog::record( 'credit.expiry_reminder_batch', 'credit', 0, array( 'count' => $sent ), 0 );
		}
	}
}
