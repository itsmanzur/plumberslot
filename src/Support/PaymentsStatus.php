<?php
/**
 * Whether online checkout can actually run.
 *
 * payments_enabled alone is not enough — Stripe or bKash must be configured
 * or parents only see “pay the technician directly”.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Support;

use PlumberSlot\Payments\GatewayRegistry;

defined( 'ABSPATH' ) || exit;

final class PaymentsStatus {

	/**
	 * @return array{
	 *   enabled: bool,
	 *   stripe: bool,
	 *   bkash: bool,
	 *   online_ready: bool,
	 *   needs_gateway: bool
	 * }
	 */
	public static function snapshot(): array {
		$enabled = Settings::bool( 'payments_enabled', true );
		$stripe  = false;
		$bkash   = false;

		try {
			$configured = \PlumberSlot\Plugin::instance()
				->container()
				->get( GatewayRegistry::class )
				->configured();
			$stripe     = isset( $configured['stripe'] );
			$bkash      = isset( $configured['bkash'] );
		} catch ( \Throwable ) {
			$stripe = false;
			$bkash  = false;
		}

		$has_gateway = $stripe || $bkash;

		return array(
			'enabled'       => $enabled,
			'stripe'        => $stripe,
			'bkash'         => $bkash,
			'online_ready'  => $enabled && $has_gateway,
			'needs_gateway' => $enabled && ! $has_gateway,
		);
	}
}
