<?php
/**
 * /tutorslot/v1/audit — manager-only audit viewer.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Rest;

use TutorSlot\Support\AuditLog;
use TutorSlot\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class AuditController extends AbstractController {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/audit',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'limit' => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 200,
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

		if ( ! current_user_can( Capabilities::MANAGE_ALL ) ) {
			return $this->guard->deny();
		}

		return true;
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		$limit = (int) $request['limit'];
		$rows  = array();

		foreach ( AuditLog::recent( $limit ) as $row ) {
			$actor  = get_userdata( (int) $row->actor_id );
			$rows[] = array(
				'id'          => (int) $row->id,
				'actor_id'    => (int) $row->actor_id,
				'actor_name'  => $actor ? $actor->display_name : __( 'System', 'tutorslot' ),
				'action'      => (string) $row->action,
				'object_type' => (string) $row->object_type,
				'object_id'   => (int) $row->object_id,
				'meta'        => json_decode( (string) $row->meta, true ),
				'created_at'  => (string) $row->created_at,
			);
		}

		return $this->ok(
			array(
				'entries' => $rows,
				'total'   => AuditLog::count(),
			)
		);
	}
}
