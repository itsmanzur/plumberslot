<?php
/**
 * Gateway callbacks.
 *
 * Two rules, both load-bearing: verify the signature before parsing anything,
 * and refuse to process the same event twice. Without the second one a replayed
 * webhook can confirm a cancelled lesson or refund a package repeatedly.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Payments;

use TutorSlot\Domain\PaymentService;
use TutorSlot\Support\AuditLog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class WebhookController {

	public function __construct(
		private readonly GatewayRegistry $gateways,
		private readonly PaymentService $payments
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'tutorslot/v1',
			'/webhook/(?P<gateway>[a-z0-9_-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				// Public by necessity; authenticity comes from the signature.
				'permission_callback' => '__return_true',
				'args'                => array(
					'gateway' => array( 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gateway = $this->gateways->get( (string) $request['gateway'] );

		if ( ! $gateway ) {
			return new WP_Error( 'tutorslot_unknown_gateway', '', array( 'status' => 404 ) );
		}

		$raw     = $request->get_body();
		$headers = array();

		foreach ( $request->get_headers() as $name => $values ) {
			$headers[ strtolower( str_replace( '_', '-', $name ) ) ] = is_array( $values ) ? (string) reset( $values ) : (string) $values;
		}

		if ( ! $gateway->verify_webhook( $raw, $headers ) ) {
			AuditLog::record( 'webhook.rejected', 'gateway', 0, array( 'gateway' => $gateway->id() ) );

			return new WP_Error( 'tutorslot_bad_signature', '', array( 'status' => 400 ) );
		}

		$event = $gateway->parse_webhook( $raw );

		if ( is_wp_error( $event ) ) {
			return $event;
		}

		$result = $this->payments->apply_event( $gateway->id(), $event );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}
}
