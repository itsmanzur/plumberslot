<?php
/**
 * Money is always an integer number of minor units.
 *
 * Floats and currency do not mix; a rounding error in a lesson package is a
 * support ticket that costs more than the lesson.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Support;

defined( 'ABSPATH' ) || exit;

final class Money {

	/** Currencies whose minor unit is the same as the major unit. */
	private const ZERO_DECIMAL = array( 'JPY', 'KRW', 'VND', 'CLP', 'ISK' );

	public static function minor_units( string $currency ): int {
		return in_array( strtoupper( $currency ), self::ZERO_DECIMAL, true ) ? 1 : 100;
	}

	public static function to_minor( float $amount, string $currency ): int {
		return (int) round( $amount * self::minor_units( $currency ) );
	}

	public static function to_major( int $minor, string $currency ): float {
		return $minor / self::minor_units( $currency );
	}

	public static function format( int $minor, string $currency ): string {
		$amount = self::to_major( $minor, $currency );
		$digits = 1 === self::minor_units( $currency ) ? 0 : 2;

		/**
		 * Filter the rendered price.
		 *
		 * @param string $formatted Formatted amount.
		 * @param int    $minor     Amount in minor units.
		 * @param string $currency  ISO 4217 code.
		 */
		return apply_filters(
			'plumberslot_format_price',
			number_format_i18n( $amount, $digits ) . ' ' . strtoupper( $currency ),
			$minor,
			$currency
		);
	}
}
