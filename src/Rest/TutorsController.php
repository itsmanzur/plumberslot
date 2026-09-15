<?php
/**
 * /plumberslot/v1/tutors — manager-only tutor directory and invites.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Rest;

use PlumberSlot\Database\Repository\SubjectRepository;
use PlumberSlot\Database\Repository\TutorRepository;
use PlumberSlot\Support\AuditLog;
use PlumberSlot\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class TutorsController extends AbstractController {

	public function __construct(
		Guard $guard,
		private readonly TutorRepository $tutors,
		private readonly SubjectRepository $subjects
	) {
		parent::__construct( $guard );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/tutors',
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
						'payout_share_pct'  => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 100,
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
			'/tutors/(?P<id>\d+)',
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
			'/tutors/(?P<id>\d+)/resend',
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

		if ( ! current_user_can( Capabilities::MANAGE_TUTORS ) ) {
			return $this->guard->deny();
		}

		return true;
	}

	public function index(): WP_REST_Response {
		$rows = array();

		foreach ( $this->tutors->all() as $tutor ) {
			$rows[] = $this->present( $tutor );
		}

		return $this->ok( array( 'tutors' => $rows ) );
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tutor = $this->tutors->find( (int) $request['id'] );

		if ( ! $tutor ) {
			return $this->guard->deny();
		}

		return $this->ok( $this->present( $tutor, true ) );
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
				$login = 'tutor_' . wp_generate_password( 8, false, false );
			}

			$user_id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 24 ),
					'display_name' => (string) ( $request['display_name'] ? $request['display_name'] : $login ),
					'role'         => Capabilities::ROLE_TUTOR,
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
			$user->add_role( Capabilities::ROLE_TUTOR );
		}

		if ( ! $user ) {
			return new WP_Error(
				'plumberslot_invite_failed',
				__( 'Could not create the tutor account.', 'plumberslot' ),
				array( 'status' => 500 )
			);
		}

		$existing = $this->tutors->find_by_user( (int) $user->ID );

		if ( $existing ) {
			return new WP_Error(
				'plumberslot_tutor_exists',
				__( 'That person is already a PlumberSlot tutor.', 'plumberslot' ),
				array( 'status' => 409 )
			);
		}

		$display = (string) ( $request['display_name'] ? $request['display_name'] : $user->display_name );
		$slug    = sanitize_title( $display );

		if ( '' === $slug || $this->tutors->find_by_slug( $slug ) ) {
			$slug = sanitize_title( $display . '-' . $user->ID );
		}

		$id = $this->tutors->create(
			array(
				'user_id'           => (int) $user->ID,
				'slug'              => $slug,
				'display_name'      => $display,
				'timezone'          => wp_timezone_string(),
				'hourly_rate_minor' => (int) $request['hourly_rate_minor'],
				'currency'          => strtoupper( substr( (string) $request['currency'], 0, 3 ) ),
				'payout_share_pct'  => min( 100, (int) $request['payout_share_pct'] ),
				'status'            => 'invited',
			)
		);

		$this->send_invite_email( (int) $user->ID, $email );
		AuditLog::record( 'tutor.invited', 'tutor', $id, array( 'email' => $email ) );

		$tutor = $this->tutors->find( $id );

		return $this->ok( $this->present( $tutor ), 201 );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id    = (int) $request['id'];
		$tutor = $this->tutors->find( $id );

		if ( ! $tutor ) {
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

		if ( isset( $body['payout_share_pct'] ) ) {
			$data['payout_share_pct'] = min( 100, absint( $body['payout_share_pct'] ) );
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
			$this->tutors->update( $id, $data );
			AuditLog::record( 'tutor.updated', 'tutor', $id, array( 'keys' => array_keys( $data ) ) );
		}

		return $this->ok( $this->present( $this->tutors->find( $id ), true ) );
	}

	public function resend( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tutor = $this->tutors->find( (int) $request['id'] );

		if ( ! $tutor ) {
			return $this->guard->deny();
		}

		$user = get_user_by( 'id', (int) $tutor->user_id );

		if ( ! $user ) {
			return $this->guard->deny();
		}

		$this->send_invite_email( (int) $user->ID, $user->user_email );
		AuditLog::record( 'tutor.invite_resent', 'tutor', (int) $tutor->id );

		return $this->ok( array( 'resent' => true ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present( ?object $tutor, bool $with_subjects = false ): array {
		if ( ! $tutor ) {
			return array();
		}

		$subjects = array();

		if ( $with_subjects ) {
			foreach ( $this->subjects->all_for_tutor( (int) $tutor->id ) as $subject ) {
				$subjects[] = array(
					'id'           => (int) $subject->id,
					'name'         => (string) $subject->name,
					'duration_min' => (int) $subject->duration_min,
					'price_minor'  => (int) $subject->price_minor,
					'status'       => (string) $subject->status,
				);
			}
		} else {
			foreach ( $this->subjects->all_for_tutor( (int) $tutor->id ) as $subject ) {
				if ( 'active' === (string) $subject->status ) {
					$subjects[] = (string) $subject->name;
				}
			}
		}

		return array(
			'id'                => (int) $tutor->id,
			'user_id'           => (int) $tutor->user_id,
			'slug'              => (string) $tutor->slug,
			'display_name'      => (string) $tutor->display_name,
			'timezone'          => (string) $tutor->timezone,
			'hourly_rate_minor' => (int) $tutor->hourly_rate_minor,
			'currency'          => (string) $tutor->currency,
			'payout_share_pct'  => (int) $tutor->payout_share_pct,
			'status'            => (string) $tutor->status,
			'subjects'          => $subjects,
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
