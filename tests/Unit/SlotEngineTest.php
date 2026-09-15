<?php
/**
 * Slot engine tests.
 *
 * The two cases worth writing first are the two that quietly break every
 * booking plugin: a DST transition, and a lesson that would start inside the
 * lead-time window.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TutorSlot\Domain\Contract\AvailabilitySource;
use TutorSlot\Domain\Contract\BookingOccupancySource;
use TutorSlot\Domain\Contract\HoldOccupancySource;
use TutorSlot\Domain\Entity\Slot;
use TutorSlot\Domain\SlotEngine;
use TutorSlot\Support\Cache;
use TutorSlot\Support\Settings;

final class SlotEngineTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['tutorslot_test_cache'] = array();
		$this->settings();
	}

	/**
	 * A tutor in London who teaches 09:00–12:00 still teaches 09:00–12:00 on
	 * either side of a DST change. If the engine did its arithmetic in UTC the
	 * lesson would silently move an hour.
	 */
	public function test_local_hours_survive_a_dst_transition(): void {
		$zone         = new DateTimeZone( 'Europe/London' );
		$before_local = new DateTimeImmutable( '2026-10-23 09:00', $zone ); // Friday, still BST.
		$after_local  = new DateTimeImmutable( '2026-10-30 09:00', $zone ); // Friday, back on GMT.

		self::assertSame( '09:00', $before_local->format( 'H:i' ) );
		self::assertSame( '09:00', $after_local->format( 'H:i' ) );
		self::assertSame( '08:00', $before_local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'H:i' ) );
		self::assertSame( '09:00', $after_local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'H:i' ) );

		$rule = (object) array(
			'weekday'    => 5,
			'start_min'  => 540,
			'end_min'    => 720,
			'valid_from' => null,
			'valid_to'   => null,
		);

		$this->settings(
			array(
				'lead_time_minutes'        => 0,
				'slot_granularity_minutes' => 60,
				'buffer_minutes'           => 0,
			)
		);

		$slots = $this->engine( array( $rule ) )->slots_for(
			1,
			new DateTimeImmutable( '2026-10-23 00:00:00', new DateTimeZone( 'UTC' ) ),
			new DateTimeImmutable( '2026-10-30 23:00:00', new DateTimeZone( 'UTC' ) ),
			'Europe/London',
			60
		);

		$starts = array_map(
			static fn ( Slot $slot ): string => $slot->start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			$slots
		);

		// Local 09:00 before the clocks fall back is 08:00 UTC; after, it is 09:00 UTC.
		self::assertContains( '2026-10-23 08:00:00', $starts );
		self::assertContains( '2026-10-30 09:00:00', $starts );

		$nine_am = array_values(
			array_filter(
				$slots,
				static function ( Slot $slot ) use ( $zone ): bool {
					return '09:00' === $slot->start->setTimezone( $zone )->format( 'H:i' );
				}
			)
		);

		self::assertCount( 2, $nine_am );
		self::assertSame( '2026-10-23 08:00:00', $nine_am[0]->start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) );
		self::assertSame( '2026-10-30 09:00:00', $nine_am[1]->start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) );
		self::assertSame( Slot::STATE_OPEN, $nine_am[0]->state );
		self::assertSame( Slot::STATE_OPEN, $nine_am[1]->state );
	}

	public function test_slots_inside_the_lead_time_are_not_offered(): void {
		$zone    = new DateTimeZone( 'UTC' );
		$inside  = ( new DateTimeImmutable( 'now', $zone ) )->modify( '+2 hours' );
		$inside  = $inside->setTime( (int) $inside->format( 'H' ), (int) $inside->format( 'i' ), 0 );
		$outside = $inside->modify( '+4 hours' );
		$rules   = array(
			$this->weekly_rule( $inside, 30 ),
			$this->weekly_rule( $outside, 30 ),
		);

		$this->settings( array( 'lead_time_minutes' => 240 ) );
		$slots = $this->engine( $rules )->slots_for( 7, $inside, $outside, 'UTC', 30 );

		self::assertCount( 1, $slots );
		self::assertSame( $outside->getTimestamp(), $slots[0]->start->getTimestamp() );
		self::assertSame( Slot::STATE_OPEN, $slots[0]->state );
	}

	public function test_a_booked_slot_is_marked_booked_not_omitted(): void {
		// Students trust a greyed-out slot more than a missing one.
		$start    = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '+7 days' )->setTime( 10, 0 );
		$booking  = (object) array(
			'start_utc' => $start->format( 'Y-m-d H:i:s' ),
			'end_utc'   => $start->modify( '+60 minutes' )->format( 'Y-m-d H:i:s' ),
		);
		$slots    = $this->engine(
			array( $this->weekly_rule( $start, 60 ) ),
			array(),
			array( $booking )
		)->slots_for( 8, $start, $start->modify( '+1 second' ), 'UTC', 60 );

		self::assertCount( 1, $slots );
		self::assertSame( $start->getTimestamp(), $slots[0]->start->getTimestamp() );
		self::assertSame( Slot::STATE_BOOKED, $slots[0]->state );
		self::assertFalse( $slots[0]->is_open() );
	}

	public function test_a_booking_occupies_every_candidate_that_overlaps_its_range(): void {
		$start   = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '+9 days' )->setTime( 10, 0 );
		$booking = (object) array(
			'start_utc' => $start->modify( '+30 minutes' )->format( 'Y-m-d H:i:s' ),
			'end_utc'   => $start->modify( '+90 minutes' )->format( 'Y-m-d H:i:s' ),
		);
		$slots   = $this->engine(
			array( $this->weekly_rule( $start, 180 ) ),
			array(),
			array( $booking )
		)->slots_for( 10, $start, $start->modify( '+60 minutes' ), 'UTC', 60 );

		self::assertCount( 2, $slots );
		self::assertSame(
			array( Slot::STATE_BOOKED, Slot::STATE_BOOKED ),
			array_column( $slots, 'state' )
		);
	}

	public function test_a_hold_occupies_every_candidate_that_overlaps_its_range(): void {
		$start = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '+10 days' )->setTime( 10, 0 );
		$slots = $this->engine(
			array( $this->weekly_rule( $start, 180 ) ),
			array(),
			array(),
			array( $start->modify( '+30 minutes' )->format( 'Y-m-d H:i:s' ) )
		)->slots_for( 11, $start, $start->modify( '+60 minutes' ), 'UTC', 60 );

		self::assertCount( 2, $slots );
		self::assertSame(
			array( Slot::STATE_HELD, Slot::STATE_HELD ),
			array_column( $slots, 'state' )
		);
	}

	public function test_a_closure_exception_beats_a_weekly_rule(): void {
		$start     = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '+8 days' )->setTime( 9, 0 );
		$exception = (object) array(
			'on_date'   => $start->format( 'Y-m-d' ),
			'kind'      => 'closed',
			'start_min' => null,
			'end_min'   => null,
		);
		$slots     = $this->engine(
			array( $this->weekly_rule( $start, 60 ) ),
			array( $exception )
		)->slots_for( 9, $start, $start->modify( '+1 second' ), 'UTC', 60 );

		self::assertSame( array(), $slots );
	}

	public function test_an_extra_open_exception_adds_slots_beyond_weekly_rules(): void {
		$morning   = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '+12 days' )->setTime( 9, 0 );
		$afternoon = $morning->setTime( 14, 0 );
		$exception = (object) array(
			'on_date'   => $afternoon->format( 'Y-m-d' ),
			'kind'      => 'open',
			'start_min' => 14 * 60,
			'end_min'   => 15 * 60,
		);

		$this->settings(
			array(
				'lead_time_minutes'        => 0,
				'slot_granularity_minutes' => 60,
				'buffer_minutes'           => 0,
			)
		);

		$slots = $this->engine(
			array( $this->weekly_rule( $morning, 60 ) ),
			array( $exception )
		)->slots_for( 12, $morning, $afternoon->modify( '+1 second' ), 'UTC', 60 );

		$starts = array_map(
			static fn ( Slot $slot ): int => $slot->start->getTimestamp(),
			$slots
		);

		self::assertContains( $morning->getTimestamp(), $starts );
		self::assertContains( $afternoon->getTimestamp(), $starts );
		self::assertSame(
			array( Slot::STATE_OPEN, Slot::STATE_OPEN ),
			array_column( $slots, 'state' )
		);
	}

	public function test_an_extra_open_exception_works_on_a_day_without_weekly_rules(): void {
		$start     = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '+13 days' )->setTime( 16, 0 );
		$exception = (object) array(
			'on_date'   => $start->format( 'Y-m-d' ),
			'kind'      => 'open',
			'start_min' => 16 * 60,
			'end_min'   => 17 * 60,
		);

		// Weekly rule is for a different weekday, so only the exception opens this day.
		$other_day = $start->modify( '+1 day' )->setTime( 9, 0 );
		$slots     = $this->engine(
			array( $this->weekly_rule( $other_day, 60 ) ),
			array( $exception )
		)->slots_for( 13, $start, $start->modify( '+1 second' ), 'UTC', 60 );

		self::assertCount( 1, $slots );
		self::assertSame( $start->getTimestamp(), $slots[0]->start->getTimestamp() );
		self::assertSame( Slot::STATE_OPEN, $slots[0]->state );
	}

	public function test_slot_starts_step_by_granularity_plus_buffer(): void {
		// 09:00–12:00 window, 60-minute lessons, 30-minute grid, 10-minute buffer
		// → step is max(30, 60) + 10 = 70 so lessons never overlap.
		$day = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '+14 days' )->setTime( 9, 0 );

		$this->settings(
			array(
				'lead_time_minutes'        => 0,
				'slot_granularity_minutes' => 30,
				'buffer_minutes'           => 10,
			)
		);

		$slots = $this->engine(
			array( $this->weekly_rule( $day, 180 ) )
		)->slots_for( 14, $day, $day->setTime( 12, 0 ), 'UTC', 60 );

		$starts = array_map(
			static fn ( Slot $slot ): string => $slot->start->format( 'H:i' ),
			$slots
		);

		self::assertSame( array( '09:00', '10:10' ), $starts );
		self::assertNotContains( '11:20', $starts );
	}

	public function test_lesson_duration_blocks_starts_that_would_overrun_the_window(): void {
		$day = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '+15 days' )->setTime( 9, 0 );

		$this->settings(
			array(
				'lead_time_minutes'        => 0,
				'slot_granularity_minutes' => 30,
				'buffer_minutes'           => 10,
			)
		);

		$slots = $this->engine(
			array( $this->weekly_rule( $day, 180 ) )
		)->slots_for( 15, $day, $day->setTime( 12, 0 ), 'UTC', 90 );

		$starts = array_map(
			static fn ( Slot $slot ): string => $slot->start->format( 'H:i' ),
			$slots
		);

		// step = max(30, 90) + 10 = 100 → 09:00 fits, 10:40 + 90m overruns 12:00.
		self::assertSame( array( '09:00' ), $starts );
		self::assertNotContains( '10:40', $starts );
	}

	public function test_candidate_slots_do_not_overlap(): void {
		$day = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '+16 days' )->setTime( 9, 0 );

		$this->settings(
			array(
				'lead_time_minutes'        => 0,
				'slot_granularity_minutes' => 15,
				'buffer_minutes'           => 5,
			)
		);

		$duration = 45;
		$slots    = $this->engine(
			array( $this->weekly_rule( $day, 240 ) )
		)->slots_for( 16, $day, $day->setTime( 13, 0 ), 'UTC', $duration );

		self::assertGreaterThan( 1, count( $slots ) );

		$previous_end = 0;

		foreach ( $slots as $slot ) {
			$start = $slot->start->getTimestamp();
			$end   = $start + ( $duration * MINUTE_IN_SECONDS );

			self::assertGreaterThanOrEqual( $previous_end, $start );
			$previous_end = $end + ( 5 * MINUTE_IN_SECONDS );
		}
	}

	public function test_slot_cache_misses_once_then_hits(): void {
		$day  = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '+17 days' )->setTime( 9, 0 );
		$rule = $this->weekly_rule( $day, 60 );

		$availability = new class( array( $rule ) ) implements AvailabilitySource {
			public int $rules_calls = 0;

			/**
			 * @param list<object> $rules Weekly rules.
			 */
			public function __construct( private readonly array $rules ) {}

			public function rules_for( int $tutor_id ): array {
				++$this->rules_calls;

				return $this->rules;
			}

			public function exceptions_between( int $tutor_id, string $from_date, string $to_date ): array {
				return array();
			}
		};

		$bookings = new class implements BookingOccupancySource {
			public function find_in_range( int $tutor_id, string $from_utc, string $to_utc ): array {
				return array();
			}
		};

		$holds = new class implements HoldOccupancySource {
			public function held_in_range( int $tutor_id, string $from_utc, string $to_utc ): array {
				return array();
			}
		};

		$engine = new SlotEngine( $availability, $bookings, $holds );
		$from   = $day;
		$to     = $day->modify( '+1 second' );

		$first  = $engine->slots_for( 17, $from, $to, 'UTC', 60 );
		$second = $engine->slots_for( 17, $from, $to, 'UTC', 60 );

		self::assertSame( 1, $availability->rules_calls );
		self::assertCount( 1, $first );
		self::assertSame( $first, $second );
		self::assertSame( Slot::STATE_OPEN, $first[0]->state );
	}

	public function test_availability_or_booking_change_invalidates_slot_cache(): void {
		$day  = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '+18 days' )->setTime( 9, 0 );
		$rule = $this->weekly_rule( $day, 60 );

		$availability = new class( array( $rule ) ) implements AvailabilitySource {
			public int $rules_calls = 0;

			/** @var list<object> */
			public array $rules;

			/**
			 * @param list<object> $rules Weekly rules.
			 */
			public function __construct( array $rules ) {
				$this->rules = $rules;
			}

			public function rules_for( int $tutor_id ): array {
				++$this->rules_calls;

				return $this->rules;
			}

			public function exceptions_between( int $tutor_id, string $from_date, string $to_date ): array {
				return array();
			}
		};

		$bookings = new class implements BookingOccupancySource {
			public function find_in_range( int $tutor_id, string $from_utc, string $to_utc ): array {
				return array();
			}
		};

		$holds = new class implements HoldOccupancySource {
			public function held_in_range( int $tutor_id, string $from_utc, string $to_utc ): array {
				return array();
			}
		};

		$engine = new SlotEngine( $availability, $bookings, $holds );
		$from   = $day;
		$to     = $day->modify( '+1 second' );
		$tutor  = 18;

		$before = Cache::generation( $tutor );

		$engine->slots_for( $tutor, $from, $to, 'UTC', 60 );
		$engine->slots_for( $tutor, $from, $to, 'UTC', 60 );

		self::assertSame( 1, $availability->rules_calls );

		// Availability replace and booking write both call this.
		Cache::forget_tutor( $tutor );

		self::assertSame( $before + 1, Cache::generation( $tutor ) );

		// Stale rules removed; recompute must see the closed day.
		$availability->rules = array();

		$after = $engine->slots_for( $tutor, $from, $to, 'UTC', 60 );

		self::assertSame( 2, $availability->rules_calls );
		self::assertSame( array(), $after );
	}

	public function test_inverted_date_range_is_rejected(): void {
		$from = new DateTimeImmutable( '2026-11-01 00:00:00', new DateTimeZone( 'UTC' ) );
		$to   = $from->modify( '-1 day' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Slot window end must be after its start.' );

		$this->engine( array() )->slots_for( 19, $from, $to, 'UTC', 60 );
	}

	public function test_oversized_date_range_is_rejected(): void {
		$from = new DateTimeImmutable( '2026-11-01 00:00:00', new DateTimeZone( 'UTC' ) );
		$to   = $from->modify( '+' . ( SlotEngine::MAX_RANGE_DAYS + 1 ) . ' days' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Slot window may not exceed ' . SlotEngine::MAX_RANGE_DAYS . ' days.' );

		$this->engine( array() )->slots_for( 20, $from, $to, 'UTC', 60 );
	}

	public function test_maximum_allowed_date_range_is_accepted(): void {
		$day  = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '+19 days' )->setTime( 9, 0 );
		$from = $day;
		$to   = $from->modify( '+' . SlotEngine::MAX_RANGE_DAYS . ' days' );

		$slots = $this->engine(
			array( $this->weekly_rule( $day, 60 ) )
		)->slots_for( 21, $from, $to, 'UTC', 60 );

		self::assertNotEmpty( $slots );
		self::assertSame( Slot::STATE_OPEN, $slots[0]->state );
	}

	/**
	 * @param array<string, int|bool> $overrides Settings to replace.
	 */
	private function settings( array $overrides = array() ): void {
		$GLOBALS['tutorslot_test_settings'] = array_merge(
			array(
				'lead_time_minutes'        => 0,
				'slot_granularity_minutes' => 30,
				'buffer_minutes'           => 0,
				'slot_cache_ttl'           => 60,
			),
			$overrides
		);

		$reflection = new ReflectionClass( Settings::class );
		$property   = $reflection->getProperty( 'cache' );
		$property->setValue( null, null );
	}

	private function weekly_rule( DateTimeImmutable $start, int $duration ): object {
		$start_minute = ( (int) $start->format( 'H' ) * 60 ) + (int) $start->format( 'i' );

		return (object) array(
			'weekday'    => (int) $start->format( 'w' ),
			'start_min'  => $start_minute,
			'end_min'    => $start_minute + $duration,
			'valid_from' => null,
			'valid_to'   => null,
		);
	}

	/**
	 * @param list<object> $rules      Weekly rules.
	 * @param list<object> $exceptions Date exceptions.
	 * @param list<object> $bookings   Existing bookings.
	 * @param list<string> $holds      Live hold start times.
	 */
	private function engine(
		array $rules,
		array $exceptions = array(),
		array $bookings = array(),
		array $holds = array()
	): SlotEngine {
		$availability = new class( $rules, $exceptions ) implements AvailabilitySource {
			/**
			 * @param list<object> $rules      Weekly rules.
			 * @param list<object> $exceptions Date exceptions.
			 */
			public function __construct(
				private readonly array $rules,
				private readonly array $exceptions
			) {}

			public function rules_for( int $tutor_id ): array {
				return $this->rules;
			}

			public function exceptions_between( int $tutor_id, string $from_date, string $to_date ): array {
				return $this->exceptions;
			}
		};

		$booking_source = new class( $bookings ) implements BookingOccupancySource {
			/**
			 * @param list<object> $bookings Existing bookings.
			 */
			public function __construct( private readonly array $bookings ) {}

			public function find_in_range( int $tutor_id, string $from_utc, string $to_utc ): array {
				return $this->bookings;
			}
		};

		$hold_source = new class( $holds ) implements HoldOccupancySource {
			/**
			 * @param list<string> $holds Live hold start times.
			 */
			public function __construct( private readonly array $holds ) {}

			public function held_in_range( int $tutor_id, string $from_utc, string $to_utc ): array {
				return $this->holds;
			}
		};

		return new SlotEngine( $availability, $booking_source, $hold_source );
	}
}
