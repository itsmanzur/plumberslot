<?php
/**
 * Admin screens.
 *
 * One React root per page, fed by REST. Nothing is rendered server-side beyond
 * a mount point, so the same API the front end uses is the only way data leaves
 * the database — one surface to secure instead of two.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Admin;

use PlumberSlot\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class AdminMenu {

	private const SLUG = 'plumberslot';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function add_pages(): void {
		add_menu_page(
			__( 'PlumberSlot', 'plumberslot' ),
			__( 'PlumberSlot', 'plumberslot' ),
			Capabilities::MANAGE_OWN,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-admin-tools',
			26
		);

		$pages = array(
			'plumberslot-availability' => __( 'Availability', 'plumberslot' ),
			'plumberslot-services'     => __( 'Services', 'plumberslot' ),
			'plumberslot-bookings'     => __( 'Bookings', 'plumberslot' ),
			'plumberslot-help'         => __( 'Help & Docs', 'plumberslot' ),
		);

		foreach ( $pages as $slug => $title ) {
			add_submenu_page( self::SLUG, $title, $title, Capabilities::MANAGE_OWN, $slug, array( $this, 'render' ) );
		}

		// Technician and settings management is a site-manager job, not a technician one.
		add_submenu_page(
			self::SLUG,
			__( 'Technicians', 'plumberslot' ),
			__( 'Technicians', 'plumberslot' ),
			Capabilities::MANAGE_TECHNICIANS,
			'plumberslot-technicians',
			array( $this, 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Settings', 'plumberslot' ),
			__( 'Settings', 'plumberslot' ),
			Capabilities::MANAGE_ALL,
			'plumberslot-settings',
			array( $this, 'render' )
		);
	}

	public function render(): void {
		$screen = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::SLUG; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.

		if ( ! self::assets_available() ) {
			self::render_missing_assets();
			return;
		}

		printf( '<div class="wrap"><div id="plumberslot-admin-root" data-screen="%s"></div></div>', esc_attr( $screen ) );
	}

	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, 'plumberslot' ) ) {
			return;
		}

		if ( ! self::assets_available() ) {
			return;
		}

		$asset_file = PLUMBERSLOT_PATH . 'assets/dist/admin.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => \PlumberSlot\VERSION,
		);
		$technician_id   = ( new \PlumberSlot\Database\Repository\TechnicianRepository() )->technician_id_for_user( get_current_user_id() );
		$page       = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.

		/**
		 * Filters the externally hosted Help & Docs product-tour URL.
		 *
		 * Use a public YouTube, Vimeo or similar HTTPS watch-page URL. PlumberSlot
		 * links to the platform and does not bundle or embed the video.
		 *
		 * @since 0.1.0
		 *
		 * @param string $video_url External product-tour URL. Empty by default.
		 */
		$help_video_url = apply_filters( 'plumberslot_help_video_url', '' );

		wp_enqueue_script( 'plumberslot-admin', PLUMBERSLOT_URL . 'assets/dist/admin.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'plumberslot-admin', PLUMBERSLOT_URL . 'assets/dist/admin.css', array(), $asset['version'] );

		wp_set_script_translations( 'plumberslot-admin', 'plumberslot', PLUMBERSLOT_PATH . 'languages' );

		wp_localize_script(
			'plumberslot-admin',
			'plumberslotAdmin',
			array(
				'root'             => esc_url_raw( rest_url( 'plumberslot/v1' ) ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'technicianId'     => $technician_id,
				'timezone'         => wp_timezone_string(),
				'version'          => \PlumberSlot\VERSION,
				'initialDashboard' => self::SLUG === $page ? $this->preload_dashboard( $technician_id ) : null,
				'user'             => array(
					'id'   => get_current_user_id(),
					'name' => wp_get_current_user()->display_name,
				),
				'caps'             => array(
					'manageOwn'    => current_user_can( Capabilities::MANAGE_OWN ),
					'manageTechnicians' => current_user_can( Capabilities::MANAGE_TECHNICIANS ),
					'manageAll'    => current_user_can( Capabilities::MANAGE_ALL ),
					'viewReports'  => current_user_can( Capabilities::VIEW_REPORTS ),
				),
				'canAll'           => current_user_can( Capabilities::MANAGE_ALL ),
				'screens'          => array(
					'dashboard'    => 'plumberslot',
					'availability' => 'plumberslot-availability',
					'services'     => 'plumberslot-services',
					'bookings'     => 'plumberslot-bookings',
					'technicians'       => 'plumberslot-technicians',
					'settings'     => 'plumberslot-settings',
					'setup'        => 'plumberslot-setup',
					'help'         => 'plumberslot-help',
				),
				'urls'             => array(
					'admin'       => admin_url( 'admin.php' ),
					'home'        => home_url( '/' ),
					'bookingPage' => home_url( '/book/' ),
				),
				'docs'             => array(
					'videoUrl' => esc_url_raw( $help_video_url ),
				),
				'payments'         => \PlumberSlot\Support\PaymentsStatus::snapshot(),
			)
		);
	}

	/**
	 * Avoid a second WordPress bootstrap before the dashboard can first render.
	 *
	 * The regular REST callback still performs ownership checks and response
	 * masking; only the transport round trip is removed from the initial view.
	 *
	 * @return array<string, mixed>|null
	 */
	private function preload_dashboard( int $technician_id ): ?array {
		if ( $technician_id <= 0 ) {
			return null;
		}

		$request = new \WP_REST_Request( 'GET', '/plumberslot/v1/dashboard' );
		$request->set_param( 'technician_id', $technician_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_do_request( $request );

		if ( 200 !== $response->get_status() ) {
			return null;
		}

		$data = $response->get_data();

		return is_array( $data ) ? $data : null;
	}

	public static function assets_available(): bool {
		return is_readable( PLUMBERSLOT_PATH . 'assets/dist/admin.js' )
			&& is_readable( PLUMBERSLOT_PATH . 'assets/dist/admin.css' );
	}

	public static function render_missing_assets(): void {
		printf(
			'<div class="wrap"><h1>%1$s</h1><div class="notice notice-warning inline"><p><strong>%2$s</strong> %3$s</p></div></div>',
			esc_html__( 'PlumberSlot', 'plumberslot' ),
			esc_html__( 'The interface assets have not been built yet.', 'plumberslot' ),
			esc_html__( 'Run npm install and npm run build in the PlumberSlot plugin directory, then reload this page.', 'plumberslot' )
		);
	}
}
