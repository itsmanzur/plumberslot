<?php
/**
 * /plumberslot/v1/relations — parent ↔ child links.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Rest;

use PlumberSlot\Database\Repository\RelationRepository;
use PlumberSlot\Support\AuditLog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class RelationsController extends AbstractController {

	public function __construct(
		Guard $guard,
		private readonly RelationRepository $relations
	) {
		parent::__construct( $guard );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/relations/children',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'children' ),
				'permission_callback' => array( $this, 'can_act' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/relations/pending',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'pending' ),
				'permission_callback' => array( $this, 'can_act' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/relations/invite',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'invite' ),
				'permission_callback' => array( $this, 'can_act' ),
				'args'                => array(
					'student_id'    => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'student_email' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					),
					'relation'      => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => 'guardian',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/relations/(?P<id>\d+)/confirm',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'confirm' ),
				'permission_callback' => array( $this, 'can_act' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
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

	public function children(): WP_REST_Response {
		$parent_id = get_current_user_id();
		$rows      = $this->relations->children_of( $parent_id, true );

		return $this->ok(
			array(
				'items' => array_map( array( $this, 'present' ), $rows ),
			)
		);
	}

	public function pending(): WP_REST_Response {
		$rows = $this->relations->pending_for_student( get_current_user_id() );

		return $this->ok(
			array(
				'items' => array_map( array( $this, 'present' ), $rows ),
			)
		);
	}

	public function invite( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$parent_id  = get_current_user_id();
		$student_id = (int) ( $request['student_id'] ?? 0 );

		if ( $student_id <= 0 && $request['student_email'] ) {
			$user = get_user_by( 'email', (string) $request['student_email'] );
			if ( ! $user ) {
				return new WP_Error(
					'plumberslot_unknown_student',
					__( 'No account matches that email. Ask your child to sign up first.', 'plumberslot' ),
					array( 'status' => 404 )
				);
			}
			$student_id = (int) $user->ID;
		}

		if ( $student_id <= 0 || $student_id === $parent_id ) {
			return new WP_Error(
				'plumberslot_bad_student',
				__( 'Choose a different student account to link.', 'plumberslot' ),
				array( 'status' => 422 )
			);
		}

		$id = $this->relations->invite( $parent_id, $student_id, (string) ( $request['relation'] ?? 'guardian' ) );
		AuditLog::record( 'relation.invited', 'relation', $id, array( 'student_id' => $student_id ) );

		$row = $this->relations->find( $id );

		return $this->ok( $this->present( $row ), 201 );
	}

	public function confirm( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->relations->find( (int) $request['id'] );
		if ( ! $row ) {
			return $this->guard->deny();
		}

		// Only the invited student (or a site manager) may confirm.
		if ( ! $this->guard->is_site_manager() && get_current_user_id() !== (int) $row->student_id ) {
			return $this->guard->deny();
		}

		$this->relations->confirm( (int) $row->id );
		AuditLog::record( 'relation.confirmed', 'relation', (int) $row->id );

		$fresh = $this->relations->find( (int) $row->id );

		return $this->ok( $this->present( $fresh ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present( ?object $row ): array {
		if ( ! $row ) {
			return array();
		}

		$parent  = get_userdata( (int) $row->parent_id );
		$student = get_userdata( (int) $row->student_id );

		return array(
			'id'            => (int) $row->id,
			'parent_id'     => (int) $row->parent_id,
			'parent_name'   => $parent ? $parent->display_name : '',
			'student_id'    => (int) $row->student_id,
			'student_name'  => $student ? $student->display_name : '',
			'student_email' => $student ? $student->user_email : '',
			'relation'      => (string) $row->relation,
			'confirmed'     => (bool) $row->confirmed,
			'created_at'    => (string) $row->created_at,
			'initials'      => $this->initials( $student ? $student->display_name : '' ),
		);
	}

	private function initials( string $name ): string {
		$parts = preg_split( '/\s+/', trim( $name ) );
		$parts = $parts ? $parts : array();
		$out   = '';
		foreach ( array_slice( $parts, 0, 2 ) as $part ) {
			$char = function_exists( 'mb_substr' ) ? mb_substr( $part, 0, 1 ) : substr( $part, 0, 1 );
			$out .= function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $char ) : strtoupper( $char );
		}

		return $out ? $out : '?';
	}
}
