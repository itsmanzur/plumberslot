<?php
/**
 * /tutorslot/v1/availability — week grid + exceptions + lesson defaults.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Rest;

use TutorSlot\Database\Repository\AvailabilityRepository;
use TutorSlot\Support\AuditLog;
use TutorSlot\Support\Capabilities;
use TutorSlot\Support\Settings;
use TutorSlot\Support\Validate;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class AvailabilityController extends AbstractController {

	public function __construct(
		Guard $guard,
		private readonly AvailabilityRepository $availability
	) {
		parent::__construct( $guard );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/availability/(?P<tutor_id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array( 'tutor_id' => array( 'sanitize_callback' => 'absint' ) ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'replace' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'tutor_id' => array( 'sanitize_callback' => 'absint' ),
						'week'     => array(
							'required'          => true,
							'type'              => 'array',
							'sanitize_callback' => array( Validate::class, 'sanitize_week' ),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/availability/(?P<tutor_id>\d+)/exceptions',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_exceptions' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array( 'tutor_id' => array( 'sanitize_callback' => 'absint' ) ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'add_exception' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'tutor_id'  => array( 'sanitize_callback' => 'absint' ),
						'on_date'   => array(
							'required'          => true,
							'type'              => 'string',
							'validate_callback' => array( Validate::class, 'is_date' ),
						),
						'kind'      => array(
							'type'    => 'string',
							'enum'    => array( 'closed', 'open' ),
							'default' => 'closed',
						),
						'note'      => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'start_min' => array( 'type' => 'integer' ),
						'end_min'   => array( 'type' => 'integer' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/availability/(?P<tutor_id>\d+)/exceptions/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_exception' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'tutor_id' => array( 'sanitize_callback' => 'absint' ),
					'id'       => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/availability/(?P<tutor_id>\d+)/defaults',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_defaults' ),
				'permission_callback' => array( $this, 'can_manage_defaults' ),
				'args'                => array(
					'tutor_id' => array( 'sanitize_callback' => 'absint' ),
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

		return $this->guard->owns_tutor( (int) $request['tutor_id'] ) ? true : $this->guard->deny();
	}

	/**
	 * Lesson defaults are site settings — tutors who manage their own schedule
	 * may update the three availability-related integers; site managers always can.
	 */
	public function can_manage_defaults( WP_REST_Request $request ): bool|WP_Error {
		$base = $this->can_manage( $request );

		if ( is_wp_error( $base ) || true !== $base ) {
			return $base;
		}

		return true;
	}

	public function show( WP_REST_Request $request ): WP_REST_Response {
		$tutor_id = (int) $request['tutor_id'];

		return $this->ok(
			array(
				'week'       => $this->availability->rules_for( $tutor_id ),
				'exceptions' => $this->availability->exceptions_for_tutor( $tutor_id ),
				'defaults'   => array(
					'default_lesson_minutes' => Settings::int( 'default_lesson_minutes', 60 ),
					'buffer_minutes'         => Settings::int( 'buffer_minutes', 0 ),
					'lead_time_minutes'      => Settings::int( 'lead_time_minutes', 0 ),
				),
			)
		);
	}

	public function replace( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tutor_id = (int) $request['tutor_id'];
		/** @var list<array{weekday:int,start_min:int,end_min:int}> $week */
		$week = $request['week'];

		if ( ! $this->availability->replace_week( $tutor_id, $week ) ) {
			return new WP_Error(
				'tutorslot_availability_save_failed',
				__( 'Could not save availability. Try again.', 'tutorslot' ),
				array( 'status' => 500 )
			);
		}

		AuditLog::record( 'availability.replaced', 'tutor', $tutor_id, array( 'blocks' => count( $week ) ) );

		return $this->ok( array( 'saved' => count( $week ) ) );
	}

	public function list_exceptions( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok(
			array( 'exceptions' => $this->availability->exceptions_for_tutor( (int) $request['tutor_id'] ) )
		);
	}

	public function add_exception( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tutor_id = (int) $request['tutor_id'];
		$id       = $this->availability->add_exception(
			$tutor_id,
			array(
				'on_date'   => (string) $request['on_date'],
				'kind'      => (string) $request['kind'],
				'note'      => (string) ( $request['note'] ?? '' ),
				'start_min' => $request['start_min'] ?? null,
				'end_min'   => $request['end_min'] ?? null,
			)
		);

		if ( $id <= 0 ) {
			return new WP_Error(
				'tutorslot_exception_save_failed',
				__( 'Could not save the time off entry.', 'tutorslot' ),
				array( 'status' => 500 )
			);
		}

		AuditLog::record( 'exception.created', 'exception', $id, array( 'tutor_id' => $tutor_id ) );

		return $this->ok( array( 'id' => $id ), 201 );
	}

	public function delete_exception( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tutor_id = (int) $request['tutor_id'];
		$id       = (int) $request['id'];

		if ( ! $this->availability->delete_exception( $tutor_id, $id ) ) {
			return $this->guard->deny();
		}

		AuditLog::record( 'exception.deleted', 'exception', $id );

		return $this->ok( array( 'deleted' => true ) );
	}

	public function save_defaults( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! current_user_can( Capabilities::MANAGE_ALL ) && ! current_user_can( Capabilities::MANAGE_OWN ) ) {
			return $this->guard->deny();
		}

		$body = (array) $request->get_json_params();
		$out  = array();

		$map = array(
			'default_lesson_minutes' => array( 15, 480 ),
			'buffer_minutes'         => array( 0, 120 ),
			'lead_time_minutes'      => array( 0, 20160 ),
		);

		foreach ( $map as $key => [$min, $max] ) {
			if ( isset( $body[ $key ] ) ) {
				$out[ $key ] = max( $min, min( $max, absint( $body[ $key ] ) ) );
			}
		}

		if ( array() !== $out ) {
			Settings::update( $out );
			AuditLog::record( 'availability.defaults', 'tutor', (int) $request['tutor_id'], $out );
		}

		return $this->ok(
			array(
				'saved'    => true,
				'defaults' => $out,
			)
		);
	}
}
