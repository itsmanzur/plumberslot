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
		private readonly CreditRepository $repo
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
						'tutor_id' => array(
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
						'tutor_id'   => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'subject_id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'total'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
						),
						'owner_id'   => array(
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
					'tutor_id' => array(
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
	}

	public function can_act( WP_REST_Request $request ): bool|WP_Error {
		$auth = $this->require_login();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		return $this->verify_nonce( $request );
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		$owner_id = get_current_user_id();
		$tutor_id = $request['tutor_id'] ? (int) $request['tutor_id'] : null;
		$rows     = $this->repo->for_owner( $owner_id, $tutor_id );

		return $this->ok(
			array(
				'items' => array_map( array( $this, 'present' ), $rows ),
			)
		);
	}

	public function balance( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( $this->credits->balance( get_current_user_id(), (int) $request['tutor_id'] ) );
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
				'owner_id'    => $owner_id,
				'tutor_id'    => $request['tutor_id'] ? (int) $request['tutor_id'] : null,
				'subject_id'  => $request['subject_id'] ? (int) $request['subject_id'] : null,
				'total'       => $total,
				'price_minor' => Settings::int( 'credit_package_price_minor', 0 ),
			)
		);

		if ( is_wp_error( $package ) ) {
			return $package;
		}

		return $this->ok( $this->present( $package ), 201 );
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
			'id'          => (int) $row->id,
			'owner_id'    => (int) $row->owner_id,
			'tutor_id'    => $row->tutor_id ? (int) $row->tutor_id : null,
			'subject_id'  => $row->subject_id ? (int) $row->subject_id : null,
			'total'       => (int) $row->total,
			'used'        => (int) $row->used,
			'remaining'   => (int) $row->total - (int) $row->used,
			'price_minor' => (int) $row->price_minor,
			'expires_at'  => $row->expires_at,
			'created_at'  => (string) $row->created_at,
		);
	}
}
