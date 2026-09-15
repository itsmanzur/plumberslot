<?php
/**
 * Phase 5: series, credits purchase/refund, relations.
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
use PlumberSlot\Database\Repository\RelationRepository;
use PlumberSlot\Database\Repository\SeriesRepository;
use PlumberSlot\Database\Repository\SubjectRepository;
use PlumberSlot\Database\Repository\TutorRepository;
use PlumberSlot\Database\Schema;
use PlumberSlot\Database\TransactionManager;
use PlumberSlot\Domain\BookingService;
use PlumberSlot\Domain\CreditService;
use PlumberSlot\Domain\PolicyService;
use PlumberSlot\Domain\RecurrenceService;
use PlumberSlot\Domain\SlotEngine;
use PlumberSlot\Notifications\Dispatcher;
use PlumberSlot\Support\AuditLog;
use PlumberSlot\Support\Capabilities;
use PlumberSlot\Support\Settings;
use PlumberSlot\Support\Time;
use WP_UnitTestCase;

final class Phase5RecurringCreditsParentTest extends WP_UnitTestCase {

	private BookingRepository $bookings;
	private CreditRepository $credits;
	private AvailabilityRepository $availability;
	private TutorRepository $tutors;
	private SubjectRepository $subjects;
	private RelationRepository $relations;
	private SeriesRepository $series;

	public function set_up(): void {
		parent::set_up();

		Schema::create_all();
		Capabilities::add_all();
		$this->empty_tables();

		$this->bookings     = new BookingRepository();
		$this->credits      = new CreditRepository();
		$this->availability = new AvailabilityRepository();
		$this->tutors       = new TutorRepository();
		$this->subjects     = new SubjectRepository();
		$this->relations    = new RelationRepository();
		$this->series       = new SeriesRepository();

		Settings::update(
			array(
				'lead_time_minutes'            => 0,
				'slot_granularity_minutes'     => 30,
				'default_lesson_minutes'       => 60,
				'buffer_minutes'               => 0,
				'slot_cache_ttl'               => 60,
				'auto_confirm'                 => true,
				'credit_refund_window_minutes' => 10080,
				'credit_rollover_enabled'      => true,
				'credit_expiry_days'           => 180,
				'credit_package_size'          => 10,
			)
		);

		add_filter( 'plumberslot_email_enabled', '__return_false' );
	}

	public function tear_down(): void {
		remove_filter( 'plumberslot_email_enabled', '__return_false' );
		$this->empty_tables();
		parent::tear_down();
	}

	public function test_series_skips_occupied_and_cancels_future_only(): void {
		$ctx   = $this->seed_tutor_week();
		$svc   = $this->booking_service();
		$recur = new RecurrenceService( $svc, $this->series );
		wp_set_current_user( $ctx['student_id'] );

		$start = new DateTimeImmutable( 'next monday 10:00:00', new DateTimeZone( 'UTC' ) );
		$start = $start->setTimezone( new DateTimeZone( 'UTC' ) );

		// Occupy the second matching Monday.
		$blocker = $start->modify( '+7 days' );
		$blocked = $svc->create(
			array(
				'tutor_id'     => $ctx['tutor_id'],
				'student_id'   => $ctx['student_id'],
				'parent_id'    => null,
				'subject_id'   => $ctx['subject_id'],
				'start_utc'    => $blocker,
				'duration_min' => 60,
				'tutor_tz'     => 'UTC',
				'student_tz'   => 'UTC',
				'price_minor'  => 0,
				'currency'     => 'USD',
				'credit_id'    => null,
				'lock_token'   => null,
				'notes'        => null,
			)
		);
		$this->assertIsInt( $blocked );

		$result = $recur->create_series(
			array(
				'tutor_id'     => $ctx['tutor_id'],
				'student_id'   => $ctx['student_id'],
				'parent_id'    => null,
				'subject_id'   => $ctx['subject_id'],
				'start_utc'    => $start,
				'duration_min' => 60,
				'tutor_tz'     => 'UTC',
				'student_tz'   => 'UTC',
				'price_minor'  => 0,
				'currency'     => 'USD',
				'credit_id'    => null,
				'lock_token'   => null,
				'notes'        => null,
			),
			array( (int) $start->format( 'w' ) ),
			4
		);

		$this->assertIsArray( $result );
		$this->assertCount( 3, $result['booked'] );
		$this->assertCount( 1, $result['skipped'] );

		$lessons = $this->bookings->find_for_series( (int) $result['series_id'] );
		$this->assertNotEmpty( $lessons );
		$this->assertSame( 1, (int) $lessons[0]->series_index );

		// Past lesson stays; future cancel.
		$first = $lessons[0];
		global $wpdb;
		$wpdb->update(
			Schema::table( Schema::BOOKINGS ),
			array( 'start_utc' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ),
			array( 'id' => (int) $first->id )
		);

		$cancelled = $recur->cancel_series( (int) $result['series_id'], true );
		$this->assertGreaterThanOrEqual( 2, $cancelled );

		$fresh = $this->bookings->find( (int) $first->id );
		$this->assertNotSame( 'cancelled', $fresh->status );

		$created = $this->audit_event( 'series.created', (int) $result['series_id'] );
		$this->assertNotNull( $created );
		$this->assertSame( $ctx['student_id'], (int) $created->actor_id );
		$this->assertSame(
			array(
				'booked'   => 3,
				'requested' => 4,
				'skipped'  => 1,
				'tutor_id' => $ctx['tutor_id'],
			),
			json_decode( (string) $created->meta, true )
		);

		$cancel_event = $this->audit_event( 'series.cancelled', (int) $result['series_id'] );
		$this->assertNotNull( $cancel_event );
		$this->assertSame( $cancelled, json_decode( (string) $cancel_event->meta, true )['bookings'] );
	}

	public function test_credit_purchase_refund_on_cancel_and_overdraw(): void {
		$owner = self::factory()->user->create( array( 'role' => Capabilities::ROLE_PARENT ) );
		$pack  = ( new CreditService( $this->credits ) )->purchase(
			array(
				'owner_id'    => $owner,
				'tutor_id'    => null,
				'subject_id'  => null,
				'total'       => 1,
				'price_minor' => 1000,
			)
		);
		$this->assertIsObject( $pack );
		$this->assertSame( 1, (int) $pack->total );

		$ctx   = $this->seed_tutor_week();
		$svc   = $this->booking_service();
		$start = new DateTimeImmutable( '+8 days 10:00:00', new DateTimeZone( 'UTC' ) );

		$id = $svc->create(
			array(
				'tutor_id'       => $ctx['tutor_id'],
				'student_id'     => $owner,
				'parent_id'      => null,
				'subject_id'     => $ctx['subject_id'],
				'start_utc'      => $start,
				'duration_min'   => 60,
				'tutor_tz'       => 'UTC',
				'student_tz'     => 'UTC',
				'price_minor'    => 0,
				'currency'       => 'USD',
				'credit_id'      => (int) $pack->id,
				'consume_credit' => true,
				'lock_token'     => null,
				'notes'          => null,
			)
		);
		$this->assertIsInt( $id );

		$after = $this->credits->find( (int) $pack->id );
		$this->assertSame( 1, (int) $after->used );

		$svc->cancel( $id, 'test' );
		$refunded = $this->credits->find( (int) $pack->id );
		$this->assertSame( 0, (int) $refunded->used );
	}

	public function test_parent_sees_only_confirmed_children(): void {
		$parent  = self::factory()->user->create( array( 'role' => Capabilities::ROLE_PARENT ) );
		$child_a = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$child_b = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );

		$id_a = $this->relations->invite( $parent, $child_a );
		$this->relations->confirm( $id_a );
		$this->relations->invite( $parent, $child_b ); // unconfirmed

		$confirmed = $this->relations->children_of( $parent, true );
		$this->assertCount( 1, $confirmed );
		$this->assertSame( $child_a, (int) $confirmed[0]->student_id );

		$all = $this->relations->children_of( $parent, false );
		$this->assertCount( 2, $all );
	}

	public function test_credit_ledger_uses_scoped_id_placeholders(): void {
		$service = new CreditService( $this->credits );

		AuditLog::record( 'credit.spent', 'credit', 101, array( 'booking' => 11 ) );
		AuditLog::record( 'credit.refunded', 'credit', 202, array( 'booking' => 22 ) );
		AuditLog::record( 'booking.created', 'credit', 101, array( 'booking' => 33 ) );

		$ledger = $service->ledger_for( array( 101 ) );

		$this->assertCount( 1, $ledger );
		$this->assertSame( 101, $ledger[0]['credit_id'] );
		$this->assertSame( 'credit.spent', $ledger[0]['action'] );
		$this->assertSame( array( 'booking' => 11 ), $ledger[0]['meta'] );
	}

	/**
	 * @return array{tutor_id:int, subject_id:int, student_id:int}
	 */
	private function seed_tutor_week(): array {
		$user     = self::factory()->user->create( array( 'role' => Capabilities::ROLE_TUTOR ) );
		$student  = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$tutor_id = $this->tutors->create(
			array(
				'user_id'      => $user,
				'slug'         => 'phase5-tutor-' . $user,
				'display_name' => 'Phase5 Tutor',
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
		$this->availability->replace_week( $tutor_id, $week );

		$subject_id = $this->subjects->create(
			$tutor_id,
			array(
				'name'         => 'Chemistry',
				'duration_min' => 60,
				'price_minor'  => 1000,
				'status'       => 'active',
			)
		);

		return array(
			'tutor_id'   => $tutor_id,
			'subject_id' => $subject_id,
			'student_id' => $student,
		);
	}

	private function booking_service(): BookingService {
		$locks = new LockRepository();

		return new BookingService(
			$this->bookings,
			$locks,
			new SlotEngine( $this->availability, $this->bookings, $locks ),
			new PolicyService( $this->bookings ),
			new Dispatcher(),
			new CreditService( $this->credits ),
			new TransactionManager()
		);
	}

	private function audit_event( string $action, int $object_id ): ?object {
		foreach ( AuditLog::recent( 200 ) as $row ) {
			if ( $action === (string) $row->action && $object_id === (int) $row->object_id ) {
				return $row;
			}
		}

		return null;
	}

	private function empty_tables(): void {
		global $wpdb;

		foreach ( Schema::all_keys() as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( 'DELETE FROM ' . Schema::table( $key ) );
		}
	}
}
