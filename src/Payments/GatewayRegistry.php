<?php
/**
 * Available gateways.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Payments;

defined( 'ABSPATH' ) || exit;

final class GatewayRegistry {

	/** @var array<string, GatewayInterface> */
	private array $gateways = array();

	public function register( GatewayInterface $gateway ): void {
		$this->gateways[ $gateway->id() ] = $gateway;
	}

	public function get( string $id ): ?GatewayInterface {
		return $this->gateways[ $id ] ?? null;
	}

	/**
	 * @return array<string, GatewayInterface>
	 */
	public function configured(): array {
		return array_filter( $this->all(), static fn ( GatewayInterface $g ): bool => $g->is_configured() );
	}

	/**
	 * @return array<string, GatewayInterface>
	 */
	public function all(): array {
		/**
		 * Filter registered gateways.
		 *
		 * Local providers (bKash, Nagad, SSLCommerz, Razorpay) plug in here.
		 *
		 * @param array<string, GatewayInterface> $gateways Registered gateways.
		 */
		return apply_filters( 'tutorslot_payment_gateways', $this->gateways );
	}
}
