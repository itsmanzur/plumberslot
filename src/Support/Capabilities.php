<?php
/**
 * Custom capabilities.
 *
 * Never reuse manage_options. A tutor needs to manage their own lessons and
 * nothing else; handing them an administrator-adjacent capability is how a
 * booking plugin turns into a site takeover.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Support;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	public const BOOK          = 'tutorslot_book';
	public const MANAGE_OWN    = 'tutorslot_manage_own';
	public const MANAGE_ALL    = 'tutorslot_manage_all';
	public const VIEW_REPORTS  = 'tutorslot_view_reports';
	public const MANAGE_TUTORS = 'tutorslot_manage_tutors';

	public const ROLE_TUTOR   = 'tutorslot_tutor';
	public const ROLE_STUDENT = 'tutorslot_student';
	public const ROLE_PARENT  = 'tutorslot_parent';

	public static function add_all(): void {
		add_role(
			self::ROLE_TUTOR,
			__( 'Tutor', 'tutorslot' ),
			array(
				'read'             => true,
				self::BOOK         => true,
				self::MANAGE_OWN   => true,
				self::VIEW_REPORTS => true,
			)
		);

		add_role(
			self::ROLE_STUDENT,
			__( 'Student', 'tutorslot' ),
			array(
				'read'     => true,
				self::BOOK => true,
			)
		);

		add_role(
			self::ROLE_PARENT,
			__( 'Parent', 'tutorslot' ),
			array(
				'read'     => true,
				self::BOOK => true,
			)
		);

		$admin = get_role( 'administrator' );

		if ( $admin ) {
			foreach ( self::all() as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	public static function remove_all(): void {
		remove_role( self::ROLE_TUTOR );
		remove_role( self::ROLE_STUDENT );
		remove_role( self::ROLE_PARENT );

		$admin = get_role( 'administrator' );

		if ( $admin ) {
			foreach ( self::all() as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}

	/** @return list<string> */
	public static function all(): array {
		return array( self::BOOK, self::MANAGE_OWN, self::MANAGE_ALL, self::VIEW_REPORTS, self::MANAGE_TUTORS );
	}
}
