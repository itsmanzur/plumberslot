<?php
/**
 * Phase 8 pending-payment expiry scheduler tests.
 *
 * @package TutorSlot\Tests
 */

declare( strict_types = 1 );

namespace TutorSlot\Tests\Integration;

use TutorSlot\Database\Repository\BookingRepository;
use TutorSlot\Database\Repository\PaymentRepository;
use TutorSlot\Database\Schema;
use TutorSlot\Domain\PaymentService;
use TutorSlot\Support\Crypto;
use TutorSlot\Support\Settings;

/**
 * @group integration
 */
final class Phase8PaymentExpiryTest extends \WP_UnitTestCase {

	private BookingRepository $bookings;
	private PaymentRepository $payments;
	private PaymentService $service;

	public function set_up(): void {
		parent::set_up();
		Schema::create_all();
		$this->empty_tables();

		$this->bookings = new BookingRepository();
		$this->payments = new PaymentRepository();
		$this->service  = \TutorSlot\Plugin::instance()->container()->get( PaymentService::class );

		Settings::update( array( 'hold_window_minutes' => 10 ) );
	}

	public function tear_down(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'tutorslot_expire_payment' );
		}
		Settings::update( array( 'stripe_secret_key' => '' ) );
		$this->empty_tables();
		parent::tear_down();
	}

	public function test_payment_started_event_schedules_expiry_for_hold_window(): void {
		$fixture    = $this->create_pending_payment( 3200 );
		$payment_id = $fixture['payment_id'];
		$booking_id = $fixture['booking_id'];
		$before     = time();

		do_action( 'tutorslot_payment_started', $payment_id, $booking_id );

		$scheduled = as_next_scheduled_action(
			'tutorslot_expire_payment',
			array( $payment_id, $booking_id ),
			'tutorslot'
		);

		$this->assertIsInt( $scheduled );
		$this->assertGreaterThanOrEqual( $before + ( 10 * MINUTE_IN_SECONDS ), $scheduled );
		$this->assertLessThanOrEqual( time() + ( 10 * MINUTE_IN_SECONDS ), $scheduled );
	}

	public function test_expiry_callback_does_not_cancel_a_newer_payment_attempt(): void {
		$fixture  = $this->create_pending_payment( 2800 );
		$first_id = $fixture['payment_id'];
		$booking  = $this->bookings->find( $fixture['booking_id'] );
		$this->payments->update_status( $first_id, 'cancelled' );
		$second_id = $this->payments->create(
			array(
				'booking_id'   => (int) $booking->id,
				'gateway'      => 'fake',
				'amount_minor' => 2800,
				'currency'     => 'USD',
				'status'       => 'pending',
			)
		);

		do_action( 'tutorslot_expire_payment', $first_id, (int) $booking->id );
		$this->assertSame( 'pending', $this->payments->latest_for_booking( (int) $booking->id )->status );
		$this->assertSame( 'pending_payment', $this->bookings->find( (int) $booking->id )->status );

		do_action( 'tutorslot_expire_payment', $second_id, (int) $booking->id );
		$this->assertSame( 'expired', $this->payments->latest_for_booking( (int) $booking->id )->status );
		$this->assertSame( 'payment_expired', $this->bookings->find( (int) $booking->id )->status );

		$replacement = $this->insert_booking(
			(int) $booking->tutor_id,
			(string) $booking->start_utc,
			(string) $booking->end_utc,
			2800
		);
		$this->assertIsInt( $replacement );
	}

	public function test_expiry_unschedules_reminders_and_audits_once(): void {
		$fixture = $this->create_pending_payment( 3000 );

		do_action( 'tutorslot_booking_created', $fixture['booking_id'], array() );
		$this->assertTrue( $this->reminder_is_scheduled( $fixture['booking_id'], '24h' ) );
		$this->assertTrue( $this->reminder_is_scheduled( $fixture['booking_id'], '1h' ) );

		do_action( 'tutorslot_expire_payment', $fixture['payment_id'], $fixture['booking_id'] );

		$this->assertFalse( $this->reminder_is_scheduled( $fixture['booking_id'], '24h' ) );
		$this->assertFalse( $this->reminder_is_scheduled( $fixture['booking_id'], '1h' ) );
		$this->assertSame( 1, $this->audit_count( 'payment.expired', 'payment', $fixture['payment_id'] ) );
		$this->assertSame( 1, $this->audit_count( 'booking.payment_expired', 'booking', $fixture['booking_id'] ) );

		do_action( 'tutorslot_expire_payment', $fixture['payment_id'], $fixture['booking_id'] );
		$this->assertSame( 1, $this->audit_count( 'payment.expired', 'payment', $fixture['payment_id'] ) );
		$this->assertSame( 1, $this->audit_count( 'booking.payment_expired', 'booking', $fixture['booking_id'] ) );
	}

	public function test_expiry_callback_does_not_overwrite_a_settled_payment(): void {
		$fixture = $this->create_pending_payment( 4100 );
		$this->payments->update_status( $fixture['payment_id'], 'paid' );

		do_action( 'tutorslot_expire_payment', $fixture['payment_id'], $fixture['booking_id'] );

		$this->assertSame( 'paid', $this->payments->latest_for_booking( $fixture['booking_id'] )->status );
		$this->assertSame( 'pending_payment', $this->bookings->find( $fixture['booking_id'] )->status );
	}

	public function test_expiry_rolls_back_payment_when_booking_transition_fails(): void {
		global $wpdb;

		$fixture = $this->create_pending_payment( 3600 );
		$table   = Schema::table( Schema::BOOKINGS );
		$fail    = static function ( string $query ) use ( $table ): string {
			if ( str_contains( $query, 'UPDATE ' . $table ) && str_contains( $query, 'payment_expired' ) ) {
				return "UPDATE {$table}_missing SET status = 'payment_expired'";
			}

			return $query;
		};

		$previous = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			do_action( 'tutorslot_expire_payment', $fixture['payment_id'], $fixture['booking_id'] );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}

		$this->assertSame( 'pending', $this->payments->latest_for_booking( $fixture['booking_id'] )->status );
		$this->assertSame( 'pending_payment', $this->bookings->find( $fixture['booking_id'] )->status );
	}

	public function test_late_success_is_refunded_without_reviving_expired_booking(): void {
		$fixture = $this->create_pending_payment( 4700, 'stripe', 'pi_checkout' );
		do_action( 'tutorslot_expire_payment', $fixture['payment_id'], $fixture['booking_id'] );
		Settings::update( array( 'stripe_secret_key' => Crypto::encrypt( 'sk_test_expiry' ) ) );

		$http = static function (): array {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'id' => 're_late_capture' ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $http );
		try {
			$result = $this->service->apply_event(
				'stripe',
				array(
					'booking_id'      => $fixture['booking_id'],
					'status'          => 'paid',
					'reference'       => 'pi_late_capture',
					'idempotency_key' => 'evt_late_capture',
					'amount_minor'    => 4700,
					'currency'        => 'USD',
				)
			);
		} finally {
			remove_filter( 'pre_http_request', $http );
		}

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertSame( 'refunded', $this->payments->latest_for_booking( $fixture['booking_id'] )->status );
		$this->assertSame( 'refunded', $this->bookings->find( $fixture['booking_id'] )->status );
	}

	/**
	 * @return array{payment_id:int,booking_id:int}
	 */
	private function create_pending_payment( int $amount, string $gateway = 'fake', ?string $reference = null ): array {
		$booking_id = $this->insert_booking(
			71,
			gmdate( 'Y-m-d H:i:s', time() + ( 3 * DAY_IN_SECONDS ) ),
			gmdate( 'Y-m-d H:i:s', time() + ( 3 * DAY_IN_SECONDS ) + HOUR_IN_SECONDS ),
			$amount
		);
		$this->assertIsInt( $booking_id );

		return array(
			'booking_id' => $booking_id,
			'payment_id' => $this->payments->create(
				array(
					'booking_id'   => $booking_id,
					'gateway'      => $gateway,
					'reference'    => $reference,
					'amount_minor' => $amount,
					'currency'     => 'USD',
					'status'       => 'pending',
				)
			),
		);
	}

	private function insert_booking( int $tutor_id, string $start, string $end, int $amount ): ?int {
		return $this->bookings->insert_unique(
			array(
				'tutor_id'      => $tutor_id,
				'student_id'    => 81,
				'parent_id'     => null,
				'subject_id'    => null,
				'series_id'     => null,
				'series_index'  => null,
				'start_utc'     => $start,
				'end_utc'       => $end,
				'student_tz'    => 'UTC',
				'status'        => 'pending_payment',
				'price_minor'   => $amount,
				'currency'      => 'USD',
				'credit_id'     => null,
				'payment_ref'   => null,
				'meeting_ref'   => null,
				'meeting_token' => null,
				'notes'         => null,
			)
		);
	}

	private function empty_tables(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- integration-test tables only.
		foreach ( Schema::all_keys() as $key ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $key ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	private function reminder_is_scheduled( int $booking_id, string $window ): bool {
		return false !== as_has_scheduled_action(
			'tutorslot_send_reminder',
			array( $booking_id, $window ),
			'tutorslot'
		);
	}

	private function audit_count( string $action, string $object_type, int $object_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- integration assertion over a schema-whitelisted table.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::table( Schema::AUDIT ) . ' WHERE action = %s AND object_type = %s AND object_id = %d',
				$action,
				$object_type,
				$object_id
			)
		);
	}
}
