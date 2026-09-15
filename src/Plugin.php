<?php
/**
 * Bootstraps every subsystem and holds the service container.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot;

use PlumberSlot\Admin\AdminMenu;
use PlumberSlot\Admin\OnboardingWizard;
use PlumberSlot\Admin\SettingsRegistry;
use PlumberSlot\Database\Migrator;
use PlumberSlot\Frontend\AssetManager;
use PlumberSlot\Frontend\BlockRegistrar;
use PlumberSlot\Frontend\DashboardRoutes;
use PlumberSlot\Frontend\Shortcode;
use PlumberSlot\Frontend\TechnicianAdminGate;
use PlumberSlot\Domain\MeetingService;
use PlumberSlot\Frontend\JoinRoute;
use PlumberSlot\Meetings\MeetingCleanup;
use PlumberSlot\Notifications\Scheduler;
use PlumberSlot\Payments\WebhookController;
use PlumberSlot\Privacy\PrivacyHooks;
use PlumberSlot\Rest\RestServiceProvider;
use PlumberSlot\Support\SecretMasker;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	private Container $container;

	private bool $booted = false;

	private function __construct() {
		$this->container = new Container();
	}

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function container(): Container {
		return $this->container;
	}

	/**
	 * Wire every subsystem. Safe to call twice; the second call is a no-op.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'plumberslot', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );

		$this->container->register_defaults();
		SecretMasker::register();

		// Schema upgrades run before anything reads the tables.
		( new Migrator() )->maybe_upgrade();

		$this->container->get( RestServiceProvider::class )->register();
		$this->container->get( Scheduler::class )->register();
		$this->container->get( MeetingCleanup::class )->register();
		$this->container->get( MeetingService::class )->register();
		$this->container->get( JoinRoute::class )->register();
		$this->container->get( WebhookController::class )->register();
		$this->container->get( AssetManager::class )->register();
		$this->container->get( Shortcode::class )->register();
		$this->container->get( BlockRegistrar::class )->register();
		$this->container->get( DashboardRoutes::class )->register();
		$this->container->get( TechnicianAdminGate::class )->register();
		$this->container->get( PrivacyHooks::class )->register();

		// Settings routes must be registered on every request (including REST) so
		// that wp-json/plumberslot/v1/settings is reachable from the admin SPA.
		$this->container->get( SettingsRegistry::class )->register();

		if ( is_admin() ) {
			$this->container->get( AdminMenu::class )->register();
			$this->container->get( OnboardingWizard::class )->register();
		}

		$this->warn_if_scheduler_missing();

		/**
		 * Fires once PlumberSlot is fully wired.
		 *
		 * Integrations (Tutor LMS, LearnDash, payment add-ons) should hook here.
		 *
		 * @param Container $container Service container.
		 */
		do_action( 'plumberslot_booted', $this->container );
	}

	/**
	 * Timed reminders and payment expiry need Action Scheduler, which arrives
	 * with Composer.
	 *
	 * Everything else works without it, so this is a notice rather than a
	 * failure: booking still functions, but timed lifecycle work is asleep.
	 */
	private function warn_if_scheduler_missing(): void {
		if ( ! is_admin() || function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		add_action(
			'admin_notices',
			static function (): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				printf(
					'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
					esc_html__( 'PlumberSlot:', 'plumberslot' ),
					esc_html__( 'lesson reminders and pending-payment expiry are switched off because Action Scheduler is not installed. Run composer install in the plugin folder to turn them on.', 'plumberslot' )
				);
			}
		);
	}
}
