<?php
/**
 * Authorisation checks shared by every controller.
 *
 * The golden rule of this plugin lives here: an id arriving in a request is a
 * question, never an answer. CVE-2026-2931 in a competing booking plugin let a
 * customer-level account reset an administrator's password, and the whole bug
 * was one handler trusting a user-supplied id. Every read and every write in
 * PlumberSlot goes through an ownership check before it touches a row.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Rest;

use PlumberSlot\Database\Repository\TechnicianRepository;
use PlumberSlot\Support\Capabilities;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Guard {

	public function __construct( private readonly TechnicianRepository $technicians ) {}

	/**
	 * A deliberately indistinct rejection.
	 *
	 * Returning 403 for "exists but not yours" and 404 for "does not exist"
	 * turns any endpoint into an enumeration oracle. Both answer 404.
	 */
	public function deny(): WP_Error {
		return new WP_Error(
			'plumberslot_not_found',
			__( 'Not found.', 'plumberslot' ),
			array( 'status' => 404 )
		);
	}

	public function is_site_manager(): bool {
		return current_user_can( Capabilities::MANAGE_ALL );
	}

	/**
	 * Can the current user act on this technician's schedule?
	 */
	public function owns_technician( int $technician_id ): bool {
		if ( $this->is_site_manager() ) {
			return true;
		}

		if ( ! current_user_can( Capabilities::MANAGE_OWN ) ) {
			return false;
		}

		return $technician_id > 0 && $this->technicians->technician_id_for_user( get_current_user_id() ) === $technician_id;
	}

	/**
	 * Can the current user see or change this booking?
	 *
	 * Three legitimate parties: the customer, the technician who does the
	 * work, and a site manager. Everyone else gets a 404.
	 */
	public function may_touch_booking( object $booking ): bool {
		if ( $this->is_site_manager() ) {
			return true;
		}

		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( (int) $booking->customer_id === $user_id ) {
			return true;
		}

		return $this->technicians->technician_id_for_user( $user_id ) === (int) $booking->technician_id;
	}
}
