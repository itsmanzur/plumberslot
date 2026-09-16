<?php
/**
 * Recurring maintenance plans: "every Monday and Wednesday at 17:00, twelve
 * times", at a cadence of every week, every 2 weeks, monthly, quarterly, or
 * every 6 months.
 *
 * A common shape for recurring maintenance work -- a quarterly drain check, a
 * biannual water-heater flush -- and one many competitors don't model in
 * their core. A series is a first-class row, so a plan can be reported on,
 * paused or cancelled as a unit while an individual appointment still moves alone.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Domain;

use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Repository\SeriesRepository;
use PlumberSlot\Support\AuditLog;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class RecurrenceService {

	public function __construct(
		private readonly BookingService $bookings,
		private readonly SeriesRepository $series
	) {}

	/**
	 * Create a series and every appointment in it.
	 *
	 * Slots already taken are skipped rather than failing the whole plan; the
	 * caller gets back both lists so the customer can be told exactly which
	 * weeks need a different time.
	 *
	 * @param array<string, mixed> $args           Same shape as BookingService::create().
	 * @param list<int>            $days           Weekdays, 0 = Sunday.
	 * @param int                  $count          How many appointments.
	 * @param int                  $interval_weeks Weeks between qualifying weeks: 1 = every
	 *                                              week (today's only behaviour), 2 =
	 *                                              biweekly, 4 = ~monthly, 13 = ~quarterly,
	 *                                              26 = ~biannual. Week 0 is always the
	 *                                              series' own start week, so it always
	 *                                              qualifies regardless of the interval.
	 * @return array{series_id:int, booked:list<int>, skipped:list<string>}|WP_Error
	 */
	public function create_series( array $args, array $days, int $count, int $interval_weeks = 1 ): array|WP_Error {
		if ( $count < 1 || $count > 104 ) {
			return new WP_Error(
				'plumberslot_bad_count',
				__( 'A recurring plan can run between 1 and 104 appointments.', 'plumberslot' ),
				array( 'status' => 422 )
			);
		}

		$interval_weeks = max( 1, $interval_weeks );

		$series_id = $this->series->create(
			array(
				'technician_id'  => (int) $args['technician_id'],
				'customer_id'    => (int) $args['customer_id'],
				'rrule'          => $this->to_rrule( $days, $count, $interval_weeks ),
				'total_count'    => $count,
				'interval_weeks' => $interval_weeks,
			)
		);

		$booked       = array();
		$skipped      = array();
		$series_start = $args['start_utc'];
		$cursor       = $series_start;
		$index        = 0;
		$processed    = 0;

		while ( $processed < $count ) {
			// Whole weeks between the series' own start date and this cursor
			// date, not a running day-count -- so the answer is correct no
			// matter which weekdays are selected. Week 0 (the start week)
			// always qualifies; interval_weeks = 1 makes every week qualify,
			// reproducing the original always-weekly loop exactly.
			$weeks_since_start = (int) floor( ( $cursor->getTimestamp() - $series_start->getTimestamp() ) / WEEK_IN_SECONDS );

			if ( in_array( (int) $cursor->format( 'w' ), $days, true )
				&& 0 === $weeks_since_start % $interval_weeks ) {
				++$index;
				++$processed;

				$result = $this->bookings->create(
					array_merge(
						$args,
						array(
							'start_utc'    => $cursor,
							'series_id'    => $series_id,
							'series_index' => $index,
							'lock_token'   => null,
						)
					)
				);

				if ( is_wp_error( $result ) ) {
					$skipped[] = $cursor->format( DATE_ATOM );
				} else {
					$booked[] = $result;
				}
			}

			$cursor = $cursor->modify( '+1 day' );

			if ( $cursor->getTimestamp() > $series_start->getTimestamp() + ( 2 * YEAR_IN_SECONDS * max( 1, $interval_weeks ) ) ) {
				break; // Guard against an unsatisfiable rule looping forever.
			}
		}

		AuditLog::record(
			'series.created',
			'series',
			$series_id,
			array(
				'booked'        => count( $booked ),
				'requested'     => $count,
				'skipped'       => count( $skipped ),
				'technician_id' => (int) $args['technician_id'],
			)
		);

		return array(
			'series_id' => $series_id,
			'booked'    => $booked,
			'skipped'   => $skipped,
		);
	}

	public function cancel_series( int $series_id, bool $future_only = true ): int {
		$repo  = new BookingRepository();
		$count = 0;

		foreach ( $repo->find_for_series( $series_id ) as $booking ) {
			if ( $future_only && strtotime( $booking->start_utc ) < time() ) {
				continue;
			}

			$this->bookings->cancel( (int) $booking->id, 'series_cancelled' );
			++$count;
		}

		AuditLog::record(
			'series.cancelled',
			'series',
			$series_id,
			array(
				'bookings'    => $count,
				'future_only' => $future_only,
			)
		);

		return $count;
	}

	/**
	 * @param list<int> $days Weekdays, 0 = Sunday.
	 */
	private function to_rrule( array $days, int $count, int $interval_weeks = 1 ): string {
		$map   = array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' );
		$names = array_map( static fn ( int $d ): string => $map[ $d ], $days );

		// Omitting INTERVAL entirely for the weekly (1) case keeps the stored
		// label identical to every series created before this cadence concept
		// existed -- it is a display string, never read back by the loop above.
		$interval = $interval_weeks > 1 ? sprintf( ';INTERVAL=%d', $interval_weeks ) : '';

		return sprintf( 'FREQ=WEEKLY%s;BYDAY=%s;COUNT=%d', $interval, implode( ',', $names ), $count );
	}
}
