<?php
/**
 * /plumberslot/v1/credits — packages, purchase, ledger.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Rest;

use PlumberSlot\Database\Repository\CreditRepository;
use PlumberSlot\Domain\CreditService;
use PlumberSlot\Domain\PaymentService;
use PlumberSlot\Support\Capabilities;
use PlumberSlot\Support\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class CreditsController extends AbstractController {

	public function __construct(
		Guard $guard,
		private readonly CreditService $credits,
		private readonly CreditRepository $repo,
		private readonly PaymentService $payment_service
	) {
		parent::__construct( $guard );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/credits',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_act' ),
					'args'                => array(
						'technician_id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'purchase' ),
					'permission_callback' => array( $this, 'can_act' ),
					'args'                => array(
						'technician_id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'service_id'    => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'total'         => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
						),
						'owner_id'      => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/credits/balance',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'balance' ),
				'permission_callback' => array( $this, 'can_act' ),
				'args'                => array(
					'technician_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/credits/ledger',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'ledger' ),
				'permission_callback' => array( $this, 'can_act' ),
				'args'                => array(
					'limit' => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/credits/packages',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'catalog' ),
				'permission_callback' => '__return_true',
			)
		);

		// Additive alongside POST /credits above, which stays exactly as it was
		// (an immediately-usable, unpaid package -- presumably for admin/manual
		// issuance, since nothing in the widget calls it today). This is the
		// real, paid purchase path: it always creates a 'pending' package and
		// starts a gateway checkout for it, and always buys for the logged-in
		// customer themselves -- unlike /credits, there is no owner_id override,
		// to keep this endpoint's attack surface narrow.
		register_rest_route(
			self::NAMESPACE,
			'/credits/checkout',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'checkout' ),
				'permission_callback' => array( $this, 'can_checkout' ),
				'args'                => array(
					'technician_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'service_id'    => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'total'         => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
					),
					// Not marked 'required': a zero-price package (Settings'
					// credit_package_price_minor === 0) needs no gateway at all,
					// and the client knows the price up front from
					// /credits/packages. checkout() itself requires a non-empty
					// gateway only once it knows the price is actually > 0.
					'gateway'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'success_url'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
					'cancel_url'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);
	}

	public function can_act( WP_REST_Request $request ): bool|WP_Error {
		$auth = $this->require_login();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		return $this->verify_nonce( $request );
	}

	public function can_checkout( WP_REST_Request $request ): bool|WP_Error {
		$auth = $this->require_login();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		return $this->verify_nonce( $request );
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		$owner_id      = get_current_user_id();
		$technician_id = $request['technician_id'] ? (int) $request['technician_id'] : null;
		$rows          = $this->repo->for_owner( $owner_id, $technician_id );

		return $this->ok(
			array(
				'items' => array_map( array( $this, 'present' ), $rows ),
			)
		);
	}

	public function balance( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( $this->credits->balance( get_current_user_id(), (int) $request['technician_id'] ) );
	}

	public function catalog(): WP_REST_Response {
		$size  = Settings::int( 'credit_package_size', 10 );
		$price = Settings::int( 'credit_package_price_minor', 0 );
		$days  = Settings::int( 'credit_expiry_days', 180 );

		return $this->ok(
			array(
				'default_total'       => $size,
				'price_minor'         => $price,
				'currency'            => Settings::string( 'default_currency', 'USD' ),
				'expiry_days'         => $days,
				'rollover_enabled'    => Settings::bool( 'credit_rollover_enabled', true ),
				'refund_window_hours' => (int) round( Settings::int( 'credit_refund_window_minutes', 720 ) / 60 ),
			)
		);
	}

	public function purchase( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$actor    = get_current_user_id();
		$owner_id = (int) ( $request['owner_id'] ? $request['owner_id'] : $actor );

		if ( $owner_id !== $actor && ! current_user_can( Capabilities::MANAGE_ALL ) ) {
			return $this->guard->deny();
		}

		$total = (int) ( $request['total'] ? $request['total'] : Settings::int( 'credit_package_size', 10 ) );
		$total = max( 1, min( 100, $total ) );

		$package = $this->credits->purchase(
			array(
				'owner_id'      => $owner_id,
				'technician_id' => $request['technician_id'] ? (int) $request['technician_id'] : null,
				'service_id'    => $request['service_id'] ? (int) $request['service_id'] : null,
				'total'         => $total,
				'price_minor'   => Settings::int( 'credit_package_price_minor', 0 ),
			)
		);

		if ( is_wp_error( $package ) ) {
			return $package;
		}

		return $this->ok( $this->present( $package ), 201 );
	}

	/**
	 * Start a paid Service Plan purchase for the logged-in customer. Returns
	 * the same {url, payment_id, gateway, amount_minor, currency} shape
	 * PaymentsController::start() returns for a booking, plus a
	 * requires_payment flag, so widget-side handling can stay symmetric with
	 * the existing booking checkout flow.
	 */
	public function checkout( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$owner_id    = get_current_user_id();
		$total       = (int) ( $request['total'] ? $request['total'] : Settings::int( 'credit_package_size', 10 ) );
		$total       = max( 1, min( 100, $total ) );
		$price_minor = Settings::int( 'credit_package_price_minor', 0 );
		$gateway     = (string) ( $request['gateway'] ?? '' );

		// Validated before create_pending() runs, not after, so a request with
		// no payment method never leaves an orphaned 'pending' package behind.
		if ( $price_minor > 0 && '' === $gateway ) {
			return new WP_Error(
				'plumberslot_gateway_required',
				__( 'Choose a payment method.', 'plumberslot' ),
				array( 'status' => 422 )
			);
		}

		$credit_id = $this->credits->create_pending(
			array(
				'owner_id'      => $owner_id,
				'technician_id' => $request['technician_id'] ? (int) $request['technician_id'] : null,
				'service_id'    => $request['service_id'] ? (int) $request['service_id'] : null,
				'total'         => $total,
				'price_minor'   => $price_minor,
			)
		);

		if ( is_wp_error( $credit_id ) ) {
			return $credit_id;
		}

		if ( $price_minor <= 0 ) {
			// create_pending() issued a free package as immediately active,
			// matching the existing /credits purchase() endpoint's behaviour --
			// there is nothing to pay for, so no checkout session is started.
			return $this->ok(
				array(
					'url'              => '',
					'payment_id'       => 0,
					'gateway'          => '',
					'amount_minor'     => 0,
					'currency'         => Settings::string( 'default_currency', 'USD' ),
					'credit_id'        => $credit_id,
					'requires_payment' => false,
				),
				201
			);
		}

		// The dashboard widget always supplies its own success_url/cancel_url
		// (see CustomerDashboard.js's creditReturnUrl()), which use
		// ?plumberslot_credit=success|cancel rather than booking's
		// ?plumberslot_pay=..., since the two return flags are read by
		// different views. These are only a fallback for any other caller.
		$success = (string) ( $request['success_url'] ? $request['success_url'] : home_url( '/customer-dashboard/?plumberslot_credit=success' ) );
		$cancel  = (string) ( $request['cancel_url'] ? $request['cancel_url'] : home_url( '/customer-dashboard/?plumberslot_credit=cancel' ) );

		$result = $this->payment_service->start_credit_purchase(
			$credit_id,
			$gateway,
			$success,
			$cancel
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( array_merge( $result, array( 'requires_payment' => true ) ), 201 );
	}

	public function ledger( WP_REST_Request $request ): WP_REST_Response {
		$owner_id = get_current_user_id();
		$limit    = (int) ( $request['limit'] ?? 50 );
		$mine     = $this->repo->for_owner( $owner_id );
		$ids      = array_map( static fn ( object $r ): int => (int) $r->id, $mine );

		return $this->ok(
			array(
				'items' => $this->credits->ledger_for( $ids, $limit ),
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present( object $row ): array {
		return array(
			'id'            => (int) $row->id,
			'owner_id'      => (int) $row->owner_id,
			'technician_id' => $row->technician_id ? (int) $row->technician_id : null,
			'service_id'    => $row->service_id ? (int) $row->service_id : null,
			'total'         => (int) $row->total,
			'used'          => (int) $row->used,
			'remaining'     => (int) $row->total - (int) $row->used,
			'price_minor'   => (int) $row->price_minor,
			'currency'      => (string) ( $row->currency ?? Settings::string( 'default_currency', 'USD' ) ),
			'status'        => (string) ( $row->status ?? 'active' ),
			'expires_at'    => $row->expires_at,
			'created_at'    => (string) $row->created_at,
		);
	}
}
