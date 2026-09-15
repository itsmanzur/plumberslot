<?php
/**
 * /plumberslot/v1/payments — gateways, start, refund, bKash callback.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Rest;

use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Repository\PaymentRepository;
use PlumberSlot\Domain\PaymentService;
use PlumberSlot\Support\Capabilities;
use PlumberSlot\Support\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class PaymentsController extends AbstractController {

	public function __construct(
		Guard $guard,
		private readonly PaymentService $payments,
		private readonly BookingRepository $bookings,
		private readonly PaymentRepository $payment_repo
	) {
		parent::__construct( $guard );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/payments/gateways',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'gateways' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/payments/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start' ),
				'permission_callback' => array( $this, 'can_pay' ),
				'args'                => array(
					'booking_id'  => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'gateway'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'success_url' => array(
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
					'cancel_url'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/payments/booking/(?P<booking_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'history' ),
				'permission_callback' => array( $this, 'can_view_booking' ),
				'args'                => array(
					'booking_id' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/payments/booking/(?P<booking_id>\d+)/refund',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'refund' ),
				'permission_callback' => array( $this, 'can_refund' ),
				'args'                => array(
					'booking_id' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/payments/booking/(?P<booking_id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel' ),
				'permission_callback' => array( $this, 'can_view_booking' ),
				'args'                => array(
					'booking_id' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/payments/bkash/callback',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'bkash_callback' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function gateways(): WP_REST_Response {
		return $this->ok(
			array(
				'enabled'  => Settings::bool( 'payments_enabled', true ),
				'gateways' => $this->payments->configured_gateways(),
			)
		);
	}

	public function can_pay( WP_REST_Request $request ): bool|WP_Error {
		$auth = $this->require_login();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$nonce = $this->verify_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$booking = $this->bookings->find( (int) $request['booking_id'] );
		if ( ! $booking || ! $this->guard->may_touch_booking( $booking ) ) {
			return $this->guard->deny();
		}

		return true;
	}

	public function can_view_booking( WP_REST_Request $request ): bool|WP_Error {
		$auth = $this->require_login();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$nonce = $this->verify_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$booking = $this->bookings->find( (int) $request['booking_id'] );
		if ( ! $booking || ! $this->guard->may_touch_booking( $booking ) ) {
			return $this->guard->deny();
		}

		return true;
	}

	public function can_refund( WP_REST_Request $request ): bool|WP_Error {
		$auth = $this->require_login();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$nonce = $this->verify_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		if ( ! current_user_can( Capabilities::MANAGE_ALL ) && ! current_user_can( Capabilities::MANAGE_OWN ) ) {
			return $this->guard->deny();
		}

		$booking = $this->bookings->find( (int) $request['booking_id'] );
		if ( ! $booking ) {
			return $this->guard->deny();
		}

		if ( $this->guard->is_site_manager() || $this->guard->owns_tutor( (int) $booking->tutor_id ) ) {
			return true;
		}

		return $this->guard->deny();
	}

	public function start( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$success = (string) ( $request['success_url'] ? $request['success_url'] : home_url( '/?plumberslot_pay=success' ) );
		$cancel  = (string) ( $request['cancel_url'] ? $request['cancel_url'] : home_url( '/?plumberslot_pay=cancel' ) );

		$result = $this->payments->start(
			(int) $request['booking_id'],
			(string) $request['gateway'],
			$success,
			$cancel
		);

		return is_wp_error( $result ) ? $result : $this->ok( $result, 201 );
	}

	public function history( WP_REST_Request $request ): WP_REST_Response {
		$rows = $this->payment_repo->for_booking( (int) $request['booking_id'] );

		return $this->ok(
			array(
				'items' => array_map(
					static function ( object $row ): array {
						return array(
							'id'           => (int) $row->id,
							'gateway'      => (string) $row->gateway,
							'reference'    => $row->reference,
							'amount_minor' => (int) $row->amount_minor,
							'currency'     => (string) $row->currency,
							'status'       => (string) $row->status,
							'created_at'   => (string) $row->created_at,
							'updated_at'   => (string) $row->updated_at,
						);
					},
					$rows
				),
			)
		);
	}

	public function refund( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->payments->refund_booking( (int) $request['booking_id'] );

		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	public function cancel( WP_REST_Request $request ): WP_REST_Response {
		$this->payments->cancel_pending( (int) $request['booking_id'] );

		return $this->ok( array( 'cancelled' => true ) );
	}

	public function bkash_callback( WP_REST_Request $request ): WP_REST_Response {
		$payment_id = $request->get_param( 'paymentID' );
		$payment_id = sanitize_text_field( (string) ( $payment_id ? $payment_id : $request->get_param( 'paymentId' ) ) );
		$booking_id = absint( $request->get_param( 'booking_id' ) );
		$status     = strtolower( sanitize_key( (string) $request->get_param( 'status' ) ) );
		$return     = esc_url_raw( rawurldecode( (string) $request->get_param( 'return' ) ) );
		$cancel     = esc_url_raw( rawurldecode( (string) $request->get_param( 'cancel' ) ) );

		if ( '' === $return ) {
			$return = home_url( '/?plumberslot_pay=success' );
		}
		if ( '' === $cancel ) {
			$cancel = home_url( '/?plumberslot_pay=cancel' );
		}

		if ( in_array( $status, array( 'cancel', 'failure', 'failed' ), true ) || '' === $payment_id ) {
			if ( $booking_id > 0 ) {
				$this->payments->cancel_pending( $booking_id );
			}
			wp_safe_redirect(
				add_query_arg(
					array(
						'plumberslot_pay' => 'cancel',
						'booking'       => $booking_id,
					),
					$cancel
				)
			);
			exit;
		}

		$result = $this->payments->bkash_complete( $payment_id, $booking_id );

		$flag = is_wp_error( $result ) || empty( $result['ok'] ) ? 'cancel' : 'success';
		wp_safe_redirect(
			add_query_arg(
				array(
					'plumberslot_pay' => $flag,
					'booking'       => $booking_id,
				),
				'success' === $flag ? $return : $cancel
			)
		);
		exit;
	}
}
