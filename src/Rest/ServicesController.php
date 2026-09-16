<?php
/**
 * /plumberslot/v1/technicians/{id}/services
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Rest;

use PlumberSlot\Database\Repository\ServiceRepository;
use PlumberSlot\Database\Repository\TechnicianRepository;
use PlumberSlot\Support\AuditLog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class ServicesController extends AbstractController {

	public function __construct(
		Guard $guard,
		private readonly ServiceRepository $services,
		private readonly TechnicianRepository $technicians
	) {
		parent::__construct( $guard );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/technicians/(?P<technician_id>\d+)/services',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array( 'technician_id' => array( 'sanitize_callback' => 'absint' ) ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'technician_id'          => array( 'sanitize_callback' => 'absint' ),
						'name'                   => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'duration_min'           => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 60,
						),
						'price_minor'            => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 0,
						),
						'is_free_estimate'       => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'is_emergency_available' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'category'               => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status'                 => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/technicians/(?P<technician_id>\d+)/services/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'technician_id' => array( 'sanitize_callback' => 'absint' ),
						'id'            => array( 'sanitize_callback' => 'absint' ),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'technician_id' => array( 'sanitize_callback' => 'absint' ),
						'id'            => array( 'sanitize_callback' => 'absint' ),
					),
				),
			)
		);
	}

	public function can_manage( WP_REST_Request $request ): bool|WP_Error {
		$logged_in = $this->require_login();

		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}

		$nonce = $this->verify_nonce( $request );

		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		return $this->guard->owns_technician( (int) $request['technician_id'] ) ? true : $this->guard->deny();
	}

	public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$technician = $this->technicians->find( (int) $request['technician_id'] );

		if ( ! $technician ) {
			return $this->guard->deny();
		}

		$items = array();

		foreach ( $this->services->all_for_technician( (int) $request['technician_id'] ) as $row ) {
			$items[] = $this->present( $row, $technician );
		}

		return $this->ok(
			array(
				'services' => $items,
				'currency' => (string) $technician->currency,
			)
		);
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$technician_id = (int) $request['technician_id'];
		$technician    = $this->technicians->find( $technician_id );

		if ( ! $technician ) {
			return $this->guard->deny();
		}

		$id = $this->services->create(
			$technician_id,
			array(
				'name'                   => (string) $request['name'],
				'duration_min'           => (int) $request['duration_min'],
				'price_minor'            => (int) $request['price_minor'],
				'is_free_estimate'       => $request['is_free_estimate'] ? 1 : 0,
				'is_emergency_available' => $request['is_emergency_available'] ? 1 : 0,
				'category'               => (string) ( $request['category'] ?? '' ),
				'status'                 => (string) ( $request['status'] ?? 'active' ),
			)
		);

		if ( $id <= 0 ) {
			return new WP_Error(
				'plumberslot_service_save_failed',
				__( 'Could not save the service.', 'plumberslot' ),
				array( 'status' => 500 )
			);
		}

		AuditLog::record( 'service.created', 'service', $id, array( 'technician_id' => $technician_id ) );
		$row = $this->services->find_for_technician( $id, $technician_id );

		return $this->ok( $this->present( $row, $technician ), 201 );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$technician_id = (int) $request['technician_id'];
		$service_id    = (int) $request['id'];
		$technician    = $this->technicians->find( $technician_id );
		$existing      = $this->services->find_for_technician( $service_id, $technician_id );

		if ( ! $technician || ! $existing ) {
			return $this->guard->deny();
		}

		$body = (array) $request->get_json_params();
		$ok   = $this->services->update_for_technician( $service_id, $technician_id, $body );

		if ( ! $ok ) {
			return new WP_Error(
				'plumberslot_service_save_failed',
				__( 'Could not update the service.', 'plumberslot' ),
				array( 'status' => 500 )
			);
		}

		AuditLog::record( 'service.updated', 'service', $service_id );

		return $this->ok( $this->present( $this->services->find_for_technician( $service_id, $technician_id ), $technician ) );
	}

	public function destroy( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$technician_id = (int) $request['technician_id'];
		$service_id    = (int) $request['id'];

		if ( ! $this->services->find_for_technician( $service_id, $technician_id ) ) {
			return $this->guard->deny();
		}

		if ( ! $this->services->delete_for_technician( $service_id, $technician_id ) ) {
			return new WP_Error(
				'plumberslot_service_delete_failed',
				__( 'Could not delete the service.', 'plumberslot' ),
				array( 'status' => 500 )
			);
		}

		AuditLog::record( 'service.deleted', 'service', $service_id );

		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present( ?object $row, ?object $technician = null ): array {
		if ( ! $row ) {
			return array();
		}

		return array(
			'id'                     => (int) $row->id,
			'technician_id'          => (int) $row->technician_id,
			'name'                   => (string) $row->name,
			'category'               => $row->category,
			'duration_min'           => (int) $row->duration_min,
			'price_minor'            => (int) $row->price_minor,
			'currency'               => $technician ? (string) $technician->currency : 'USD',
			'is_free_estimate'       => (bool) $row->is_free_estimate,
			'is_emergency_available' => (bool) $row->is_emergency_available,
			'status'                 => (string) $row->status,
			'sort_order'             => (int) $row->sort_order,
		);
	}
}
