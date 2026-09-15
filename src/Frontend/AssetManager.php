<?php
/**
 * Front-end assets.
 *
 * Loaded only on pages that actually contain the widget. A booking plugin that
 * enqueues itself site-wide is a booking plugin that shows up in every Core Web
 * Vitals report the site owner ever runs.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Frontend;

defined( 'ABSPATH' ) || exit;

final class AssetManager {

	private bool $needed = false;

	private bool $dashboard_needed = false;

	private string $dashboard_view = 'technician';

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ), 20 );
	}

	/**
	 * Called by the shortcode and the block when they render.
	 */
	public function mark_needed(): void {
		$this->needed = true;

		if ( did_action( 'wp_enqueue_scripts' ) ) {
			$this->enqueue_widget();
		}
	}

	public function mark_dashboard_needed( string $view = 'technician' ): void {
		$this->dashboard_needed = true;
		$this->dashboard_view   = 'technician' === $view ? $view : 'technician';

		if ( did_action( 'wp_enqueue_scripts' ) ) {
			$this->enqueue_dashboard();
		}
	}

	public function maybe_enqueue(): void {
		global $post;

		if ( $this->needed
			|| ( $post instanceof \WP_Post
				&& ( has_shortcode( (string) $post->post_content, 'plumberslot' ) || has_block( 'plumberslot/booking', $post ) ) ) ) {
			$this->enqueue_widget();
		}

		if ( $this->dashboard_needed
			|| ( $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, 'plumberslot_dashboard' ) ) ) {
			$this->enqueue_dashboard();
		}
	}

	private function enqueue_widget(): void {
		if ( wp_script_is( 'plumberslot-widget', 'enqueued' ) || ! $this->available() ) {
			return;
		}

		$asset = $this->asset_metadata( 'widget' );

		wp_enqueue_script( 'plumberslot-widget', PLUMBERSLOT_URL . 'assets/dist/widget.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'plumberslot-widget', PLUMBERSLOT_URL . 'assets/dist/widget.css', array(), $asset['version'] );
		wp_set_script_translations( 'plumberslot-widget', 'plumberslot', PLUMBERSLOT_PATH . 'languages' );
		wp_localize_script( 'plumberslot-widget', 'plumberslotWidget', $this->boot_payload( 'widget' ) );
	}

	private function enqueue_dashboard(): void {
		if ( wp_script_is( 'plumberslot-dashboard', 'enqueued' ) || ! $this->dashboard_available() ) {
			return;
		}

		$asset = $this->asset_metadata( 'dashboard' );

		wp_enqueue_script( 'plumberslot-dashboard', PLUMBERSLOT_URL . 'assets/dist/dashboard.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'plumberslot-dashboard', PLUMBERSLOT_URL . 'assets/dist/dashboard.css', array(), $asset['version'] );
		wp_set_script_translations( 'plumberslot-dashboard', 'plumberslot', PLUMBERSLOT_PATH . 'languages' );
		wp_localize_script( 'plumberslot-dashboard', 'plumberslotDashboard', $this->boot_payload( 'dashboard' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function boot_payload( string $surface ): array {
		$user        = wp_get_current_user();
		$technician_id    = 0;
		$current_url = $this->current_public_url();

		if ( is_user_logged_in() ) {
			$technician_id = ( new \PlumberSlot\Database\Repository\TechnicianRepository() )->technician_id_for_user( get_current_user_id() );
		}

		return array(
			'root'         => esc_url_raw( rest_url( 'plumberslot/v1' ) ),
			'nonce'        => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'loggedIn'     => is_user_logged_in(),
			'loginUrl'     => wp_login_url( $current_url ),
			'dashboardUrl' => home_url( '/technician-dashboard/' ),
			'technicianDashUrl' => home_url( '/technician-dashboard/' ),
			'bookingUrl'   => $this->booking_page_url(),
			'view'         => $this->dashboard_view,
			'surface'      => $surface,
			'technicianId' => $technician_id,
			'user'         => is_user_logged_in()
				? array(
					'id'    => get_current_user_id(),
					'name'  => $user->display_name,
					'email' => $user->user_email,
					'roles' => array_values( (array) $user->roles ),
				)
				: null,
			'payments'     => $this->configured_payments(),
			'i18n'         => array(
				/* translators: %s: viewer's timezone name. */
				'timezoneNotice' => __( 'Times shown in %s', 'plumberslot' ),
			),
		);
	}

	/**
	 * Full current front-end URL (path + query) so login can return to the booking page.
	 */
	private function current_public_url(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- rebuilt via home_url + esc.
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );
		$query       = wp_parse_url( $request_uri, PHP_URL_QUERY );
		$url         = home_url( is_string( $path ) && '' !== $path ? $path : '/' );

		if ( is_string( $query ) && '' !== $query ) {
			$url = $url . '?' . $query;
		}

		$permalink = get_permalink();
		if ( ( ! is_string( $path ) || '/' === $path || '' === $path ) && is_string( $permalink ) && '' !== $permalink && ! $query ) {
			return $permalink;
		}

		return esc_url_raw( $url );
	}

	private function booking_page_url(): string {
		$page_id = (int) \PlumberSlot\Support\Settings::int( 'booking_page_id', 0 );

		if ( $page_id > 0 ) {
			$url = get_permalink( $page_id );

			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return home_url( '/' );
	}

	/**
	 * @return array{enabled:bool,stripe:bool,bkash:bool,online_ready:bool,needs_gateway:bool}
	 */
	private function configured_payments(): array {
		return \PlumberSlot\Support\PaymentsStatus::snapshot();
	}

	public function available(): bool {
		return is_readable( PLUMBERSLOT_PATH . 'assets/dist/widget.js' )
			&& is_readable( PLUMBERSLOT_PATH . 'assets/dist/widget.css' );
	}

	public function dashboard_available(): bool {
		return is_readable( PLUMBERSLOT_PATH . 'assets/dist/dashboard.js' )
			&& is_readable( PLUMBERSLOT_PATH . 'assets/dist/dashboard.css' );
	}

	/**
	 * @return array{dependencies:array<int, string>,version:string}
	 */
	private function asset_metadata( string $entry ): array {
		$path  = PLUMBERSLOT_PATH . 'assets/dist/' . $entry . '.asset.php';
		$asset = is_readable( $path ) ? require $path : array();

		return array(
			'dependencies' => isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] )
				? array_values( $asset['dependencies'] )
				: array(),
			'version'      => isset( $asset['version'] ) && is_string( $asset['version'] )
				? $asset['version']
				: \PlumberSlot\VERSION,
		);
	}
}
