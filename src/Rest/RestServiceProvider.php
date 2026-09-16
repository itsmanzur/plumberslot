<?php
/**
 * Registers every REST controller on rest_api_init.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Rest;

use PlumberSlot\Container;
use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Repository\AvailabilityRepository;
use PlumberSlot\Database\Repository\CreditRepository;
use PlumberSlot\Database\Repository\LockRepository;
use PlumberSlot\Database\Repository\ReviewRepository;
use PlumberSlot\Database\Repository\SeriesRepository;
use PlumberSlot\Database\Repository\ServiceRepository;
use PlumberSlot\Database\Repository\TechnicianRepository;
use PlumberSlot\Domain\BookingService;
use PlumberSlot\Domain\CreditService;
use PlumberSlot\Domain\PaymentService;
use PlumberSlot\Domain\PolicyService;
use PlumberSlot\Domain\RecurrenceService;
use PlumberSlot\Domain\SlotEngine;
use PlumberSlot\Database\Repository\PaymentRepository;
use PlumberSlot\Meetings\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

final class RestServiceProvider {

	public function __construct( private readonly Container $container ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$guard = new Guard( $this->container->get( TechnicianRepository::class ) );

		$controllers = array(
			new SlotsController(
				$guard,
				$this->container->get( SlotEngine::class ),
				$this->container->get( TechnicianRepository::class )
			),
			new BookingsController(
				$guard,
				$this->container->get( BookingService::class ),
				$this->container->get( BookingRepository::class ),
				$this->container->get( LockRepository::class ),
				$this->container->get( TechnicianRepository::class ),
				$this->container->get( ServiceRepository::class ),
				$this->container->get( CreditService::class ),
				$this->container->get( SlotEngine::class ),
				$this->container->get( PolicyService::class ),
				$this->container->get( SeriesRepository::class )
			),
			new SeriesController(
				$guard,
				$this->container->get( RecurrenceService::class ),
				$this->container->get( SeriesRepository::class ),
				$this->container->get( BookingRepository::class ),
				$this->container->get( TechnicianRepository::class ),
				$this->container->get( ServiceRepository::class ),
				$this->container->get( CreditService::class ),
				$this->container->get( PolicyService::class )
			),
			new CreditsController(
				$guard,
				$this->container->get( CreditService::class ),
				$this->container->get( CreditRepository::class )
			),
			new PaymentsController(
				$guard,
				$this->container->get( PaymentService::class ),
				$this->container->get( BookingRepository::class ),
				$this->container->get( PaymentRepository::class )
			),
			new AvailabilityController(
				$guard,
				$this->container->get( AvailabilityRepository::class )
			),
			new TechniciansController(
				$guard,
				$this->container->get( TechnicianRepository::class ),
				$this->container->get( ServiceRepository::class )
			),
			new ServicesController(
				$guard,
				$this->container->get( ServiceRepository::class ),
				$this->container->get( TechnicianRepository::class )
			),
			new DashboardController(
				$guard,
				$this->container->get( TechnicianRepository::class ),
				$this->container->get( BookingRepository::class ),
				$this->container->get( CreditRepository::class ),
				$this->container->get( AvailabilityRepository::class ),
				$this->container->get( ServiceRepository::class )
			),
			new AuditController( $guard ),
			new SetupController(
				$guard,
				$this->container->get( TechnicianRepository::class ),
				$this->container->get( ServiceRepository::class ),
				$this->container->get( AvailabilityRepository::class )
			),
			new MeetingsController(
				$guard,
				$this->container->get( ProviderRegistry::class )
			),
			new PublicTechnicianController(
				$guard,
				$this->container->get( TechnicianRepository::class ),
				$this->container->get( ServiceRepository::class ),
				$this->container->get( ReviewRepository::class ),
				$this->container->get( SlotEngine::class ),
				$this->container->get( BookingRepository::class )
			),
			new UploadsController( $guard ),
		);

		/**
		 * Filter the controller list.
		 *
		 * @param list<AbstractController> $controllers Controllers to register.
		 * @param Container                $container   Service container.
		 */
		$controllers = apply_filters( 'plumberslot_rest_controllers', $controllers, $this->container );

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}
}
