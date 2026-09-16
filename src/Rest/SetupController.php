<?php
/**
 * /plumberslot/v1/setup — onboarding wizard completion + analytics.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Rest;

use PlumberSlot\Database\Repository\AvailabilityRepository;
use PlumberSlot\Database\Repository\ServiceRepository;
use PlumberSlot\Database\Repository\TechnicianRepository;
use PlumberSlot\Frontend\BookingPage;
use PlumberSlot\Support\AuditLog;
use PlumberSlot\Support\Capabilities;
use PlumberSlot\Support\Settings;
use PlumberSlot\Support\Validate;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class SetupController extends AbstractController {

	public function __construct(
		Guard $guard,
		private readonly TechnicianRepository $technicians,
		private readonly ServiceRepository $services,
		private readonly AvailabilityRepository $availability
	) {
		parent::__construct( $guard );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/setup',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'status' ),
					'permission_callback' => array( $this, 'can_setup' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'complete' ),
					'permission_callback' => array( $this, 'can_setup' ),
				),
			)
		);
	}

	public function can_setup( WP_REST_Request $request ): bool|WP_Error {
		$logged_in = $this->require_login();

		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}

		$nonce = $this->verify_nonce( $request );

		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		if ( ! current_user_can( Capabilities::MANAGE_OWN ) ) {
			return $this->guard->deny();
		}

		return true;
	}

	public function status(): WP_REST_Response {
		$technician_id = $this->technicians->technician_id_for_user( get_current_user_id() );
		$technician    = $technician_id ? $this->technicians->find( $technician_id ) : null;
		$started       = (int) get_user_meta( get_current_user_id(), 'plumberslot_setup_started_at', true );

		return $this->ok(
			array(
				'completed'   => (bool) get_user_meta( get_current_user_id(), 'plumberslot_setup_completed', true ),
				'started_at'  => $started ? $started : null,
				'setup_mode'  => Settings::string( 'setup_mode', 'solo' ),
				'technician'  => $technician ? array(
					'id'           => (int) $technician->id,
					'slug'         => (string) $technician->slug,
					'display_name' => (string) $technician->display_name,
					'status'       => (string) $technician->status,
				) : null,
				'shortcode'   => $technician ? sprintf( '[plumberslot technician="%s"]', esc_attr( (string) $technician->slug ) ) : '[plumberslot]',
				'booking_url' => $technician ? BookingPage::url_for_technician( $technician ) : home_url( '/' ),
				'payments'    => \PlumberSlot\Support\PaymentsStatus::snapshot(),
			)
		);
	}

	public function complete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body     = (array) $request->get_json_params();
		$user_id  = get_current_user_id();
		$mode     = sanitize_key( (string) ( $body['mode'] ?? 'solo' ) );
		$services = is_array( $body['services'] ?? null ) ? $body['services'] : array();
		$week     = $body['week'] ?? array();
		$payments = ! empty( $body['payments_enabled'] );
		$started  = (int) ( $body['started_at'] ?? get_user_meta( $user_id, 'plumberslot_setup_started_at', true ) );

		if ( $started <= 0 ) {
			$started = time();
		}

		update_user_meta( $user_id, 'plumberslot_setup_started_at', $started );

		$user       = wp_get_current_user();
		$technician = $this->technicians->find_by_user( $user_id );

		if ( ! $technician ) {
			$display = $user->display_name ? $user->display_name : $user->user_login;
			$slug    = sanitize_title( $display );

			if ( '' === $slug || $this->technicians->find_by_slug( $slug ) ) {
				$slug = sanitize_title( $display . '-' . $user_id );
			}

			$id = $this->technicians->create(
				array(
					'user_id'      => $user_id,
					'slug'         => $slug,
					'display_name' => $display,
					'timezone'     => wp_timezone_string(),
					'status'       => 'active',
				)
			);
			$user->add_role( Capabilities::ROLE_TECHNICIAN );
			$technician = $this->technicians->find( $id );
		} else {
			$this->technicians->update(
				(int) $technician->id,
				array(
					'status' => 'active',
				)
			);
			$technician = $this->technicians->find( (int) $technician->id );
		}

		$technician_id = (int) $technician->id;

		foreach ( $services as $name ) {
			$name = sanitize_text_field( (string) $name );

			if ( '' === $name ) {
				continue;
			}

			$this->services->create(
				$technician_id,
				array(
					'name'         => $name,
					'duration_min' => Settings::int( 'default_lesson_minutes', 60 ),
					'status'       => 'active',
				)
			);
		}

		// A technician who finishes the wizard with no services yet (skipped
		// the services step, or re-runs setup after clearing their list) gets
		// a friendlier starting point than an empty booking widget. Skipped
		// entirely once they have any service of their own, so this never
		// runs twice and never overwrites something they built themselves.
		if ( array() === $this->services->all_for_technician( $technician_id ) ) {
			$this->seed_starter_services( $technician_id );
		}

		if ( is_array( $week ) && array() !== $week ) {
			$clean = Validate::sanitize_week( $week );

			if ( is_wp_error( $clean ) ) {
				return $clean;
			}

			if ( ! $this->availability->replace_week( $technician_id, $clean ) ) {
				return new WP_Error(
					'plumberslot_setup_availability_failed',
					__( 'Could not save your weekly hours.', 'plumberslot' ),
					array( 'status' => 500 )
				);
			}
		}

		Settings::update(
			array(
				'payments_enabled' => $payments,
				'setup_mode'       => $mode,
			)
		);

		BookingPage::ensure( (string) $technician->slug );
		$booking_url = BookingPage::url_for_technician( $technician );

		$elapsed     = max( 0, time() - $started );
		$bookable_at = time();

		update_user_meta( $user_id, 'plumberslot_setup_completed', 1 );
		update_user_meta( $user_id, 'plumberslot_setup_elapsed_seconds', $elapsed );
		update_option(
			'plumberslot_setup_analytics',
			array(
				'elapsed_seconds'             => $elapsed,
				'time_to_first_bookable_slot' => $elapsed,
				'completed_at'                => gmdate( 'c' ),
				'technician_id'               => $technician_id,
				'mode'                        => $mode,
			),
			false
		);

		AuditLog::record(
			'setup.completed',
			'technician',
			$technician_id,
			array(
				'elapsed'  => $elapsed,
				'bookable' => $bookable_at,
				'mode'     => $mode,
			)
		);

		return $this->ok(
			array(
				'completed'                   => true,
				'elapsed_seconds'             => $elapsed,
				'time_to_first_bookable_slot' => $elapsed,
				'setup_mode'                  => $mode,
				'shortcode'                   => sprintf( '[plumberslot technician="%s"]', esc_attr( (string) $technician->slug ) ),
				'booking_url'                 => $booking_url,
				'technician_id'               => $technician_id,
			)
		);
	}

	/**
	 * Four editable, deletable placeholder services covering the common
	 * plumbing job shapes -- a friendlier default than a blank services list,
	 * not a locked-in default. Each row is created independently so one bad
	 * row can't take the others down, and any failure here is swallowed
	 * rather than surfaced, since this is a nice-to-have and must never block
	 * wizard completion.
	 */
	private function seed_starter_services( int $technician_id ): void {
		$starters = array(
			array(
				'name'         => __( 'Drain Cleaning', 'plumberslot' ),
				'category'     => __( 'Repair', 'plumberslot' ),
				'duration_min' => 60,
				'price_minor'  => 15000,
			),
			array(
				'name'         => __( 'Water Heater Service', 'plumberslot' ),
				'category'     => __( 'Installation & Repair', 'plumberslot' ),
				'duration_min' => 90,
				'price_minor'  => 22000,
			),
			array(
				'name'                   => __( 'Leak Repair', 'plumberslot' ),
				'category'               => __( 'Repair', 'plumberslot' ),
				'duration_min'           => 60,
				'price_minor'            => 17500,
				'is_emergency_available' => 1,
			),
			array(
				'name'             => __( 'Free Estimate / Inspection', 'plumberslot' ),
				'category'         => __( 'Estimate', 'plumberslot' ),
				'duration_min'     => 30,
				'price_minor'      => 0,
				'is_free_estimate' => 1,
			),
		);

		foreach ( $starters as $starter ) {
			try {
				$this->services->create( $technician_id, $starter );
			} catch ( \Throwable $error ) {
				// Best-effort convenience default -- never block setup completion.
				$error = null;
			}
		}
	}
}
