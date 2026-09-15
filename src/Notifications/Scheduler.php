<?php
/**
 * Reminder scheduling.
 *
 * Action Scheduler, not WP-Cron. WP-Cron only fires when somebody loads a page,
 * so on a quiet tutoring site the twenty-four-hour reminder simply never goes
 * out, and a missed reminder is a no-show, a refund and a one-star review.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Notifications;

use TutorSlot\Database\Repository\BookingRepository;
use TutorSlot\Database\Repository\LockRepository;
use TutorSlot\Domain\PaymentService;
use TutorSlot\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Scheduler {

	private const GROUP = 'tutorslot';

	public function __construct(
		private readonly Dispatcher $dispatcher,
		private readonly PaymentService $payments
	) {}

	public function register(): void {
		add_action( 'tutorslot_booking_created', array( $this, 'schedule_for_booking' ), 10, 1 );
		add_action( 'tutorslot_booking_cancelled', array( $this, 'unschedule_for_booking' ), 10, 1 );
		add_action( 'tutorslot_booking_moved', array( $this, 'unschedule_for_booking' ), 10, 1 );
		add_action( 'tutorslot_booking_refunded', array( $this, 'unschedule_for_booking' ), 10, 1 );
		add_action( 'tutorslot_booking_payment_expired', array( $this, 'unschedule_for_booking' ), 10, 1 );
		add_action( 'tutorslot_send_reminder', array( $this, 'run_reminder' ), 10, 2 );
		add_action( 'tutorslot_payment_started', array( $this, 'schedule_payment_expiry' ), 10, 2 );
		add_action( 'tutorslot_expire_payment', array( $this, 'run_payment_expiry' ), 10, 2 );
		add_action( 'tutorslot_purge_locks', array( $this, 'run_purge' ) );
		add_action( 'init', array( $this, 'ensure_recurring' ) );
	}

	public function ensure_recurring(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( 'tutorslot_purge_locks', array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, 'tutorslot_purge_locks', array(), self::GROUP );
		}
	}

	public function schedule_for_booking( int $booking_id ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$booking = ( new BookingRepository() )->find( $booking_id );

		if ( ! $booking ) {
			return;
		}

		$start = strtotime( (string) $booking->start_utc );

		foreach ( array(
			'24h' => DAY_IN_SECONDS,
			'1h'  => HOUR_IN_SECONDS,
		) as $window => $offset ) {
			$at = $start - $offset;

			if ( $at <= time() ) {
				continue;
			}

			as_schedule_single_action( $at, 'tutorslot_send_reminder', array( $booking_id, $window ), self::GROUP );
		}
	}

	public function unschedule_for_booking( int $booking_id ): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		foreach ( array( '24h', '1h' ) as $window ) {
			as_unschedule_all_actions( 'tutorslot_send_reminder', array( $booking_id, $window ), self::GROUP );
		}
	}

	public function run_reminder( int $booking_id, string $window ): void {
		$booking = ( new BookingRepository() )->find( $booking_id );

		// A lesson cancelled after the reminder was queued must stay quiet.
		if ( ! $booking || 'confirmed' !== $booking->status ) {
			return;
		}

		$this->dispatcher->reminder( $booking_id, $window );
	}

	public function schedule_payment_expiry( int $payment_id, int $booking_id ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$args = array( $payment_id, $booking_id );
		if ( function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( 'tutorslot_expire_payment', $args, self::GROUP ) ) {
			return;
		}

		$window = max( 2, Settings::int( 'hold_window_minutes', 10 ) );
		as_schedule_single_action(
			time() + ( $window * MINUTE_IN_SECONDS ),
			'tutorslot_expire_payment',
			$args,
			self::GROUP
		);
	}

	public function run_payment_expiry( int $payment_id, int $booking_id ): void {
		$this->payments->expire_pending( $payment_id, $booking_id );
	}

	public function run_purge(): void {
		( new LockRepository() )->purge_expired();
	}
}
