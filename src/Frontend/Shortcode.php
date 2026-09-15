<?php
/**
 * [plumberslot] — the booking widget.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Frontend;

use PlumberSlot\Database\Repository\TechnicianRepository;

defined( 'ABSPATH' ) || exit;

final class Shortcode {

	public function __construct( private readonly AssetManager $assets ) {}

	public function register(): void {
		add_shortcode( 'plumberslot', array( $this, 'render' ) );
		add_shortcode( 'plumberslot_dashboard', array( $this, 'render_dashboard' ) );
	}

	/**
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public function render( array|string $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'technician'   => '',
				'service' => '',
				'view'    => 'booking',
			),
			$atts,
			'plumberslot'
		);

		$view = sanitize_key( $atts['view'] );
		if ( in_array( $view, array( 'parent', 'technician' ), true ) ) {
			return $this->render_dashboard( array( 'view' => $view ) );
		}

		$technician = '' !== $atts['technician']
			? ( new TechnicianRepository() )->find_by_slug( sanitize_title( $atts['technician'] ) )
			: null;

		if ( '' !== $atts['technician'] && ! $technician ) {
			return sprintf(
				'<div class="plumberslot-empty"><p>%s</p></div>',
				esc_html__( 'No technician matches that name. Check the technician slug in the shortcode.', 'plumberslot' )
			);
		}

		if ( ! $this->assets->available() ) {
			return sprintf(
				'<div class="plumberslot-unavailable" role="status"><p>%s</p></div>',
				esc_html__( 'Booking is temporarily unavailable. Check this page again shortly.', 'plumberslot' )
			);
		}

		$this->assets->mark_needed();

		return sprintf(
			'<div class="plumberslot-widget" data-technician="%d" data-service="%d" data-view="%s"></div>',
			$technician ? (int) $technician->id : 0,
			absint( $atts['service'] ),
			esc_attr( $view )
		);
	}

	/**
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public function render_dashboard( array|string $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'view' => 'parent',
			),
			$atts,
			'plumberslot_dashboard'
		);

		$view = sanitize_key( $atts['view'] );
		if ( ! in_array( $view, array( 'parent', 'technician' ), true ) ) {
			$view = 'parent';
		}

		$routes = new DashboardRoutes( $this->assets );

		return $routes->markup( $view );
	}
}
