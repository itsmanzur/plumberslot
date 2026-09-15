<?php
/**
 * /plumberslot/v1/tutors/{id}/subjects
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Rest;

use PlumberSlot\Database\Repository\SubjectRepository;
use PlumberSlot\Database\Repository\TutorRepository;
use PlumberSlot\Support\AuditLog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class SubjectsController extends AbstractController {

	public function __construct(
		Guard $guard,
		private readonly SubjectRepository $subjects,
		private readonly TutorRepository $tutors
	) {
		parent::__construct( $guard );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/tutors/(?P<tutor_id>\d+)/subjects',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array( 'tutor_id' => array( 'sanitize_callback' => 'absint' ) ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'tutor_id'     => array( 'sanitize_callback' => 'absint' ),
						'name'         => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'duration_min' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 60,
						),
						'price_minor'  => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 0,
						),
						'is_trial'     => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'level'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'curriculum'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status'       => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/tutors/(?P<tutor_id>\d+)/subjects/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'tutor_id' => array( 'sanitize_callback' => 'absint' ),
						'id'       => array( 'sanitize_callback' => 'absint' ),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'tutor_id' => array( 'sanitize_callback' => 'absint' ),
						'id'       => array( 'sanitize_callback' => 'absint' ),
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

		return $this->guard->owns_tutor( (int) $request['tutor_id'] ) ? true : $this->guard->deny();
	}

	public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tutor = $this->tutors->find( (int) $request['tutor_id'] );

		if ( ! $tutor ) {
			return $this->guard->deny();
		}

		$items = array();

		foreach ( $this->subjects->all_for_tutor( (int) $request['tutor_id'] ) as $row ) {
			$items[] = $this->present( $row, $tutor );
		}

		return $this->ok(
			array(
				'subjects' => $items,
				'currency' => (string) $tutor->currency,
			)
		);
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tutor_id = (int) $request['tutor_id'];
		$tutor    = $this->tutors->find( $tutor_id );

		if ( ! $tutor ) {
			return $this->guard->deny();
		}

		$id = $this->subjects->create(
			$tutor_id,
			array(
				'name'         => (string) $request['name'],
				'duration_min' => (int) $request['duration_min'],
				'price_minor'  => (int) $request['price_minor'],
				'is_trial'     => $request['is_trial'] ? 1 : 0,
				'level'        => (string) ( $request['level'] ?? '' ),
				'curriculum'   => (string) ( $request['curriculum'] ?? '' ),
				'status'       => (string) ( $request['status'] ?? 'active' ),
			)
		);

		if ( $id <= 0 ) {
			return new WP_Error(
				'plumberslot_subject_save_failed',
				__( 'Could not save the subject.', 'plumberslot' ),
				array( 'status' => 500 )
			);
		}

		AuditLog::record( 'subject.created', 'subject', $id, array( 'tutor_id' => $tutor_id ) );
		$row = $this->subjects->find_for_tutor( $id, $tutor_id );

		return $this->ok( $this->present( $row, $tutor ), 201 );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tutor_id   = (int) $request['tutor_id'];
		$subject_id = (int) $request['id'];
		$tutor      = $this->tutors->find( $tutor_id );
		$existing   = $this->subjects->find_for_tutor( $subject_id, $tutor_id );

		if ( ! $tutor || ! $existing ) {
			return $this->guard->deny();
		}

		$body = (array) $request->get_json_params();
		$ok   = $this->subjects->update_for_tutor( $subject_id, $tutor_id, $body );

		if ( ! $ok ) {
			return new WP_Error(
				'plumberslot_subject_save_failed',
				__( 'Could not update the subject.', 'plumberslot' ),
				array( 'status' => 500 )
			);
		}

		AuditLog::record( 'subject.updated', 'subject', $subject_id );

		return $this->ok( $this->present( $this->subjects->find_for_tutor( $subject_id, $tutor_id ), $tutor ) );
	}

	public function destroy( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tutor_id   = (int) $request['tutor_id'];
		$subject_id = (int) $request['id'];

		if ( ! $this->subjects->find_for_tutor( $subject_id, $tutor_id ) ) {
			return $this->guard->deny();
		}

		if ( ! $this->subjects->delete_for_tutor( $subject_id, $tutor_id ) ) {
			return new WP_Error(
				'plumberslot_subject_delete_failed',
				__( 'Could not delete the subject.', 'plumberslot' ),
				array( 'status' => 500 )
			);
		}

		AuditLog::record( 'subject.deleted', 'subject', $subject_id );

		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present( ?object $row, ?object $tutor = null ): array {
		if ( ! $row ) {
			return array();
		}

		return array(
			'id'           => (int) $row->id,
			'tutor_id'     => (int) $row->tutor_id,
			'name'         => (string) $row->name,
			'level'        => $row->level,
			'curriculum'   => $row->curriculum,
			'duration_min' => (int) $row->duration_min,
			'price_minor'  => (int) $row->price_minor,
			'currency'     => $tutor ? (string) $tutor->currency : 'USD',
			'is_trial'     => (bool) $row->is_trial,
			'status'       => (string) $row->status,
			'sort_order'   => (int) $row->sort_order,
		);
	}
}
