<?php
/**
 * Phase 6 payment orchestration tests.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PlumberSlot\Database\Repository\AvailabilityRepository;
use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Repository\CreditRepository;
use PlumberSlot\Database\Repository\LockRepository;
use PlumberSlot\Database\Repository\PaymentRepository;
use PlumberSlot\Database\Repository\SubjectRepository;
use PlumberSlot\Database\Repository\TutorRepository;
use PlumberSlot\Database\Schema;
use PlumberSlot\Database\TransactionManager;
use PlumberSlot\Domain\BookingService;
use PlumberSlot\Domain\CreditService;
use PlumberSlot\Domain\PaymentService;
use PlumberSlot\Domain\PolicyService;
use PlumberSlot\Domain\SlotEngine;
use PlumberSlot\Notifications\Dispatcher;
use PlumberSlot\Payments\GatewayInterface;
use PlumberSlot\Payments\GatewayRegistry;
use PlumberSlot\Payments\StripeGateway;
use PlumberSlot\Payments\WebhookController;
use PlumberSlot\Support\Capabilities;
use PlumberSlot\Support\Crypto;
use PlumberSlot\Support\Settings;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

final class Phase6PaymentsTest extends WP_UnitTestCase {

	private BookingRepository $bookings;
	private PaymentRepository $payments;
	private AvailabilityRepository $availability;
	private TutorRepository $tutors;
	private FakePayGateway $gateway;

	public function set_up(): void {
		parent::set_up();
		Schema::create_all();
		Capabilities::add_all();
		$this->empty_tables();

		$this->bookings     = new BookingRepository();
		$this->payments     = new PaymentRepository();
		$this->availability = new AvailabilityRepository();
		$this->tutors       = new TutorRepository();
		$this->gateway      = new FakePayGateway();

		Settings::update(
			array(
				'lead_time_minutes'        => 0,
				'slot_granularity_minutes' => 30,
				'default_lesson_minutes'   => 60,
				'buffer_minutes'           => 0,
				'auto_confirm'             => true,
				'payments_enabled'         => true,
			)
		);

		add_filter( 'plumberslot_email_enabled', '__return_false' );
	}

	public function tear_down(): void {
		remove_filter( 'plumberslot_email_enabled', '__return_false' );
		$this->empty_tables();
		parent::tear_down();
	}

	public function test_start_uses_booking_amount_not_client_amount(): void {
		$ctx     = $this->seed();
		$service = $this->payment_service();
		$booking = $this->create_payable( $ctx, 5000 );

		$result = $service->start(
			$booking,
			'fake',
			'https://example.com/ok',
			'https://example.com/cancel'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 5000, $result['amount_minor'] );
		$this->assertSame( 5000, $this->gateway->last_amount );
		$this->assertNotSame( 100, $this->gateway->last_amount );

		$row = $this->payments->latest_for_booking( $booking );
		$this->assertSame( 'pending', $row->status );
		$this->assertSame( 5000, (int) $row->amount_minor );
	}

	public function test_webhook_replay_does_not_confirm_twice(): void {
		$ctx     = $this->seed();
		$service = $this->payment_service();
		$booking = $this->create_payable( $ctx, 2000 );
		$service->start( $booking, 'fake', 'https://example.com/ok', 'https://example.com/cancel' );

		$event = array(
			'booking_id'      => $booking,
			'status'          => 'paid',
			'reference'       => 'pi_test_1',
			'idempotency_key' => 'evt_replay_1',
			'amount_minor'    => 2000,
			'currency'        => 'USD',
		);

		$first  = $service->apply_event( 'fake', $event );
		$second = $service->apply_event( 'fake', $event );

		$this->assertTrue( ! empty( $first['ok'] ) );
		$this->assertTrue( ! empty( $second['duplicate'] ) );

		$row = $this->bookings->find( $booking );
		$this->assertSame( 'confirmed', $row->status );
		$this->assertSame( 'pi_test_1', $row->payment_ref );
	}

	public function test_signed_webhook_replay_has_one_durable_side_effect(): void {
		$ctx        = $this->seed();
		$service    = $this->payment_service();
		$booking_id = $this->create_payable( $ctx, 2400 );
		$registry   = new GatewayRegistry();
		$registry->register( $this->gateway );
		$controller = new WebhookController( $registry, $service );
		$event      = array(
			'booking_id'      => $booking_id,
			'status'          => 'paid',
			'reference'       => 'sess_' . $booking_id,
			'idempotency_key' => 'evt_signed_replay',
			'amount_minor'    => 2400,
			'currency'        => 'USD',
		);
		$raw        = (string) wp_json_encode( $event );
		$paid_calls = 0;
		$capture    = static function () use ( &$paid_calls ): void {
			++$paid_calls;
		};

		$service->start( $booking_id, 'fake', 'https://example.com/ok', 'https://example.com/cancel' );
		add_action( 'plumberslot_booking_paid', $capture );

		try {
			$first  = $controller->handle( $this->webhook_request( $raw, $this->gateway->sign( $raw ) ) );
			$replay = $controller->handle( $this->webhook_request( $raw, $this->gateway->sign( $raw ) ) );
		} finally {
			remove_action( 'plumberslot_booking_paid', $capture );
		}

		$this->assertNotWPError( $first );
		$this->assertNotWPError( $replay );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( array( 'ok' => true ), $first->get_data() );
		$this->assertSame( 200, $replay->get_status() );
		$this->assertSame( array( 'duplicate' => true ), $replay->get_data() );
		$this->assertSame( 1, $paid_calls );
		$this->assertSame( 1, $this->webhook_total() );
		$this->assertCount( 1, $this->payments->for_booking( $booking_id ) );

		$row = $this->bookings->find( $booking_id );
		$this->assertSame( 'confirmed', $row->status );
		$this->assertSame( 'sess_' . $booking_id, $row->payment_ref );

		$tampered = str_replace( '2400', '1', $raw );
		$rejected = $controller->handle( $this->webhook_request( $tampered, $this->gateway->sign( $raw ) ) );

		$this->assertWPError( $rejected );
		$this->assertSame( 'plumberslot_bad_signature', $rejected->get_error_code() );
		$this->assertSame( 400, $rejected->get_error_data()['status'] );
		$this->assertSame( 2, $this->gateway->parse_calls );
		$this->assertSame( 1, $this->webhook_total() );
	}

	public function test_stripe_webhook_signature_rejects_tampered_and_expired_payloads(): void {
		$secret  = 'whsec_phase8_security';
		$body    = '{"id":"evt_phase8"}';
		$current = time();
		$stripe  = new StripeGateway();

		Settings::update( array( 'stripe_webhook_secret' => Crypto::encrypt( $secret ) ) );

		$valid_header = $this->stripe_signature( $secret, $current, $body );

		$this->assertTrue( $stripe->verify_webhook( $body, array( 'stripe-signature' => $valid_header ) ) );
		$this->assertFalse( $stripe->verify_webhook( $body . 'tampered', array( 'stripe-signature' => $valid_header ) ) );

		$expired        = $current - ( 5 * MINUTE_IN_SECONDS ) - 1;
		$expired_header = $this->stripe_signature( $secret, $expired, $body );

		$this->assertFalse( $stripe->verify_webhook( $body, array( 'stripe-signature' => $expired_header ) ) );
	}

	public function test_underpay_is_rejected(): void {
		$ctx     = $this->seed();
		$service = $this->payment_service();
		$booking = $this->create_payable( $ctx, 5000 );
		$service->start( $booking, 'fake', 'https://example.com/ok', 'https://example.com/cancel' );

		$result = $service->apply_event(
			'fake',
			array(
				'booking_id'      => $booking,
				'status'          => 'paid',
				'reference'       => 'pi_low',
				'idempotency_key' => 'evt_low',
				'amount_minor'    => 100,
				'currency'        => 'USD',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$row = $this->bookings->find( $booking );
		$this->assertNotSame( 'confirmed', $row->status );
	}

	public function test_failed_payment_can_be_retried_and_refund_marks_history(): void {
		$ctx     = $this->seed();
		$service = $this->payment_service();
		$booking = $this->create_payable( $ctx, 1500 );

		$service->start( $booking, 'fake', 'https://example.com/ok', 'https://example.com/cancel' );
		$service->apply_event(
			'fake',
			array(
				'booking_id'      => $booking,
				'status'          => 'failed',
				'reference'       => 'pi_fail',
				'idempotency_key' => 'evt_fail',
				'amount_minor'    => 1500,
				'currency'        => 'USD',
			)
		);

		$failed = $this->bookings->find( $booking );
		$this->assertSame( 'payment_failed', $failed->status );

		// Retry.
		$retry = $service->start( $booking, 'fake', 'https://example.com/ok', 'https://example.com/cancel' );
		$this->assertIsArray( $retry );

		$service->apply_event(
			'fake',
			array(
				'booking_id'      => $booking,
				'status'          => 'paid',
				'reference'       => 'pi_ok',
				'idempotency_key' => 'evt_ok',
				'amount_minor'    => 1500,
				'currency'        => 'USD',
			)
		);
		$this->assertTrue( $this->bookings->update_status_if_current( $booking, 'confirmed', 'completed' ) );

		$refund = $service->refund_booking( $booking );
		$this->assertIsArray( $refund );
		$this->assertTrue( $refund['refunded'] );

		$history = $this->payments->for_booking( $booking );
		$statuses = array_map( static fn ( $p ) => (string) $p->status, $history );
		$this->assertContains( 'refunded', $statuses );

		$booking_row = $this->bookings->find( $booking );
		$this->assertSame( 'refunded', $booking_row->status );
		$this->assertSame( 1, $this->gateway->refund_calls );

		$replay = $service->refund_booking( $booking );
		$this->assertIsArray( $replay );
		$this->assertTrue( $replay['refunded'] );
		$this->assertSame( 1, $this->gateway->refund_calls );
	}

	public function test_refund_rejects_a_confirmed_lesson_before_calling_gateway(): void {
		$ctx     = $this->seed();
		$service = $this->payment_service();
		$booking = $this->create_payable( $ctx, 1800 );

		$service->start( $booking, 'fake', 'https://example.com/ok', 'https://example.com/cancel' );
		$service->apply_event(
			'fake',
			array(
				'booking_id'      => $booking,
				'status'          => 'paid',
				'reference'       => 'pi_not_completed',
				'idempotency_key' => 'evt_not_completed',
				'amount_minor'    => 1800,
				'currency'        => 'USD',
			)
		);

		$result = $service->refund_booking( $booking );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'plumberslot_invalid_transition', $result->get_error_code() );
		$this->assertSame( 'confirmed', $this->bookings->find( $booking )->status );
		$this->assertSame( 'paid', $this->payments->latest_for_booking( $booking )->status );
		$this->assertSame( 0, $this->gateway->refund_calls );
	}

	public function test_completed_credit_booking_refund_is_atomic_and_idempotent(): void {
		$ctx        = $this->seed();
		$credits    = new CreditRepository();
		$credit_id  = $credits->create_package(
			array(
				'owner_id'    => $ctx['student_id'],
				'tutor_id'    => $ctx['tutor_id'],
				'subject_id'  => null,
				'total'       => 1,
				'price_minor' => 2000,
				'expires_at'  => null,
			)
		);
		$start      = new DateTimeImmutable( '+4 days 11:00:00', new DateTimeZone( 'UTC' ) );
		$args       = array(
			'tutor_id'       => $ctx['tutor_id'],
			'student_id'     => $ctx['student_id'],
			'parent_id'      => null,
			'subject_id'     => null,
			'start_utc'      => $start,
			'duration_min'   => 60,
			'tutor_tz'       => 'UTC',
			'student_tz'     => 'UTC',
			'price_minor'    => 0,
			'currency'       => 'USD',
			'credit_id'      => $credit_id,
			'consume_credit' => true,
			'lock_token'     => null,
			'notes'          => null,
		);
		$booking_id = $this->booking_service()->create( $args );

		$this->assertIsInt( $booking_id );
		$this->assertSame( 1, (int) $credits->find( $credit_id )->used );
		$this->assertTrue( $this->bookings->update_status_if_current( $booking_id, 'confirmed', 'completed' ) );

		$service = $this->payment_service();
		$refund  = $service->refund_booking( $booking_id );

		$this->assertIsArray( $refund );
		$this->assertTrue( $refund['refunded'] );
		$this->assertSame( 'refunded', $this->bookings->find( $booking_id )->status );
		$this->assertSame( 0, (int) $credits->find( $credit_id )->used );
		$this->assertNull( $this->payments->latest_for_booking( $booking_id ) );

		$replay = $service->refund_booking( $booking_id );
		$this->assertIsArray( $replay );
		$this->assertSame( 0, (int) $credits->find( $credit_id )->used );
	}

	public function test_rescheduled_completed_booking_refunds_the_original_payment(): void {
		$ctx       = $this->seed();
		$service   = $this->payment_service();
		$old_id    = $this->create_payable( $ctx, 3200 );
		$old_start = new DateTimeImmutable( (string) $this->bookings->find( $old_id )->start_utc, new DateTimeZone( 'UTC' ) );
		$new_start = $old_start->modify( '+1 day' );

		$service->start( $old_id, 'fake', 'https://example.com/ok', 'https://example.com/cancel' );
		$service->apply_event(
			'fake',
			array(
				'booking_id'      => $old_id,
				'status'          => 'paid',
				'reference'       => 'pi_rescheduled_refund',
				'idempotency_key' => 'evt_rescheduled_refund',
				'amount_minor'    => 3200,
				'currency'        => 'USD',
			)
		);

		$this->assertTrue( $this->booking_service()->reschedule( $old_id, $new_start ) );
		$new_rows = $this->bookings->find_in_range(
			$ctx['tutor_id'],
			$new_start->format( 'Y-m-d H:i:s' ),
			$new_start->modify( '+60 minutes' )->format( 'Y-m-d H:i:s' )
		);
		$new_id = (int) $new_rows[0]->id;

		$this->assertGreaterThan( 0, $new_id );
		$this->assertTrue( $this->bookings->update_status_if_current( $new_id, 'confirmed', 'completed' ) );
		$refund = $service->refund_booking( $new_id );

		$this->assertIsArray( $refund );
		$this->assertSame( 'refunded', $this->bookings->find( $new_id )->status );
		$this->assertSame( 'refunded', $this->payments->latest_for_booking( $old_id )->status );
		$this->assertSame( 1, $this->gateway->refund_calls );
	}

	public function test_credit_refund_failure_rolls_back_completed_booking(): void {
		global $wpdb;

		$ctx        = $this->seed();
		$credits    = new CreditRepository();
		$credit_id  = $credits->create_package(
			array(
				'owner_id'    => $ctx['student_id'],
				'tutor_id'    => $ctx['tutor_id'],
				'subject_id'  => null,
				'total'       => 1,
				'price_minor' => 2000,
				'expires_at'  => null,
			)
		);
		$booking_id = $this->booking_service()->create(
			array(
				'tutor_id'       => $ctx['tutor_id'],
				'student_id'     => $ctx['student_id'],
				'parent_id'      => null,
				'subject_id'     => null,
				'start_utc'      => new DateTimeImmutable( '+5 days 11:00:00', new DateTimeZone( 'UTC' ) ),
				'duration_min'   => 60,
				'tutor_tz'       => 'UTC',
				'student_tz'     => 'UTC',
				'price_minor'    => 0,
				'currency'       => 'USD',
				'credit_id'      => $credit_id,
				'consume_credit' => true,
				'lock_token'     => null,
				'notes'          => null,
			)
		);
		$this->assertIsInt( $booking_id );
		$this->assertTrue( $this->bookings->update_status_if_current( $booking_id, 'confirmed', 'completed' ) );

		$credits_table = Schema::table( Schema::CREDITS );
		$fail_credit   = static function ( string $query ) use ( $credits_table ): string {
			if ( str_contains( $query, "UPDATE {$credits_table} SET used = used - 1" ) ) {
				return 'UPDATE `plumberslot_missing_table` SET `used` = 0';
			}

			return $query;
		};
		$previous_errors = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail_credit );

		try {
			$result = $this->payment_service()->refund_booking( $booking_id );
		} finally {
			remove_filter( 'query', $fail_credit );
			$wpdb->suppress_errors( $previous_errors );
		}

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'plumberslot_refund_persistence_failed', $result->get_error_code() );
		$this->assertSame( 'completed', $this->bookings->find( $booking_id )->status );
		$this->assertSame( 1, (int) $credits->find( $credit_id )->used );
	}

	public function test_paid_refund_retries_after_local_persistence_failure(): void {
		global $wpdb;

		$ctx     = $this->seed();
		$service = $this->payment_service();
		$booking = $this->create_payable( $ctx, 2600 );

		$service->start( $booking, 'fake', 'https://example.com/ok', 'https://example.com/cancel' );
		$service->apply_event(
			'fake',
			array(
				'booking_id'      => $booking,
				'status'          => 'paid',
				'reference'       => 'pi_retry_refund',
				'idempotency_key' => 'evt_retry_refund',
				'amount_minor'    => 2600,
				'currency'        => 'USD',
			)
		);
		$this->assertTrue( $this->bookings->update_status_if_current( $booking, 'confirmed', 'completed' ) );

		$bookings_table = Schema::table( Schema::BOOKINGS );
		$fail_local     = static function ( string $query ) use ( $bookings_table ): string {
			if ( str_contains( $query, "UPDATE {$bookings_table} SET status = 'refunded'" ) ) {
				return 'UPDATE `plumberslot_missing_table` SET `status` = \'refunded\'';
			}

			return $query;
		};
		$previous_errors = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail_local );

		try {
			$failed = $service->refund_booking( $booking );
		} finally {
			remove_filter( 'query', $fail_local );
			$wpdb->suppress_errors( $previous_errors );
		}

		$this->assertInstanceOf( WP_Error::class, $failed );
		$this->assertSame( 'plumberslot_refund_persistence_failed', $failed->get_error_code() );
		$this->assertSame( 'completed', $this->bookings->find( $booking )->status );
		$this->assertSame( 'paid', $this->payments->latest_for_booking( $booking )->status );

		$retried = $service->refund_booking( $booking );
		$this->assertIsArray( $retried );
		$this->assertSame( 'refunded', $this->bookings->find( $booking )->status );
		$this->assertSame( 'refunded', $this->payments->latest_for_booking( $booking )->status );
		$this->assertSame( 2, $this->gateway->refund_calls );
		$this->assertCount( 1, array_unique( $this->gateway->refund_keys ) );
	}

	/**
	 * @return array{tutor_id:int, student_id:int}
	 */
	private function seed(): array {
		$user    = self::factory()->user->create( array( 'role' => Capabilities::ROLE_TUTOR ) );
		$student = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$tutor   = $this->tutors->create(
			array(
				'user_id'      => $user,
				'slug'         => 'pay-tutor-' . $user,
				'display_name' => 'Pay Tutor',
				'timezone'     => 'UTC',
				'status'       => 'active',
			)
		);

		$week = array();
		for ( $d = 0; $d < 7; $d++ ) {
			$week[] = array(
				'weekday'   => $d,
				'start_min' => 8 * 60,
				'end_min'   => 20 * 60,
			);
		}
		$this->availability->replace_week( $tutor, $week );

		return array(
			'tutor_id'   => $tutor,
			'student_id' => $student,
		);
	}

	private function create_payable( array $ctx, int $amount ): int {
		$start = new DateTimeImmutable( '+3 days 11:00:00', new DateTimeZone( 'UTC' ) );
		$id    = $this->booking_service()->create(
			array(
				'tutor_id'     => $ctx['tutor_id'],
				'student_id'   => $ctx['student_id'],
				'parent_id'    => null,
				'subject_id'   => null,
				'start_utc'    => $start,
				'duration_min' => 60,
				'tutor_tz'     => 'UTC',
				'student_tz'   => 'UTC',
				'price_minor'  => $amount,
				'currency'     => 'USD',
				'credit_id'    => null,
				'lock_token'   => null,
				'notes'        => null,
			)
		);

		$this->assertIsInt( $id );

		return $id;
	}

	private function booking_service(): BookingService {
		$locks = new LockRepository();

		return new BookingService(
			$this->bookings,
			$locks,
			new SlotEngine( $this->availability, $this->bookings, $locks ),
			new PolicyService( $this->bookings ),
			new Dispatcher(),
			new CreditService( new CreditRepository() ),
			new TransactionManager()
		);
	}

	private function payment_service(): PaymentService {
		$registry = new GatewayRegistry();
		$registry->register( $this->gateway );

		return new PaymentService(
			$registry,
			$this->payments,
			$this->bookings,
			$this->booking_service(),
			new CreditService( new CreditRepository() ),
			new TransactionManager()
		);
	}

	private function webhook_request( string $body, string $signature ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/plumberslot/v1/webhook/fake' );
		$request->set_param( 'gateway', 'fake' );
		$request->set_header( 'X-Fake-Signature', $signature );
		$request->set_body( $body );

		return $request;
	}

	private function stripe_signature( string $secret, int $timestamp, string $body ): string {
		return 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	private function webhook_total(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integration invariant over a schema-whitelisted table.
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( Schema::WEBHOOKS ) );
	}

	private function empty_tables(): void {
		global $wpdb;
		foreach ( Schema::all_keys() as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( 'DELETE FROM ' . Schema::table( $key ) );
		}
	}
}

final class FakePayGateway implements GatewayInterface {

	public int $last_amount = 0;
	public int $parse_calls = 0;
	public int $refund_calls = 0;
	/** @var list<string> */
	public array $refund_keys = array();

	private const WEBHOOK_SECRET = 'phase8-fake-webhook-secret';

	public function id(): string {
		return 'fake';
	}

	public function label(): string {
		return 'Fake';
	}

	public function is_configured(): bool {
		return true;
	}

	public function start( int $booking_id, int $amount_minor, string $currency, string $success_url, string $cancel_url ): array|WP_Error {
		$this->last_amount = $amount_minor;

		return array(
			'url'       => 'https://pay.example/checkout',
			'reference' => 'sess_' . $booking_id,
		);
	}

	public function verify_webhook( string $raw_body, array $headers ): bool {
		$signature = (string) ( $headers['x-fake-signature'] ?? '' );

		return '' !== $signature && hash_equals( $this->sign( $raw_body ), $signature );
	}

	public function parse_webhook( string $raw_body ): array|WP_Error {
		++$this->parse_calls;

		return json_decode( $raw_body, true ) ?: new WP_Error( 'bad' );
	}

	public function sign( string $raw_body ): string {
		return hash_hmac( 'sha256', $raw_body, self::WEBHOOK_SECRET );
	}

	public function refund( string $reference, int $amount_minor, string $idempotency_key = '' ): bool|WP_Error {
		++$this->refund_calls;
		$this->refund_keys[] = $idempotency_key;

		return true;
	}
}
