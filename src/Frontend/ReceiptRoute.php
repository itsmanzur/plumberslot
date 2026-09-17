<?php
/**
 * /plumberslot/receipt — printable, login-gated receipt page.
 *
 * Unlike TrackRoute (public, token-only -- a stranger with a forwarded link
 * should still see basic status), a receipt carries financial detail, so the
 * real gate here is the account, not a token: the requester must be signed
 * in as the booking's own customer, its assigned technician, or a site
 * manager, mirroring JoinRoute's own "must be logged in, must be a
 * participant" check.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Frontend;

use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Repository\ServiceRepository;
use PlumberSlot\Database\Repository\TechnicianRepository;
use PlumberSlot\Support\Capabilities;
use PlumberSlot\Support\Money;
use PlumberSlot\Support\Settings;
use PlumberSlot\Support\Time;

defined( 'ABSPATH' ) || exit;

final class ReceiptRoute {

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
		add_rewrite_rule( '^plumberslot/receipt/?$', 'index.php?plumberslot_receipt=1', 'top' );
	}

	/**
	 * @param list<string> $vars Public query variables.
	 * @return list<string>
	 */
	public function query_vars( array $vars ): array {
		$vars[] = 'plumberslot_receipt';

		return $vars;
	}

	public function handle(): void {
		if ( ! get_query_var( 'plumberslot_receipt' ) ) {
			return;
		}

		// booking is a plain id, not a signed credential -- the real gate
		// below is login + participant identity, not this param.
		$booking_id = isset( $_GET['booking'] ) ? absint( wp_unslash( $_GET['booking'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$booking = $booking_id > 0 ? $this->bookings->find( $booking_id ) : null;

		if ( ! $booking ) {
			wp_die( esc_html__( 'Receipt not found.', 'plumberslot' ), '', array( 'response' => 404 ) );
		}

		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Sign in with an appointment participant account to view this receipt.', 'plumberslot' ), '', array( 'response' => 403 ) );
		}

		$user_id        = get_current_user_id();
		$technician_row = $this->technicians->find( (int) $booking->technician_id );
		$allowed        = array( (int) $booking->customer_id );

		if ( $technician_row ) {
			$allowed[] = (int) $technician_row->user_id;
		}

		if ( ! in_array( $user_id, $allowed, true ) && ! current_user_can( Capabilities::MANAGE_ALL ) ) {
			wp_die( esc_html__( 'You are not a participant of this appointment.', 'plumberslot' ), '', array( 'response' => 403 ) );
		}

		$service = ! empty( $booking->service_id ) ? $this->services->find( (int) $booking->service_id ) : null;

		$tz   = $technician_row ? (string) $technician_row->timezone : 'UTC';
		$when = Time::for_human( Time::from_sql( (string) $booking->start_utc ), $tz );

		$this->render( $booking, $technician_row, $service, $when );
		exit;
	}

	private function business_name(): string {
		$name = Settings::string( 'business_name', '' );

		return '' !== $name ? $name : get_bloginfo( 'name' );
	}

	/**
	 * @return list<array{label:string,value:string,is_total?:bool}>
	 */
	private function price_lines( object $booking ): array {
		$currency = (string) ( $booking->currency ?? 'USD' );
		$price    = (int) ( $booking->price_minor ?? 0 );
		$deposit  = (int) ( $booking->deposit_minor ?? $price );
		$balance  = (int) ( $booking->balance_minor ?? 0 );

		if ( $deposit === $price || $balance <= 0 ) {
			return array(
				array(
					'label'    => __( 'Total', 'plumberslot' ),
					'value'    => Money::format( $price, $currency ),
					'is_total' => true,
				),
			);
		}

		return array(
			array(
				'label' => __( 'Paid online', 'plumberslot' ),
				'value' => Money::format( $deposit, $currency ),
			),
			array(
				'label' => __( 'Paid on arrival', 'plumberslot' ),
				'value' => Money::format( $balance, $currency ),
			),
			array(
				'label'    => __( 'Total', 'plumberslot' ),
				'value'    => Money::format( $price, $currency ),
				'is_total' => true,
			),
		);
	}

	private function render( object $booking, ?object $technician, ?object $service, string $when ): void {
		status_header( 200 );
		nocache_headers();

		$business_name   = $this->business_name();
		$technician_name = $technician ? (string) $technician->display_name : __( 'Your technician', 'plumberslot' );
		$service_name    = $service ? (string) $service->name : '';
		$address         = trim(
			implode(
				', ',
				array_filter(
					array(
						(string) ( $booking->address_line1 ?? '' ),
						(string) ( $booking->address_line2 ?? '' ),
					)
				)
			)
		);
		$city_state_zip  = trim(
			implode(
				' ',
				array_filter(
					array(
						(string) ( $booking->address_city ?? '' ),
						(string) ( $booking->address_state ?? '' ),
						(string) ( $booking->address_zip ?? '' ),
					)
				)
			)
		);
		$price_lines     = $this->price_lines( $booking );

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $business_name ); ?> — <?php esc_html_e( 'Receipt', 'plumberslot' ); ?></title>
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
		.ts-receipt {
			width: 100%;
			max-width: 480px;
		}
		.ts-receipt__top {
			display: flex;
			justify-content: space-between;
			align-items: baseline;
			margin-bottom: 16px;
		}
		.ts-receipt__business {
			font-family: "Instrument Sans", system-ui, sans-serif;
			font-weight: 700;
			font-size: 14px;
			letter-spacing: 0.02em;
			text-transform: uppercase;
			color: #6b6a67;
			margin: 0;
		}
		.ts-receipt__print {
			border: 1px solid #d8d6d1;
			background: #ffffff;
			color: #1c1b19;
			font-family: inherit;
			font-size: 13px;
			font-weight: 600;
			padding: 6px 14px;
			border-radius: 8px;
			cursor: pointer;
		}
		.ts-receipt__card {
			background: #ffffff;
			border: 1px solid #e7e5e1;
			border-radius: 16px;
			padding: 24px;
		}
		.ts-receipt__title {
			font-family: "Instrument Sans", system-ui, sans-serif;
			font-weight: 700;
			font-size: 22px;
			line-height: 1.3;
			margin: 0 0 16px;
		}
		.ts-receipt__row {
			display: flex;
			justify-content: space-between;
			gap: 12px;
			padding: 10px 0;
			border-top: 1px solid #efeeeb;
			font-size: 14px;
		}
		.ts-receipt__row:first-of-type { border-top: none; }
		.ts-receipt__label { color: #6b6a67; }
		.ts-receipt__value { font-weight: 600; text-align: right; }
		.ts-receipt__prices {
			margin-top: 16px;
			padding-top: 4px;
			border-top: 2px solid #1c1b19;
		}
		.ts-receipt__row.is-total .ts-receipt__label,
		.ts-receipt__row.is-total .ts-receipt__value {
			font-weight: 700;
			font-size: 16px;
			color: #1c1b19;
		}
		.ts-receipt__footer {
			margin-top: 16px;
			font-size: 12px;
			color: #9a9894;
			text-align: center;
		}
		@media print {
			body { background: #ffffff; padding: 0; }
			.ts-receipt__print { display: none; }
			.ts-receipt__card { border: none; padding: 0; }
		}
	</style>
</head>
<body>
	<main class="ts-receipt">
		<div class="ts-receipt__top">
			<p class="ts-receipt__business"><?php echo esc_html( $business_name ); ?></p>
			<button type="button" class="ts-receipt__print" onclick="window.print()"><?php esc_html_e( 'Print', 'plumberslot' ); ?></button>
		</div>
		<div class="ts-receipt__card">
			<p class="ts-receipt__title"><?php esc_html_e( 'Receipt', 'plumberslot' ); ?></p>
			<?php if ( '' !== $service_name ) : ?>
			<div class="ts-receipt__row">
				<span class="ts-receipt__label"><?php esc_html_e( 'Service', 'plumberslot' ); ?></span>
				<span class="ts-receipt__value"><?php echo esc_html( $service_name ); ?></span>
			</div>
			<?php endif; ?>
			<div class="ts-receipt__row">
				<span class="ts-receipt__label"><?php esc_html_e( 'Technician', 'plumberslot' ); ?></span>
				<span class="ts-receipt__value"><?php echo esc_html( $technician_name ); ?></span>
			</div>
			<div class="ts-receipt__row">
				<span class="ts-receipt__label"><?php esc_html_e( 'Appointment', 'plumberslot' ); ?></span>
				<span class="ts-receipt__value"><?php echo esc_html( $when ); ?></span>
			</div>
			<?php if ( '' !== $address ) : ?>
			<div class="ts-receipt__row">
				<span class="ts-receipt__label"><?php esc_html_e( 'Address', 'plumberslot' ); ?></span>
				<span class="ts-receipt__value"><?php echo esc_html( $address ); ?><?php echo '' !== $city_state_zip ? '<br>' . esc_html( $city_state_zip ) : ''; ?></span>
			</div>
			<?php endif; ?>
			<div class="ts-receipt__prices">
				<?php foreach ( $price_lines as $line ) : ?>
				<div class="ts-receipt__row<?php echo ! empty( $line['is_total'] ) ? ' is-total' : ''; ?>">
					<span class="ts-receipt__label"><?php echo esc_html( $line['label'] ); ?></span>
					<span class="ts-receipt__value"><?php echo esc_html( $line['value'] ); ?></span>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<p class="ts-receipt__footer"><?php esc_html_e( 'Thank you for your business.', 'plumberslot' ); ?></p>
	</main>
</body>
</html>
		<?php
	}
}
