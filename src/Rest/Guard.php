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

use PlumberSlot\Database\Repository\TutorRepository;
use PlumberSlot\Database\Schema;
use PlumberSlot\Support\Capabilities;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Guard {

	public function __construct( private readonly TutorRepository $tutors ) {}

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
	 * Can the current user act on this tutor's schedule?
	 */
	public function owns_tutor( int $tutor_id ): bool {
		if ( $this->is_site_manager() ) {
			return true;
		}

		if ( ! current_user_can( Capabilities::MANAGE_OWN ) ) {
			return false;
		}

		return $tutor_id > 0 && $this->tutors->tutor_id_for_user( get_current_user_id() ) === $tutor_id;
	}

	/**
	 * Can the current user see or change this booking?
	 *
	 * Four legitimate parties: the student, the paying parent, the tutor who
	 * teaches it, and a site manager. Everyone else gets a 404.
	 */
	public function may_touch_booking( object $booking ): bool {
		if ( $this->is_site_manager() ) {
			return true;
		}

		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( (int) $booking->student_id === $user_id ) {
			return true;
		}

		if ( null !== $booking->parent_id && (int) $booking->parent_id === $user_id ) {
			return true;
		}

		return $this->tutors->tutor_id_for_user( $user_id ) === (int) $booking->tutor_id;
	}

	/**
	 * Is this user really the guardian of this student?
	 *
	 * Checked on every parent-scoped read, so a parent account cannot widen
	 * itself into somebody else's child by editing an id in the request.
	 */
	public function is_guardian_of( int $parent_id, int $student_id ): bool {
		global $wpdb;

		if ( $parent_id === $student_id ) {
			return true;
		}

		$table = Schema::table( Schema::RELATIONS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- relationship lookup is direct and the table name is resolved from the internal whitelist.
		$relation_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $table . ' WHERE parent_id = %d AND student_id = %d AND confirmed = 1',
				$parent_id,
				$student_id
			)
		);
		// phpcs:enable

		return (bool) $relation_id;
	}
}
