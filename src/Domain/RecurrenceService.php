<?php
/**
 * Weekly courses: "every Monday and Wednesday at 17:00, twelve times".
 *
 * A common shape for recurring maintenance work, and one many competitors
 * don't model in their core. A series is a first-class row, so a course can be reported on,
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
	 * Slots already taken are skipped rather than failing the whole course; the
	 * caller gets back both lists so the customer can be told exactly which
	 * weeks need a different time.
	 *
	 * @param array<string, mixed> $args   Same shape as BookingService::create().
	 * @param list<int>            $days   Weekdays, 0 = Sunday.
	 * @param int                  $count  How many appointments.
	 * @return array{series_id:int, booked:list<int>, skipped:list<string>}|WP_Error
	 */
	public function create_series( array $args, array $days, int $count ): array|WP_Error {
		if ( $count < 1 || $count > 104 ) {
			return new WP_Error(
				'plumberslot_bad_count',
				__( 'A course can run between 1 and 104 appointments.', 'plumberslot' ),
				array( 'status' => 422 )
			);
		}

		$series_id = $this->series->create(
			array(
				'technician_id' => (int) $args['technician_id'],
				'customer_id'   => (int) $args['customer_id'],
				'rrule'         => $this->to_rrule( $days, $count ),
				'total_count'   => $count,
			)
		);

		$booked    = array();
		$skipped   = array();
		$cursor    = $args['start_utc'];
		$index     = 0;
		$processed = 0;

		while ( $processed < $count ) {
			if ( in_array( (int) $cursor->format( 'w' ), $days, true ) ) {
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

			if ( $cursor->getTimestamp() > $args['start_utc']->getTimestamp() + ( 2 * YEAR_IN_SECONDS ) ) {
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
	private function to_rrule( array $days, int $count ): string {
		$map   = array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' );
		$names = array_map( static fn ( int $d ): string => $map[ $d ], $days );

		return sprintf( 'FREQ=WEEKLY;BYDAY=%s;COUNT=%d', implode( ',', $names ), $count );
	}
}
