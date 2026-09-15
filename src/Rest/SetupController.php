<?php
/**
 * /plumberslot/v1/setup — onboarding wizard completion + analytics.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Rest;

use PlumberSlot\Database\Repository\AvailabilityRepository;
use PlumberSlot\Database\Repository\SubjectRepository;
use PlumberSlot\Database\Repository\TutorRepository;
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
		private readonly TutorRepository $tutors,
		private readonly SubjectRepository $subjects,
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
		$tutor_id = $this->tutors->tutor_id_for_user( get_current_user_id() );
		$tutor    = $tutor_id ? $this->tutors->find( $tutor_id ) : null;
		$started  = (int) get_user_meta( get_current_user_id(), 'plumberslot_setup_started_at', true );

		return $this->ok(
			array(
				'completed'   => (bool) get_user_meta( get_current_user_id(), 'plumberslot_setup_completed', true ),
				'started_at'  => $started ? $started : null,
				'setup_mode'  => Settings::string( 'setup_mode', 'solo' ),
				'tutor'       => $tutor ? array(
					'id'           => (int) $tutor->id,
					'slug'         => (string) $tutor->slug,
					'display_name' => (string) $tutor->display_name,
					'status'       => (string) $tutor->status,
				) : null,
				'shortcode'   => $tutor ? sprintf( '[plumberslot tutor="%s"]', esc_attr( (string) $tutor->slug ) ) : '[plumberslot]',
				'booking_url' => $tutor ? BookingPage::url_for_tutor( $tutor ) : home_url( '/' ),
				'payments'    => \PlumberSlot\Support\PaymentsStatus::snapshot(),
			)
		);
	}

	public function complete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body     = (array) $request->get_json_params();
		$user_id  = get_current_user_id();
		$mode     = sanitize_key( (string) ( $body['mode'] ?? 'solo' ) );
		$subjects = is_array( $body['subjects'] ?? null ) ? $body['subjects'] : array();
		$week     = $body['week'] ?? array();
		$payments = ! empty( $body['payments_enabled'] );
		$started  = (int) ( $body['started_at'] ?? get_user_meta( $user_id, 'plumberslot_setup_started_at', true ) );

		if ( $started <= 0 ) {
			$started = time();
		}

		update_user_meta( $user_id, 'plumberslot_setup_started_at', $started );

		$user  = wp_get_current_user();
		$tutor = $this->tutors->find_by_user( $user_id );

		if ( ! $tutor ) {
			$display = $user->display_name ? $user->display_name : $user->user_login;
			$slug    = sanitize_title( $display );

			if ( '' === $slug || $this->tutors->find_by_slug( $slug ) ) {
				$slug = sanitize_title( $display . '-' . $user_id );
			}

			$id = $this->tutors->create(
				array(
					'user_id'          => $user_id,
					'slug'             => $slug,
					'display_name'     => $display,
					'timezone'         => wp_timezone_string(),
					'status'           => 'active',
					'payout_share_pct' => 'centre' === $mode ? 70 : 100,
				)
			);
			$user->add_role( Capabilities::ROLE_TUTOR );
			$tutor = $this->tutors->find( $id );
		} else {
			$this->tutors->update(
				(int) $tutor->id,
				array(
					'status'           => 'active',
					'payout_share_pct' => 'centre' === $mode ? (int) $tutor->payout_share_pct : 100,
				)
			);
			$tutor = $this->tutors->find( (int) $tutor->id );
		}

		$tutor_id = (int) $tutor->id;

		foreach ( $subjects as $name ) {
			$name = sanitize_text_field( (string) $name );

			if ( '' === $name ) {
				continue;
			}

			$this->subjects->create(
				$tutor_id,
				array(
					'name'         => $name,
					'duration_min' => Settings::int( 'default_lesson_minutes', 60 ),
					'status'       => 'active',
				)
			);
		}

		if ( is_array( $week ) && array() !== $week ) {
			$clean = Validate::sanitize_week( $week );

			if ( is_wp_error( $clean ) ) {
				return $clean;
			}

			if ( ! $this->availability->replace_week( $tutor_id, $clean ) ) {
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

		BookingPage::ensure( (string) $tutor->slug );
		$booking_url = BookingPage::url_for_tutor( $tutor );

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
				'tutor_id'                    => $tutor_id,
				'mode'                        => $mode,
			),
			false
		);

		AuditLog::record(
			'setup.completed',
			'tutor',
			$tutor_id,
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
				'shortcode'                   => sprintf( '[plumberslot tutor="%s"]', esc_attr( (string) $tutor->slug ) ),
				'booking_url'                 => $booking_url,
				'tutor_id'                    => $tutor_id,
			)
		);
	}
}
