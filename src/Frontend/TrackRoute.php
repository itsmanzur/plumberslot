<?php
/**
 * /plumberslot/track — public, no-login job-status tracking page.
 *
 * A customer-facing "where's my technician" page, the way a package-courier
 * tracking link works: possession of the correct token in the URL is the
 * authorization, not account identity. Unlike JoinRoute's signed, short-lived
 * URL (a join link should time out), this link is verified with a direct
 * hash_equals() against the booking's own durable meeting_token and stays
 * valid for the booking's whole lifetime -- see Crypto::track_url().
 *
 * Deliberately scoped to status-only: no coordinates, no map, no polling
 * endpoint. The page just quietly reloads itself every 60 seconds.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Frontend;

use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Repository\ServiceRepository;
use PlumberSlot\Database\Repository\TechnicianRepository;
use PlumberSlot\Support\RateLimiter;
use PlumberSlot\Support\Settings;
use PlumberSlot\Support\Time;

defined( 'ABSPATH' ) || exit;

final class TrackRoute {

	public function __construct(
		private readonly BookingRepository $bookings,
		private readonly TechnicianRepository $technicians,
		private readonly ServiceRepository $services
	) {}

	public function register(): void {
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle' ) );
	}

	public function add_rewrite(): void {
		add_rewrite_rule( '^plumberslot/track/?$', 'index.php?plumberslot_track=1', 'top' );
	}

	/**
	 * @param list<string> $vars Public query variables.
	 * @return list<string>
	 */
	public function query_vars( array $vars ): array {
		$vars[] = 'plumberslot_track';

		return $vars;
	}

	public function handle(): void {
		if ( ! get_query_var( 'plumberslot_track' ) ) {
			return;
		}

		if ( ! RateLimiter::allow( 'track_booking', 60 ) ) {
			wp_die( esc_html__( 'Too many requests. Wait a moment and try again.', 'plumberslot' ), '', array( 'response' => 429 ) );
		}

		// The token itself is the only credential this route checks -- unslash
		// and sanitize before use, then verify with hash_equals() below.
		$booking_id = isset( $_GET['ts_booking'] ) ? absint( wp_unslash( $_GET['ts_booking'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token      = isset( $_GET['ts_token'] ) ? sanitize_text_field( wp_unslash( $_GET['ts_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		/** @var object{meeting_token:?string,technician_id:int,service_id:?int,start_utc:string,status:string,job_stage:?string}|null $booking */
		$booking = $booking_id > 0 ? $this->bookings->find( $booking_id ) : null;

		// A missing param, an unknown booking, an empty stored token, and a
		// wrong token all produce the exact same response -- never leak which
		// case it was.
		if ( ! $booking_id
			|| '' === $token
			|| ! $booking
			|| empty( $booking->meeting_token )
			|| ! hash_equals( (string) $booking->meeting_token, $token )
		) {
			wp_die( esc_html__( 'This tracking link is invalid.', 'plumberslot' ), '', array( 'response' => 404 ) );
		}

		$technician = $this->technicians->find( (int) $booking->technician_id );
		$service    = ! empty( $booking->service_id ) ? $this->services->find( (int) $booking->service_id ) : null;

		$tz   = $technician ? (string) $technician->timezone : 'UTC';
		$when = Time::for_human( Time::from_sql( (string) $booking->start_utc ), $tz );

		$this->render(
			$this->business_name(),
			$technician ? (string) $technician->display_name : __( 'Your technician', 'plumberslot' ),
			$service ? (string) $service->name : '',
			$when,
			$this->status_line( $booking, $technician )
		);
		exit;
	}

	private function business_name(): string {
		$name = Settings::string( 'business_name', '' );

		return '' !== $name ? $name : get_bloginfo( 'name' );
	}

	/**
	 * Synthesizes one customer-facing sentence from `status` and `job_stage`
	 * together -- `job_stage` only means anything while `status` is
	 * 'confirmed'; once attendance is recorded, `status` alone conveys it.
	 */
	private function status_line( object $booking, ?object $technician ): string {
		$status          = (string) $booking->status;
		$technician_name = $technician ? (string) $technician->display_name : __( 'Your technician', 'plumberslot' );

		if ( in_array( $status, array( 'cancelled', 'refunded' ), true ) ) {
			return __( 'This appointment was cancelled.', 'plumberslot' );
		}

		if ( 'completed' === $status ) {
			return __( 'This job is complete.', 'plumberslot' );
		}

		if ( 'no_show' === $status ) {
			return __( 'This appointment has ended.', 'plumberslot' );
		}

		if ( 'confirmed' === $status ) {
			$stage = (string) ( $booking->job_stage ?? 'scheduled' );

			if ( 'on_the_way' === $stage ) {
				return sprintf(
					/* translators: %s: technician display name. */
					__( '%s is on the way!', 'plumberslot' ),
					$technician_name
				);
			}

			if ( 'in_progress' === $stage ) {
				return sprintf(
					/* translators: %s: technician display name. */
					__( '%s is on site.', 'plumberslot' ),
					$technician_name
				);
			}

			return sprintf(
				/* translators: %s: date and time. */
				__( 'Scheduled for %s.', 'plumberslot' ),
				Time::for_human( Time::from_sql( (string) $booking->start_utc ), $technician ? (string) $technician->timezone : 'UTC' )
			);
		}

		return __( "We'll update this page as your appointment progresses.", 'plumberslot' );
	}

	private function render( string $business_name, string $technician_name, string $service_name, string $when, string $status_line ): void {
		status_header( 200 );
		nocache_headers();

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta http-equiv="refresh" content="60">
	<title><?php echo esc_html( $business_name ); ?> — <?php esc_html_e( 'Appointment status', 'plumberslot' ); ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- this route renders a standalone page outside wp_head/wp_footer, so there is no enqueue hook to attach to. ?>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Instrument+Sans:wght@600;700&display=swap" rel="stylesheet">
	<style>
		:root { color-scheme: light; }
		* { box-sizing: border-box; }
		body {
			margin: 0;
			padding: 32px 16px;
			min-height: 100vh;
			background: #f6f5f3;
			font-family: "Inter", system-ui, sans-serif;
			color: #1c1b19;
			display: flex;
			justify-content: center;
		}
		.ts-track {
			width: 100%;
			max-width: 480px;
		}
		.ts-track__business {
			font-family: "Instrument Sans", system-ui, sans-serif;
			font-weight: 700;
			font-size: 14px;
			letter-spacing: 0.02em;
			text-transform: uppercase;
			color: #6b6a67;
			margin: 0 0 16px;
		}
		.ts-track__card {
			background: #ffffff;
			border: 1px solid #e7e5e1;
			border-radius: 16px;
			padding: 24px;
		}
		.ts-track__status {
			font-family: "Instrument Sans", system-ui, sans-serif;
			font-weight: 700;
			font-size: 22px;
			line-height: 1.3;
			margin: 0 0 16px;
		}
		.ts-track__row {
			display: flex;
			justify-content: space-between;
			gap: 12px;
			padding: 10px 0;
			border-top: 1px solid #efeeeb;
			font-size: 14px;
		}
		.ts-track__row:first-of-type { border-top: none; }
		.ts-track__label { color: #6b6a67; }
		.ts-track__value { font-weight: 600; text-align: right; }
		.ts-track__footer {
			margin-top: 16px;
			font-size: 12px;
			color: #9a9894;
			text-align: center;
		}
	</style>
</head>
<body>
	<main class="ts-track">
		<p class="ts-track__business"><?php echo esc_html( $business_name ); ?></p>
		<div class="ts-track__card">
			<p class="ts-track__status"><?php echo esc_html( $status_line ); ?></p>
			<?php if ( '' !== $service_name ) : ?>
			<div class="ts-track__row">
				<span class="ts-track__label"><?php esc_html_e( 'Service', 'plumberslot' ); ?></span>
				<span class="ts-track__value"><?php echo esc_html( $service_name ); ?></span>
			</div>
			<?php endif; ?>
			<div class="ts-track__row">
				<span class="ts-track__label"><?php esc_html_e( 'Technician', 'plumberslot' ); ?></span>
				<span class="ts-track__value"><?php echo esc_html( $technician_name ); ?></span>
			</div>
			<div class="ts-track__row">
				<span class="ts-track__label"><?php esc_html_e( 'Appointment', 'plumberslot' ); ?></span>
				<span class="ts-track__value"><?php echo esc_html( $when ); ?></span>
			</div>
		</div>
		<p class="ts-track__footer"><?php esc_html_e( 'This page updates automatically.', 'plumberslot' ); ?></p>
	</main>
</body>
</html>
		<?php
	}
}
