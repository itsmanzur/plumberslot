<?php
/**
 * Registers every REST controller on rest_api_init.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Rest;

use TutorSlot\Container;
use TutorSlot\Database\Repository\BookingRepository;
use TutorSlot\Database\Repository\AvailabilityRepository;
use TutorSlot\Database\Repository\CreditRepository;
use TutorSlot\Database\Repository\LockRepository;
use TutorSlot\Database\Repository\RelationRepository;
use TutorSlot\Database\Repository\ReviewRepository;
use TutorSlot\Database\Repository\SeriesRepository;
use TutorSlot\Database\Repository\SubjectRepository;
use TutorSlot\Database\Repository\TutorRepository;
use TutorSlot\Domain\BookingService;
use TutorSlot\Domain\CreditService;
use TutorSlot\Domain\PaymentService;
use TutorSlot\Domain\PolicyService;
use TutorSlot\Domain\RecurrenceService;
use TutorSlot\Domain\SlotEngine;
use TutorSlot\Database\Repository\PaymentRepository;
use TutorSlot\Meetings\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

final class RestServiceProvider {

	public function __construct( private readonly Container $container ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$guard = new Guard( $this->container->get( TutorRepository::class ) );

		$controllers = array(
			new SlotsController(
				$guard,
				$this->container->get( SlotEngine::class ),
				$this->container->get( TutorRepository::class )
			),
			new BookingsController(
				$guard,
				$this->container->get( BookingService::class ),
				$this->container->get( BookingRepository::class ),
				$this->container->get( LockRepository::class ),
				$this->container->get( TutorRepository::class ),
				$this->container->get( SubjectRepository::class ),
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
				$this->container->get( TutorRepository::class ),
				$this->container->get( SubjectRepository::class ),
				$this->container->get( CreditService::class ),
				$this->container->get( PolicyService::class )
			),
			new CreditsController(
				$guard,
				$this->container->get( CreditService::class ),
				$this->container->get( CreditRepository::class )
			),
			new RelationsController(
				$guard,
				$this->container->get( RelationRepository::class )
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
			new TutorsController(
				$guard,
				$this->container->get( TutorRepository::class ),
				$this->container->get( SubjectRepository::class )
			),
			new SubjectsController(
				$guard,
				$this->container->get( SubjectRepository::class ),
				$this->container->get( TutorRepository::class )
			),
			new DashboardController(
				$guard,
				$this->container->get( TutorRepository::class ),
				$this->container->get( BookingRepository::class ),
				$this->container->get( CreditRepository::class ),
				$this->container->get( AvailabilityRepository::class ),
				$this->container->get( SubjectRepository::class )
			),
			new AuditController( $guard ),
			new SetupController(
				$guard,
				$this->container->get( TutorRepository::class ),
				$this->container->get( SubjectRepository::class ),
				$this->container->get( AvailabilityRepository::class )
			),
			new MeetingsController(
				$guard,
				$this->container->get( ProviderRegistry::class )
			),
			new PublicTutorController(
				$guard,
				$this->container->get( TutorRepository::class ),
				$this->container->get( SubjectRepository::class ),
				$this->container->get( ReviewRepository::class ),
				$this->container->get( SlotEngine::class ),
				$this->container->get( BookingRepository::class )
			),
		);

		/**
		 * Filter the controller list.
		 *
		 * @param list<AbstractController> $controllers Controllers to register.
		 * @param Container                $container   Service container.
		 */
		$controllers = apply_filters( 'tutorslot_rest_controllers', $controllers, $this->container );

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}
}
