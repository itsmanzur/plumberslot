<?php
/**
 * Four steps from activation to a working booking link.
 *
 * The number this screen is judged against is time to first bookable slot,
 * target under ninety seconds. Anything that does not move a new tutor closer
 * to a link they can paste does not belong in the wizard.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Admin;

use PlumberSlot\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class OnboardingWizard {

	private const SLUG = 'plumberslot-setup';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
	}

	public function add_page(): void {
		// Registered without a parent so it never clutters the menu.
		add_submenu_page(
			'',
			__( 'Set up PlumberSlot', 'plumberslot' ),
			__( 'Set up PlumberSlot', 'plumberslot' ),
			Capabilities::MANAGE_OWN,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Send a freshly activated site straight into the wizard, exactly once.
	 */
	public function maybe_redirect(): void {
		if ( ! get_transient( 'plumberslot_show_onboarding' ) ) {
			return;
		}
		if ( wp_doing_ajax() || ! current_user_can( Capabilities::MANAGE_OWN ) ) {
			return;
		}

		delete_transient( 'plumberslot_show_onboarding' );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	public function render(): void {
		if ( ! AdminMenu::assets_available() ) {
			AdminMenu::render_missing_assets();
			return;
		}

		echo '<div class="wrap"><div id="plumberslot-setup-root" data-screen="plumberslot-setup"></div></div>';
	}
}
