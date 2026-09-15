<?php
/**
 * /plumberslot/v1/technicians — manager-only technician directory and invites.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Rest;

use PlumberSlot\Database\Repository\ServiceRepository;
use PlumberSlot\Database\Repository\TechnicianRepository;
use PlumberSlot\Support\AuditLog;
use PlumberSlot\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class TechniciansController extends AbstractController {

	public function __construct(
		Guard $guard,
		private readonly TechnicianRepository $technicians,
		private readonly ServiceRepository $services
	) {
		parent::__construct( $guard );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/technicians',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'invite' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'email'             => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
						),
						'display_name'      => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'hourly_rate_minor' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 0,
						),
						'currency'          => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => 'USD',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/technicians/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/technicians/(?P<id>\d+)/resend',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resend' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
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

		if ( ! current_user_can( Capabilities::MANAGE_TECHNICIANS ) ) {
			return $this->guard->deny();
		}

		return true;
	}

	public function index(): WP_REST_Response {
		$rows = array();

		foreach ( $this->technicians->all() as $technician ) {
			$rows[] = $this->present( $technician );
		}

		return $this->ok( array( 'technicians' => $rows ) );
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$technician = $this->technicians->find( (int) $request['id'] );

		if ( ! $technician ) {
			return $this->guard->deny();
		}

		return $this->ok( $this->present( $technician, true ) );
	}

	public function invite( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$email = (string) $request['email'];

		if ( ! is_email( $email ) ) {
			return new WP_Error(
				'plumberslot_invalid_email',
				__( 'Enter a valid email address.', 'plumberslot' ),
				array( 'status' => 400 )
			);
		}

		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			$login = sanitize_user( current( explode( '@', $email ) ), true );

			if ( '' === $login || username_exists( $login ) ) {
				$login = 'technician_' . wp_generate_password( 8, false, false );
			}

			$user_id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 24 ),
					'display_name' => (string) ( $request['display_name'] ? $request['display_name'] : $login ),
					'role'         => Capabilities::ROLE_TECHNICIAN,
				)
			);

			if ( is_wp_error( $user_id ) ) {
				return new WP_Error(
					'plumberslot_invite_failed',
					$user_id->get_error_message(),
					array( 'status' => 400 )
				);
			}

			$user = get_user_by( 'id', $user_id );
		} else {
			$user->add_role( Capabilities::ROLE_TECHNICIAN );
		}

		if ( ! $user ) {
			return new WP_Error(
				'plumberslot_invite_failed',
				__( 'Could not create the technician account.', 'plumberslot' ),
				array( 'status' => 500 )
			);
		}

		$existing = $this->technicians->find_by_user( (int) $user->ID );

		if ( $existing ) {
			return new WP_Error(
				'plumberslot_technician_exists',
				__( 'That person is already a PlumberSlot technician.', 'plumberslot' ),
				array( 'status' => 409 )
			);
		}

		$display = (string) ( $request['display_name'] ? $request['display_name'] : $user->display_name );
		$slug    = sanitize_title( $display );

		if ( '' === $slug || $this->technicians->find_by_slug( $slug ) ) {
			$slug = sanitize_title( $display . '-' . $user->ID );
		}

		$id = $this->technicians->create(
			array(
				'user_id'           => (int) $user->ID,
				'slug'              => $slug,
				'display_name'      => $display,
				'timezone'          => wp_timezone_string(),
				'hourly_rate_minor' => (int) $request['hourly_rate_minor'],
				'currency'          => strtoupper( substr( (string) $request['currency'], 0, 3 ) ),
				'status'            => 'invited',
			)
		);

		$this->send_invite_email( (int) $user->ID, $email );
		AuditLog::record( 'technician.invited', 'technician', $id, array( 'email' => $email ) );

		$technician = $this->technicians->find( $id );

		return $this->ok( $this->present( $technician ), 201 );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id    = (int) $request['id'];
		$technician = $this->technicians->find( $id );

		if ( ! $technician ) {
			return $this->guard->deny();
		}

		$body = (array) $request->get_json_params();
		$data = array();

		if ( isset( $body['display_name'] ) ) {
			$data['display_name'] = sanitize_text_field( (string) $body['display_name'] );
		}

		if ( isset( $body['hourly_rate_minor'] ) ) {
			$data['hourly_rate_minor'] = absint( $body['hourly_rate_minor'] );
		}

		if ( isset( $body['currency'] ) ) {
			$data['currency'] = strtoupper( substr( sanitize_text_field( (string) $body['currency'] ), 0, 3 ) );
		}

		if ( isset( $body['status'] ) ) {
			$status = sanitize_key( (string) $body['status'] );

			if ( in_array( $status, array( 'active', 'invited', 'disabled' ), true ) ) {
				$data['status'] = $status;
			}
		}

		if ( array() !== $data ) {
			$this->technicians->update( $id, $data );
			AuditLog::record( 'technician.updated', 'technician', $id, array( 'keys' => array_keys( $data ) ) );
		}

		return $this->ok( $this->present( $this->technicians->find( $id ), true ) );
	}

	public function resend( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$technician = $this->technicians->find( (int) $request['id'] );

		if ( ! $technician ) {
			return $this->guard->deny();
		}

		$user = get_user_by( 'id', (int) $technician->user_id );

		if ( ! $user ) {
			return $this->guard->deny();
		}

		$this->send_invite_email( (int) $user->ID, $user->user_email );
		AuditLog::record( 'technician.invite_resent', 'technician', (int) $technician->id );

		return $this->ok( array( 'resent' => true ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present( ?object $technician, bool $with_services = false ): array {
		if ( ! $technician ) {
			return array();
		}

		$services = array();

		if ( $with_services ) {
			foreach ( $this->services->all_for_technician( (int) $technician->id ) as $service ) {
				$services[] = array(
					'id'           => (int) $service->id,
					'name'         => (string) $service->name,
					'duration_min' => (int) $service->duration_min,
					'price_minor'  => (int) $service->price_minor,
					'status'       => (string) $service->status,
				);
			}
		} else {
			foreach ( $this->services->all_for_technician( (int) $technician->id ) as $service ) {
				if ( 'active' === (string) $service->status ) {
					$services[] = (string) $service->name;
				}
			}
		}

		return array(
			'id'                => (int) $technician->id,
			'user_id'           => (int) $technician->user_id,
			'slug'              => (string) $technician->slug,
			'display_name'      => (string) $technician->display_name,
			'timezone'          => (string) $technician->timezone,
			'hourly_rate_minor' => (int) $technician->hourly_rate_minor,
			'currency'          => (string) $technician->currency,
			'status'            => (string) $technician->status,
			'services'          => $services,
		);
	}

	private function send_invite_email( int $user_id, string $email ): void {
		$key  = get_password_reset_key( get_user_by( 'id', $user_id ) );
		$link = is_wp_error( $key )
			? admin_url( 'admin.php?page=plumberslot-setup' )
			: network_site_url( "wp-login.php?action=rp&key={$key}&login=" . rawurlencode( (string) get_userdata( $user_id )->user_login ), 'login' );

		wp_mail(
			$email,
			__( 'You are invited to teach on PlumberSlot', 'plumberslot' ),
			sprintf(
				/* translators: %s: set-password URL */
				__( "You have been invited to teach with PlumberSlot.\n\nSet your password and finish setup:\n%s\n", 'plumberslot' ),
				$link
			)
		);
	}
}
