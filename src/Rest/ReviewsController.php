<?php
/**
 * /plumberslot/v1/bookings/{id}/review, /plumberslot/v1/reviews
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Rest;

use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Repository\ReviewRepository;
use PlumberSlot\Database\Repository\TechnicianRepository;
use PlumberSlot\Support\AuditLog;
use PlumberSlot\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class ReviewsController extends AbstractController {

	public function __construct(
		Guard $guard,
		private readonly ReviewRepository $reviews,
		private readonly BookingRepository $bookings,
		private readonly TechnicianRepository $technicians
	) {
		parent::__construct( $guard );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/bookings/(?P<id>\d+)/review',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => array( $this, 'can_submit' ),
				'args'                => array(
					'id'     => array( 'sanitize_callback' => 'absint' ),
					'rating' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'body'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/reviews',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/reviews/(?P<id>\d+)/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'moderate' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'id'     => array( 'sanitize_callback' => 'absint' ),
					'status' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'approved', 'rejected' ),
					),
				),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Permissions
	 * ------------------------------------------------------------------ */

	public function can_submit( WP_REST_Request $request ): bool|WP_Error {
		$logged_in = $this->require_login();

		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}

		return $this->verify_nonce( $request );
	}

	/**
	 * Manager-only, same gating shape as DashboardController::can_view_snapshot().
	 */
	public function can_manage( WP_REST_Request $request ): bool|WP_Error {
		$logged_in = $this->require_login();

		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}

		$nonce = $this->verify_nonce( $request );

		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		if ( ! current_user_can( Capabilities::MANAGE_ALL ) ) {
			return $this->guard->deny();
		}

		return true;
	}

	/* ------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	public function submit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$booking_id = (int) $request['id'];
		$booking    = $this->bookings->find( $booking_id );

		if ( ! $booking ) {
			return $this->guard->deny();
		}

		// Only the booking's own customer -- or a manager acting on their
		// behalf -- may leave a review for it. This is the real, server-side
		// gate; nothing about who a review is "for" is ever taken from the
		// request body.
		$user_id = get_current_user_id();

		if ( (int) $booking->customer_id !== $user_id && ! current_user_can( Capabilities::MANAGE_ALL ) ) {
			return $this->guard->deny();
		}

		if ( 'completed' !== (string) $booking->status ) {
			return new WP_Error(
				'plumberslot_review_not_completed',
				__( 'A review can only be submitted once the job is marked completed.', 'plumberslot' ),
				array( 'status' => 409 )
			);
		}

		// Checked here (not just left to the schema's UNIQUE(booking_id)) so
		// a duplicate submission gets a clean, explainable error rather than
		// a raw SQL failure surfacing as a 500.
		if ( $this->reviews->for_booking( $booking_id ) ) {
			return new WP_Error(
				'plumberslot_review_exists',
				__( 'A review has already been submitted for this appointment.', 'plumberslot' ),
				array( 'status' => 409 )
			);
		}

		$rating = (int) $request['rating'];

		if ( $rating < 1 || $rating > 5 ) {
			return new WP_Error(
				'plumberslot_bad_rating',
				__( 'Rating must be between 1 and 5.', 'plumberslot' ),
				array( 'status' => 422 )
			);
		}

		$id = $this->reviews->create(
			array(
				'booking_id'    => $booking_id,
				'technician_id' => (int) $booking->technician_id,
				'author_id'     => $user_id,
				'rating'        => $rating,
				'body'          => $request['body'] ? (string) $request['body'] : null,
			)
		);

		if ( $id <= 0 ) {
			return new WP_Error(
				'plumberslot_review_save_failed',
				__( 'Could not save your review. Try again.', 'plumberslot' ),
				array( 'status' => 500 )
			);
		}

		AuditLog::record( 'review.submitted', 'review', $id, array( 'booking_id' => $booking_id ) );

		return $this->ok(
			array(
				'id'     => $id,
				'status' => 'pending',
			),
			201
		);
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		$items = array();

		foreach ( $this->reviews->pending() as $row ) {
			$items[] = $this->present( $row );
		}

		return $this->ok( array( 'items' => $items ) );
	}

	public function moderate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id     = (int) $request['id'];
		$status = (string) $request['status'];

		if ( ! $this->reviews->set_status( $id, $status ) ) {
			return new WP_Error(
				'plumberslot_not_found',
				__( 'Review not found.', 'plumberslot' ),
				array( 'status' => 404 )
			);
		}

		AuditLog::record( 'review.moderated', 'review', $id, array( 'status' => $status ) );

		return $this->ok( array( 'status' => $status ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present( object $row ): array {
		$author     = get_userdata( (int) $row->author_id );
		$technician = $this->technicians->find( (int) $row->technician_id );

		return array(
			'id'            => (int) $row->id,
			'booking_id'    => (int) $row->booking_id,
			'technician_id' => (int) $row->technician_id,
			'technician'    => $technician ? (string) $technician->display_name : __( 'Technician', 'plumberslot' ),
			'author'        => $author ? $author->display_name : __( 'Customer', 'plumberslot' ),
			'rating'        => (int) $row->rating,
			'body'          => $row->body,
			'created_at'    => (string) $row->created_at,
		);
	}
}
