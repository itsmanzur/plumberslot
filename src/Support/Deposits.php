<?php
/**
 * Splits a booking's price into an amount to charge online now and an amount
 * the technician collects on-site later.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Support;

defined( 'ABSPATH' ) || exit;

final class Deposits {

	/**
	 * A service with no deposit configured (or a zero/free price, already
	 * zeroed out by the caller for a free estimate or credit-paid booking)
	 * keeps today's behaviour exactly: pay the full amount now, nothing due
	 * later.
	 *
	 * @return array{deposit_minor:int, balance_minor:int}
	 */
	public static function split( object|null $service, int $price_minor ): array {
		if ( null === $service
			|| ! in_array( $service->deposit_type ?? 'none', array( 'fixed', 'percent' ), true )
			|| $price_minor <= 0 ) {
			return array(
				'deposit_minor' => $price_minor,
				'balance_minor' => 0,
			);
		}

		if ( 'fixed' === $service->deposit_type ) {
			$deposit = min( (int) $service->deposit_value, $price_minor );
		} else {
			$percent = min( 100, max( 0, (int) $service->deposit_value ) );
			$deposit = (int) round( $price_minor * $percent / 100 );
			$deposit = min( $deposit, $price_minor );
		}

		return array(
			'deposit_minor' => $deposit,
			'balance_minor' => $price_minor - $deposit,
		);
	}
}
