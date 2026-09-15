<?php
/**
 * Tutors work from /tutor-dashboard — not wp-admin.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Frontend;

use PlumberSlot\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class TutorAdminGate {

	public function register(): void {
		add_action( 'admin_init', array( $this, 'block_tutor_admin' ) );
		add_filter( 'show_admin_bar', array( $this, 'hide_admin_bar' ) );
	}

	public function block_tutor_admin(): void {
		if ( ! is_user_logged_in() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( ! $this->is_tutor_only() ) {
			return;
		}

		wp_safe_redirect( home_url( '/tutor-dashboard/' ) );
		exit;
	}

	/**
	 * @param bool $show Whether the admin bar is shown.
	 */
	public function hide_admin_bar( bool $show ): bool {
		if ( $this->is_tutor_only() ) {
			return false;
		}

		return $show;
	}

	private function is_tutor_only(): bool {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		if ( user_can( $user, Capabilities::MANAGE_ALL ) || user_can( $user, 'manage_options' ) ) {
			return false;
		}

		return user_can( $user, Capabilities::MANAGE_OWN )
			&& in_array( Capabilities::ROLE_TUTOR, (array) $user->roles, true );
	}
}
