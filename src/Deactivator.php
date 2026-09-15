<?php
/**
 * Runs on deactivation. Never destroys data.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot;

defined( 'ABSPATH' ) || exit;

final class Deactivator {

	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'tutorslot' );
		}

		wp_cache_flush_group( 'tutorslot' );
		flush_rewrite_rules();
	}
}
