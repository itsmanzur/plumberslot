<?php
/**
 * Time handling.
 *
 * One rule: every stored instant is UTC, and conversion happens at the edge.
 * Storing a local wall clock works until a family moves country or a DST
 * boundary lands mid-course, and then it silently produces lessons an hour off.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Support;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

final class Time {

	public static function utc(): DateTimeZone {
		return new DateTimeZone( 'UTC' );
	}

	/**
	 * MySQL DATETIME string, always UTC.
	 */
	public static function sql( DateTimeImmutable $moment ): string {
		return $moment->setTimezone( self::utc() )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Parse a stored DATETIME back into an object.
	 */
	public static function from_sql( string $sql ): DateTimeImmutable {
		return new DateTimeImmutable( $sql, self::utc() );
	}

	/**
	 * Parse an ISO 8601 string from the API, normalised to UTC.
	 *
	 * @throws \InvalidArgumentException When the string is not a valid instant.
	 */
	public static function from_iso( string $iso ): DateTimeImmutable {
		try {
			return ( new DateTimeImmutable( $iso ) )->setTimezone( self::utc() );
		} catch ( \Exception $e ) {
			throw new \InvalidArgumentException( 'Not a valid ISO 8601 instant.' );
		}
	}

	/**
	 * Render an instant for a person, in their own zone.
	 */
	public static function for_human( DateTimeImmutable $moment, string $timezone, string $format = '' ): string {
		$format = '' !== $format ? $format : get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return $moment->setTimezone( self::zone( $timezone ) )->format( $format );
	}

	/**
	 * A safe DateTimeZone. Unknown identifiers fall back to the site zone
	 * rather than throwing on a user-supplied string.
	 */
	public static function zone( string $identifier ): DateTimeZone {
		try {
			return new DateTimeZone( $identifier );
		} catch ( \Exception $e ) {
			return wp_timezone();
		}
	}

	public static function is_valid_zone( string $identifier ): bool {
		return in_array( $identifier, timezone_identifiers_list(), true );
	}
}
