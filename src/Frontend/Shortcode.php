<?php
/**
 * [tutorslot] — the booking widget.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Frontend;

use TutorSlot\Database\Repository\TutorRepository;

defined( 'ABSPATH' ) || exit;

final class Shortcode {

	public function __construct( private readonly AssetManager $assets ) {}

	public function register(): void {
		add_shortcode( 'tutorslot', array( $this, 'render' ) );
		add_shortcode( 'tutorslot_dashboard', array( $this, 'render_dashboard' ) );
	}

	/**
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public function render( array|string $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'tutor'   => '',
				'subject' => '',
				'view'    => 'booking',
			),
			$atts,
			'tutorslot'
		);

		$view = sanitize_key( $atts['view'] );
		if ( in_array( $view, array( 'parent', 'tutor' ), true ) ) {
			return $this->render_dashboard( array( 'view' => $view ) );
		}

		$tutor = '' !== $atts['tutor']
			? ( new TutorRepository() )->find_by_slug( sanitize_title( $atts['tutor'] ) )
			: null;

		if ( '' !== $atts['tutor'] && ! $tutor ) {
			return sprintf(
				'<div class="tutorslot-empty"><p>%s</p></div>',
				esc_html__( 'No tutor matches that name. Check the tutor slug in the shortcode.', 'tutorslot' )
			);
		}

		if ( ! $this->assets->available() ) {
			return sprintf(
				'<div class="tutorslot-unavailable" role="status"><p>%s</p></div>',
				esc_html__( 'Booking is temporarily unavailable. Check this page again shortly.', 'tutorslot' )
			);
		}

		$this->assets->mark_needed();

		return sprintf(
			'<div class="tutorslot-widget" data-tutor="%d" data-subject="%d" data-view="%s"></div>',
			$tutor ? (int) $tutor->id : 0,
			absint( $atts['subject'] ),
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
			'tutorslot_dashboard'
		);

		$view = sanitize_key( $atts['view'] );
		if ( ! in_array( $view, array( 'parent', 'tutor' ), true ) ) {
			$view = 'parent';
		}

		$routes = new DashboardRoutes( $this->assets );

		return $routes->markup( $view );
	}
}
