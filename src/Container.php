<?php
/**
 * A deliberately small service container: lazy singletons, no autowiring magic.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot;

defined( 'ABSPATH' ) || exit;

final class Container {

	/** @var array<class-string, callable> */
	private array $factories = array();

	/** @var array<class-string, object> */
	private array $resolved = array();

	/**
	 * @param class-string $id      Service identifier.
	 * @param callable     $factory Receives the container, returns the service.
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->resolved[ $id ] );
	}

	/**
	 * @template T of object
	 * @param class-string<T> $id Service identifier.
	 * @return T
	 * @throws \RuntimeException When the service was never registered.
	 */
	public function get( string $id ): object {
		if ( isset( $this->resolved[ $id ] ) ) {
			/** @var T */
			return $this->resolved[ $id ];
		}
		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \RuntimeException( esc_html( "PlumberSlot: service {$id} is not registered." ) );
		}
		/** @var T $service */
		$service               = ( $this->factories[ $id ] )( $this );
		$this->resolved[ $id ] = $service;

		return $service;
	}

	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * Register the default object graph.
	 *
	 * Add-ons can override any binding on `plumberslot_container_defaults`.
	 */
	public function register_defaults(): void {
		// Repositories.
		$this->set( Database\Repository\TechnicianRepository::class, static fn () => new Database\Repository\TechnicianRepository() );
		$this->set( Database\Repository\AvailabilityRepository::class, static fn () => new Database\Repository\AvailabilityRepository() );
		$this->set( Database\Repository\BookingRepository::class, static fn () => new Database\Repository\BookingRepository() );
		$this->set( Database\Repository\CreditRepository::class, static fn () => new Database\Repository\CreditRepository() );
		$this->set( Database\Repository\SeriesRepository::class, static fn () => new Database\Repository\SeriesRepository() );
		$this->set( Database\Repository\RelationRepository::class, static fn () => new Database\Repository\RelationRepository() );
		$this->set( Database\Repository\LockRepository::class, static fn () => new Database\Repository\LockRepository() );
		$this->set( Database\Repository\ServiceRepository::class, static fn () => new Database\Repository\ServiceRepository() );
		$this->set( Database\Repository\ReviewRepository::class, static fn () => new Database\Repository\ReviewRepository() );
		$this->set( Database\Repository\PaymentRepository::class, static fn () => new Database\Repository\PaymentRepository() );
		$this->set( Database\TransactionManager::class, static fn () => new Database\TransactionManager() );

		// Domain services.
		$this->set(
			Domain\SlotEngine::class,
			static fn ( Container $c ) => new Domain\SlotEngine(
				$c->get( Database\Repository\AvailabilityRepository::class ),
				$c->get( Database\Repository\BookingRepository::class ),
				$c->get( Database\Repository\LockRepository::class )
			)
		);
		$this->set(
			Domain\BookingService::class,
			static fn ( Container $c ) => new Domain\BookingService(
				$c->get( Database\Repository\BookingRepository::class ),
				$c->get( Database\Repository\LockRepository::class ),
				$c->get( Domain\SlotEngine::class ),
				$c->get( Domain\PolicyService::class ),
				$c->get( Notifications\Dispatcher::class ),
				$c->get( Domain\CreditService::class ),
				$c->get( Database\TransactionManager::class )
			)
		);
		$this->set(
			Domain\PolicyService::class,
			static fn ( Container $c ) => new Domain\PolicyService(
				$c->get( Database\Repository\BookingRepository::class )
			)
		);
		$this->set( Domain\RecurrenceService::class, static fn ( Container $c ) => new Domain\RecurrenceService( $c->get( Domain\BookingService::class ), $c->get( Database\Repository\SeriesRepository::class ) ) );
		$this->set( Domain\CreditService::class, static fn ( Container $c ) => new Domain\CreditService( $c->get( Database\Repository\CreditRepository::class ) ) );
		$this->set(
			Domain\PaymentService::class,
			static fn ( Container $c ) => new Domain\PaymentService(
				$c->get( Payments\GatewayRegistry::class ),
				$c->get( Database\Repository\PaymentRepository::class ),
				$c->get( Database\Repository\BookingRepository::class ),
				$c->get( Domain\BookingService::class ),
				$c->get( Domain\CreditService::class ),
				$c->get( Database\TransactionManager::class )
			)
		);

		// Infrastructure.
		$this->set( Notifications\Dispatcher::class, static fn () => new Notifications\Dispatcher() );
		$this->set(
			Notifications\Scheduler::class,
			static fn ( Container $c ) => new Notifications\Scheduler(
				$c->get( Notifications\Dispatcher::class ),
				$c->get( Domain\PaymentService::class )
			)
		);
		$this->set(
			Payments\GatewayRegistry::class,
			static function (): Payments\GatewayRegistry {
				$registry = new Payments\GatewayRegistry();
				$registry->register( new Payments\StripeGateway() );
				$registry->register( new Payments\BkashGateway() );

				return $registry;
			}
		);
		$this->set(
			Payments\WebhookController::class,
			static fn ( Container $c ) => new Payments\WebhookController(
				$c->get( Payments\GatewayRegistry::class ),
				$c->get( Domain\PaymentService::class )
			)
		);
		$this->set(
			Meetings\ProviderRegistry::class,
			static function (): Meetings\ProviderRegistry {
				$registry = new Meetings\ProviderRegistry();
				$registry->register( new Meetings\GoogleMeetProvider() );
				$registry->register( new Meetings\ZoomProvider() );

				return $registry;
			}
		);
		$this->set(
			Meetings\MeetingCleanup::class,
			static fn ( Container $c ) => new Meetings\MeetingCleanup(
				$c->get( Database\Repository\BookingRepository::class ),
				$c->get( Meetings\ProviderRegistry::class )
			)
		);
		$this->set(
			Domain\MeetingService::class,
			static fn ( Container $c ) => new Domain\MeetingService(
				$c->get( Meetings\ProviderRegistry::class ),
				$c->get( Database\Repository\BookingRepository::class ),
				$c->get( Database\Repository\TechnicianRepository::class )
			)
		);

		// Presentation.
		$this->set( Rest\RestServiceProvider::class, static fn ( Container $c ) => new Rest\RestServiceProvider( $c ) );
		$this->set( Frontend\AssetManager::class, static fn () => new Frontend\AssetManager() );
		$this->set( Frontend\Shortcode::class, static fn ( Container $c ) => new Frontend\Shortcode( $c->get( Frontend\AssetManager::class ) ) );
		$this->set( Frontend\BlockRegistrar::class, static fn ( Container $c ) => new Frontend\BlockRegistrar( $c->get( Frontend\Shortcode::class ) ) );
		$this->set( Frontend\DashboardRoutes::class, static fn ( Container $c ) => new Frontend\DashboardRoutes( $c->get( Frontend\AssetManager::class ) ) );
		$this->set(
			Frontend\JoinRoute::class,
			static fn ( Container $c ) => new Frontend\JoinRoute(
				$c->get( Database\Repository\BookingRepository::class ),
				$c->get( Meetings\ProviderRegistry::class )
			)
		);
		$this->set( Frontend\TechnicianAdminGate::class, static fn () => new Frontend\TechnicianAdminGate() );
		$this->set( Admin\AdminMenu::class, static fn () => new Admin\AdminMenu() );
		$this->set( Admin\SettingsRegistry::class, static fn () => new Admin\SettingsRegistry() );
		$this->set( Admin\OnboardingWizard::class, static fn () => new Admin\OnboardingWizard() );
		$this->set( Privacy\PrivacyHooks::class, static fn ( Container $c ) => new Privacy\PrivacyHooks( $c->get( Database\Repository\BookingRepository::class ) ) );

		do_action( 'plumberslot_container_defaults', $this );
	}
}
