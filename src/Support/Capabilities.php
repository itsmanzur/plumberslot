<?php
/**
 * Custom capabilities.
 *
 * Never reuse manage_options. A technician needs to manage their own jobs and
 * nothing else; handing them an administrator-adjacent capability is how a
 * booking plugin turns into a site takeover.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Support;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	public const BOOK               = 'plumberslot_book';
	public const MANAGE_OWN         = 'plumberslot_manage_own';
	public const MANAGE_ALL         = 'plumberslot_manage_all';
	public const VIEW_REPORTS       = 'plumberslot_view_reports';
	public const MANAGE_TECHNICIANS = 'plumberslot_manage_technicians';

	public const ROLE_TECHNICIAN = 'plumberslot_technician';
	public const ROLE_CUSTOMER   = 'plumberslot_customer';

	public static function add_all(): void {
		add_role(
			self::ROLE_TECHNICIAN,
			__( 'Technician', 'plumberslot' ),
			array(
				'read'             => true,
				self::BOOK         => true,
				self::MANAGE_OWN   => true,
				self::VIEW_REPORTS => true,
			)
		);

		add_role(
			self::ROLE_CUSTOMER,
			__( 'Customer', 'plumberslot' ),
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
		remove_role( self::ROLE_TECHNICIAN );
		remove_role( self::ROLE_CUSTOMER );

		$admin = get_role( 'administrator' );

		if ( $admin ) {
			foreach ( self::all() as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}

	/** @return list<string> */
	public static function all(): array {
		return array( self::BOOK, self::MANAGE_OWN, self::MANAGE_ALL, self::VIEW_REPORTS, self::MANAGE_TECHNICIANS );
	}
}
