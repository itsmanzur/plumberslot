<?php
/**
 * Validation callbacks used by REST argument schemas.
 *
 * Validation belongs in the route definition, so a malformed request is
 * rejected by WordPress before a single line of business logic runs.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Support;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Validate {

	public static function is_iso8601( mixed $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}

		try {
			Time::from_iso( $value );

			return true;
		} catch ( \InvalidArgumentException $e ) {
			return false;
		}
	}

	public static function is_timezone( mixed $value ): bool {
		return is_string( $value ) && Time::is_valid_zone( $value );
	}

	public static function is_date( mixed $value ): bool {
		return is_string( $value ) && (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
	}

	/**
	 * Weekday list: unique integers 0-6.
	 */
	public static function is_weekday_list( mixed $value ): bool {
		if ( ! is_array( $value ) || array() === $value || count( $value ) > 7 ) {
			return false;
		}

		foreach ( $value as $day ) {
			if ( ! is_int( $day ) || $day < 0 || $day > 6 ) {
				return false;
			}
		}

		return count( array_unique( $value ) ) === count( $value );
	}

	/**
	 * Sanitise the week grid the availability editor posts back.
	 *
	 * Invalid weekday/minutes and same-day overlaps are rejected, not skipped,
	 * so a partial week can never be saved by accident.
	 *
	 * @param mixed $value Raw request value.
	 * @return list<array{weekday:int,start_min:int,end_min:int}>|WP_Error
	 */
	public static function sanitize_week( mixed $value ): array|WP_Error {
		if ( ! is_array( $value ) ) {
			return new WP_Error(
				'plumberslot_invalid_week',
				__( 'Availability must be sent as a list of weekday blocks.', 'plumberslot' ),
				array( 'status' => 400 )
			);
		}

		$out = array();

		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				return self::invalid_week_block();
			}

			if ( ! isset( $row['weekday'], $row['start_min'], $row['end_min'] ) ) {
				return self::invalid_week_block();
			}

			if ( ! self::is_whole_number( $row['weekday'] )
				|| ! self::is_whole_number( $row['start_min'] )
				|| ! self::is_whole_number( $row['end_min'] ) ) {
				return self::invalid_week_block();
			}

			$weekday = (int) $row['weekday'];
			$start   = (int) $row['start_min'];
			$end     = (int) $row['end_min'];

			if ( $weekday < 0 || $weekday > 6 || $start < 0 || $end > 1440 || $start >= $end ) {
				return self::invalid_week_block();
			}

			$out[] = array(
				'weekday'   => $weekday,
				'start_min' => $start,
				'end_min'   => $end,
			);
		}

		if ( self::week_has_overlap( $out ) ) {
			return new WP_Error(
				'plumberslot_overlapping_week',
				__( 'Availability blocks on the same day cannot overlap.', 'plumberslot' ),
				array( 'status' => 400 )
			);
		}

		return $out;
	}

	/**
	 * @param list<array{weekday:int,start_min:int,end_min:int}> $rules Rules.
	 */
	private static function week_has_overlap( array $rules ): bool {
		$by_day = array();

		foreach ( $rules as $rule ) {
			$by_day[ $rule['weekday'] ][] = $rule;
		}

		foreach ( $by_day as $day_rules ) {
			usort(
				$day_rules,
				static fn ( array $a, array $b ): int => $a['start_min'] <=> $b['start_min']
			);

			$previous_end = -1;

			foreach ( $day_rules as $rule ) {
				if ( $rule['start_min'] < $previous_end ) {
					return true;
				}

				$previous_end = $rule['end_min'];
			}
		}

		return false;
	}

	private static function is_whole_number( mixed $value ): bool {
		if ( is_int( $value ) ) {
			return true;
		}

		return is_string( $value ) && 1 === preg_match( '/^-?\d+$/', $value );
	}

	private static function invalid_week_block(): WP_Error {
		return new WP_Error(
			'plumberslot_invalid_week_block',
			__( 'Each availability block needs a weekday 0–6 and minutes within 0–1440, with start before end.', 'plumberslot' ),
			array( 'status' => 400 )
		);
	}
}
