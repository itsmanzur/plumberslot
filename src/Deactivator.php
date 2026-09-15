<?php
/**
 * Runs on deactivation. Never destroys data.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot;

defined( 'ABSPATH' ) || exit;

final class Deactivator {

	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'plumberslot' );
		}

		wp_cache_flush_group( 'plumberslot' );
		flush_rewrite_rules();
	}
}
