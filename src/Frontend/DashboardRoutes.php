<?php
/**
 * Front-end routes for parent and tutor dashboards.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Frontend;

defined( 'ABSPATH' ) || exit;

final class DashboardRoutes {

	public function __construct( private readonly AssetManager $assets ) {}

	public function register(): void {
		add_action( 'init', array( $this, 'add_rewrites' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'render' ) );
	}

	public function add_rewrites(): void {
		add_rewrite_rule( '^tutor-dashboard/?$', 'index.php?tutorslot_dash=tutor', 'top' );
		add_rewrite_rule( '^parent-dashboard/?$', 'index.php?tutorslot_dash=parent', 'top' );

		$flag = (string) get_option( 'tutorslot_rewrite_version', '' );
		if ( '5' !== $flag ) {
			flush_rewrite_rules( false );
			update_option( 'tutorslot_rewrite_version', '5', false );
		}
	}

	/**
	 * @param list<string> $vars Query vars.
	 * @return list<string>
	 */
	public function query_vars( array $vars ): array {
		$vars[] = 'tutorslot_dash';

		return $vars;
	}

	public function render(): void {
		$view = get_query_var( 'tutorslot_dash' );
		if ( ! in_array( $view, array( 'tutor', 'parent' ), true ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		status_header( 200 );
		nocache_headers();

		get_header();
		echo wp_kses(
			$this->markup( $view ),
			array(
				'div' => array(
					'class'     => true,
					'role'      => true,
					'data-view' => true,
				),
				'p'   => array(),
			)
		);
		get_footer();
		exit;
	}

	public function markup( string $view ): string {
		if ( ! $this->assets->dashboard_available() ) {
			return sprintf(
				'<div class="tutorslot-unavailable" role="status"><p>%s</p></div>',
				esc_html__( 'Dashboard is temporarily unavailable.', 'tutorslot' )
			);
		}

		$this->assets->mark_dashboard_needed( $view );

		return sprintf(
			'<div class="tutorslot-dashboard tutorslot-root" data-view="%s"></div>',
			esc_attr( $view )
		);
	}
}
