<?php
/**
 * Turns availability rules into bookable slots.
 *
 * Slots are never stored. A year of fifteen-minute slots would be roughly
 * thirty-five thousand rows per technician, all of which go stale the moment a rule
 * changes. Instead the rules are stored and the slots are computed per request
 * and cached, keyed by technician and month.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PlumberSlot\Domain\Contract\AvailabilitySource;
use PlumberSlot\Domain\Contract\BookingOccupancySource;
use PlumberSlot\Domain\Contract\HoldOccupancySource;
use PlumberSlot\Domain\Entity\Slot;
use PlumberSlot\Support\Cache;
use PlumberSlot\Support\Settings;
use PlumberSlot\Support\Time;

defined( 'ABSPATH' ) || exit;

final class SlotEngine {

	/**
	 * Widest window a single slots_for() call may cover.
	 */
	public const MAX_RANGE_DAYS = 62;

	public function __construct(
		private readonly AvailabilitySource $availability,
		private readonly BookingOccupancySource $bookings,
		private readonly HoldOccupancySource $locks
	) {}

	/**
	 * Every slot in a window, each already marked open, booked or held.
	 *
	 * @param int               $technician_id      Technician row id.
	 * @param DateTimeImmutable $from_utc      Window start, UTC.
	 * @param DateTimeImmutable $to_utc        Window end, UTC.
	 * @param string            $technician_tz      Technician's IANA zone.
	 * @param int               $duration_min  Lesson length.
	 * @return list<Slot>
	 * @throws \InvalidArgumentException When the window is empty or too wide.
	 */
	public function slots_for(
		int $technician_id,
		DateTimeImmutable $from_utc,
		DateTimeImmutable $to_utc,
		string $technician_tz,
		int $duration_min,
		int $exclude_booking_id = 0
	): array {
		self::assert_valid_range( $from_utc, $to_utc );

		if ( $exclude_booking_id <= 0 ) {
			$key    = Cache::slot_key( $technician_id, $from_utc, $to_utc, $duration_min );
			$cached = Cache::get( $key );

			if ( is_array( $cached ) ) {
				/** @var list<Slot> $cached */
				return $cached;
			}
		}

		$candidates = $this->candidates( $technician_id, $from_utc, $to_utc, $technician_tz, $duration_min );
		$slots      = $this->apply_occupancy( $technician_id, $candidates, $from_utc, $to_utc, $duration_min, $exclude_booking_id );

		if ( $exclude_booking_id <= 0 ) {
			Cache::set( $key, $slots, Settings::int( 'slot_cache_ttl', 900 ) );
		}

		return $slots;
	}

	/**
	 * Reject inverted or excessively wide windows before any work runs.
	 *
	 * @throws \InvalidArgumentException When the window is empty or too wide.
	 */
	public static function assert_valid_range( DateTimeImmutable $from_utc, DateTimeImmutable $to_utc ): void {
		if ( $to_utc <= $from_utc ) {
			throw new \InvalidArgumentException( 'Slot window end must be after its start.' );
		}

		$max_seconds = self::MAX_RANGE_DAYS * DAY_IN_SECONDS;

		if ( $to_utc->getTimestamp() - $from_utc->getTimestamp() > $max_seconds ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception text is not rendered as HTML.
			throw new \InvalidArgumentException(
				sprintf( 'Slot window may not exceed %d days.', self::MAX_RANGE_DAYS )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Expand the weekly rules across the window, minus closures.
	 *
	 * All arithmetic happens in the technician's own zone and is converted to UTC at
	 * the very end. Doing it the other way round breaks twice a year, on the
	 * days a DST transition moves the wall clock under you.
	 *
	 * @return list<DateTimeImmutable> Slot start times, UTC.
	 */
	private function candidates(
		int $technician_id,
		DateTimeImmutable $from_utc,
		DateTimeImmutable $to_utc,
		string $technician_tz,
		int $duration_min
	): array {
		$zone        = new DateTimeZone( $technician_tz );
		$rules       = $this->availability->rules_for( $technician_id );
		$granularity = Settings::int( 'slot_granularity_minutes', 30 );
		$buffer      = Settings::int( 'buffer_minutes', 10 );
		$lead        = Settings::int( 'lead_time_minutes', 240 );
		$earliest    = new DateTimeImmutable( '@' . ( time() + ( $lead * MINUTE_IN_SECONDS ) ) );

		$exceptions = $this->index_exceptions(
			$this->availability->exceptions_between(
				$technician_id,
				$from_utc->setTimezone( $zone )->format( 'Y-m-d' ),
				$to_utc->setTimezone( $zone )->format( 'Y-m-d' )
			)
		);

		$out    = array();
		$cursor = $from_utc->setTimezone( $zone )->setTime( 0, 0 );
		$last   = $to_utc->setTimezone( $zone );

		while ( $cursor <= $last ) {
			$date    = $cursor->format( 'Y-m-d' );
			$weekday = (int) $cursor->format( 'w' );

			if ( isset( $exceptions['closed'][ $date ] ) ) {
				$cursor = $cursor->modify( '+1 day' )->setTime( 0, 0 );
				continue;
			}

			$windows = $this->windows_for_day( $rules, $weekday, $date );

			foreach ( $exceptions['open'][ $date ] ?? array() as $extra ) {
				$windows[] = $extra;
			}

			foreach ( $windows as $window ) {
				// Step by at least the lesson length so candidates never overlap;
				// granularity still wins when it is longer than the lesson.
				$step = max( $granularity, $duration_min ) + $buffer;

				for ( $min = $window['start']; $min + $duration_min <= $window['end']; $min += $step ) {
					$local = $cursor->setTime( intdiv( $min, 60 ), $min % 60 );
					$utc   = $local->setTimezone( new DateTimeZone( 'UTC' ) );

					if ( $utc < $earliest || $utc < $from_utc || $utc > $to_utc ) {
						continue;
					}

					$out[ $utc->format( 'Y-m-d H:i:s' ) ] = $utc;
				}
			}

			$cursor = $cursor->modify( '+1 day' )->setTime( 0, 0 );
		}

		ksort( $out );

		return array_values( $out );
	}

	/**
	 * Weekly rules that apply on a given date.
	 *
	 * @param list<object> $rules   Availability rows.
	 * @param int          $weekday 0 = Sunday.
	 * @param string       $date    Y-m-d in the technician's zone.
	 * @return list<array{start:int,end:int}>
	 */
	private function windows_for_day( array $rules, int $weekday, string $date ): array {
		$out = array();

		foreach ( $rules as $rule ) {
			if ( (int) $rule->weekday !== $weekday ) {
				continue;
			}
			if ( $rule->valid_from && $date < $rule->valid_from ) {
				continue;
			}
			if ( $rule->valid_to && $date > $rule->valid_to ) {
				continue;
			}

			$out[] = array(
				'start' => (int) $rule->start_min,
				'end'   => (int) $rule->end_min,
			);
		}

		return $out;
	}

	/**
	 * @param list<object> $rows Exception rows.
	 * @return array{closed: array<string, true>, open: array<string, list<array{start:int,end:int}>>}
	 */
	private function index_exceptions( array $rows ): array {
		$index = array(
			'closed' => array(),
			'open'   => array(),
		);

		foreach ( $rows as $row ) {
			if ( 'closed' === $row->kind ) {
				$index['closed'][ $row->on_date ] = true;
				continue;
			}

			$index['open'][ $row->on_date ][] = array(
				'start' => (int) $row->start_min,
				'end'   => (int) $row->end_min,
			);
		}

		return $index;
	}

	/**
	 * Mark each candidate against confirmed bookings and live payment holds.
	 *
	 * @param list<DateTimeImmutable> $candidates          Slot starts, UTC.
	 * @param int                      $exclude_booking_id  Booking to ignore.
	 * @return list<Slot>
	 */
	private function apply_occupancy(
		int $technician_id,
		array $candidates,
		DateTimeImmutable $from_utc,
		DateTimeImmutable $to_utc,
		int $duration_min,
		int $exclude_booking_id = 0
	): array {
		$duration = $duration_min * MINUTE_IN_SECONDS;
		$bookings = $this->bookings->find_in_range(
			$technician_id,
			Time::sql( $from_utc ),
			Time::sql( $to_utc->modify( '+' . $duration_min . ' minutes' ) )
		);

		if ( $exclude_booking_id > 0 ) {
			$bookings = array_values(
				array_filter(
					$bookings,
					static fn ( object $booking ): bool => (int) $booking->id !== $exclude_booking_id
				)
			);
		}

		$holds = $this->locks->held_in_range(
			$technician_id,
			Time::sql( $from_utc->modify( '-' . $duration_min . ' minutes' ) ),
			Time::sql( $to_utc )
		);

		$slots = array();

		foreach ( $candidates as $start ) {
			$candidate_start = $start->getTimestamp();
			$candidate_end   = $candidate_start + $duration;
			$is_booked       = false;
			$is_held         = false;

			foreach ( $bookings as $booking ) {
				$booking_start = strtotime( (string) $booking->start_utc );
				$booking_end   = strtotime( (string) $booking->end_utc );

				if ( $booking_start < $candidate_end && $booking_end > $candidate_start ) {
					$is_booked = true;
					break;
				}
			}

			foreach ( $holds as $held_start ) {
				$hold_start = strtotime( $held_start . ' UTC' );
				$hold_end   = $hold_start + $duration;

				if ( $hold_start < $candidate_end && $hold_end > $candidate_start ) {
					$is_held = true;
					break;
				}
			}

			$state = match ( true ) {
				$is_booked => Slot::STATE_BOOKED,
				$is_held   => Slot::STATE_HELD,
				default    => Slot::STATE_OPEN,
			};

			$slots[] = new Slot( $start, $state );
		}

		/**
		 * Filter the computed slot list.
		 *
		 * Integrations use this to subtract busy events pulled from an external
		 * calendar without touching the engine.
		 *
		 * @param list<Slot> $slots    Computed slots.
		 * @param int        $technician_id Technician row id.
		 */
		return apply_filters( 'plumberslot_slots', $slots, $technician_id );
	}

	/**
	 * Is this exact start time still open? Called immediately before insert.
	 *
	 * @param int $exclude_booking_id Booking to ignore while checking occupancy (reschedule).
	 */
	public function is_open(
		int $technician_id,
		DateTimeImmutable $start_utc,
		string $technician_tz,
		int $duration_min,
		int $exclude_booking_id = 0
	): bool {
		if ( $exclude_booking_id > 0 ) {
			self::assert_valid_range( $start_utc, $start_utc->modify( '+1 second' ) );
			$candidates = $this->candidates( $technician_id, $start_utc, $start_utc->modify( '+1 second' ), $technician_tz, $duration_min );
			$slots      = $this->apply_occupancy( $technician_id, $candidates, $start_utc, $start_utc->modify( '+1 second' ), $duration_min, $exclude_booking_id );
		} else {
			$slots = $this->slots_for( $technician_id, $start_utc, $start_utc->modify( '+1 second' ), $technician_tz, $duration_min );
		}

		foreach ( $slots as $slot ) {
			if ( $slot->start->getTimestamp() === $start_utc->getTimestamp() ) {
				return Slot::STATE_OPEN === $slot->state;
			}
		}

		return false;
	}
}
