<?php
/**
 * The ZIP/postal codes a business is willing to travel to.
 *
 * An empty list means unrestricted -- most sites never configure this, and a
 * booking should never be blocked by a setting nobody set.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Support;

defined( 'ABSPATH' ) || exit;

final class ServiceArea {

	/**
	 * @return list<string> Normalised (trimmed, uppercased) codes. Empty means unrestricted.
	 */
	public static function list(): array {
		$raw = Settings::string( 'service_area_zips', '' );

		if ( '' === trim( $raw ) ) {
			return array();
		}

		$codes = preg_split( '/[,\r\n]+/', $raw );

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn ( string $code ): string => strtoupper( trim( $code ) ),
						(array) $codes
					)
				)
			)
		);
	}

	/**
	 * Whether a booking address's ZIP falls inside the configured area.
	 *
	 * Always true when no area is configured.
	 */
	public static function allows( string $zip ): bool {
		$area = self::list();

		if ( array() === $area ) {
			return true;
		}

		return in_array( strtoupper( trim( $zip ) ), $area, true );
	}
}
