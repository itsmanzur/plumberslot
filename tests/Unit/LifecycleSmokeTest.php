<?php
/**
 * Lifecycle smoke tests that do not mutate the Local WordPress database.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace {
	$GLOBALS['tutorslot_lifecycle_calls'] = array();
}

namespace TutorSlot\Tests\Unit {
	use PHPUnit\Framework\TestCase;
	use TutorSlot\Deactivator;

	final class LifecycleSmokeTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['tutorslot_lifecycle_calls'] = array();
			$GLOBALS['tutorslot_test_settings']    = array( 'delete_data_on_uninstall' => false );
		}

		public function test_deactivation_unschedules_only_tutorslot_actions_and_flushes_runtime_state(): void {
			Deactivator::deactivate();

			self::assertSame(
				array( '', array(), 'tutorslot' ),
				$GLOBALS['tutorslot_lifecycle_calls']['unschedule']
			);
			self::assertSame( 'tutorslot', $GLOBALS['tutorslot_lifecycle_calls']['cache'] );
			self::assertTrue( $GLOBALS['tutorslot_lifecycle_calls']['rewrite'] );
		}

		public function test_default_uninstall_returns_without_loading_destructive_schema_code(): void {
			if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
				define( 'WP_UNINSTALL_PLUGIN', true );
			}

			include dirname( __DIR__, 2 ) . '/uninstall.php';

			self::assertFalse( class_exists( \TutorSlot\Database\Schema::class, false ) );
		}
	}
}
