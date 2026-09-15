<?php
/**
 * Phase 8 privacy exporter and eraser integration tests.
 *
 * @package PlumberSlot\Tests
 */

declare( strict_types = 1 );

namespace PlumberSlot\Tests\Integration;

use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Repository\RelationRepository;
use PlumberSlot\Database\Repository\TutorRepository;
use PlumberSlot\Database\Schema;
use PlumberSlot\Privacy\PrivacyHooks;
use PlumberSlot\Support\AuditLog;

final class Phase8PrivacyTest extends \WP_UnitTestCase {

	private BookingRepository $bookings;
	private PrivacyHooks $privacy;
	private int $tutor_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_all();

		$this->bookings = new BookingRepository();
		$this->privacy  = new PrivacyHooks( $this->bookings );

		$tutor_user_id  = self::factory()->user->create();
		$this->tutor_id = ( new TutorRepository() )->create(
			array(
				'user_id'      => $tutor_user_id,
				'slug'         => 'phase8-privacy-' . $tutor_user_id,
				'display_name' => 'Phase 8 Privacy Tutor',
				'timezone'     => 'UTC',
				'status'       => 'active',
			)
		);
	}

	public function tear_down(): void {
		global $wpdb;

		foreach ( Schema::all_keys() as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integration-test tables only.
			$wpdb->query( 'DELETE FROM ' . Schema::table( $key ) );
		}

		parent::tear_down();
	}

	public function test_registers_wordpress_privacy_callbacks_and_complete_policy_text(): void {
		$exporters = $this->privacy->add_exporter( array() );
		$erasers   = $this->privacy->add_eraser( array() );
		$policy    = $this->privacy->policy_text();

		$this->assertSame( array( $this->privacy, 'export' ), $exporters['plumberslot']['callback'] );
		$this->assertSame( array( $this->privacy, 'erase' ), $erasers['plumberslot']['callback'] );
		$this->assertStringContainsString( 'signed PlumberSlot join link', $policy );
		$this->assertStringContainsString( 'Stripe or bKash', $policy );
		$this->assertStringContainsString( 'Google Calendar and Meet or to Zoom', $policy );
		$this->assertStringContainsString( 'connects an SMS add-on', $policy );
		$this->assertStringContainsString( 'accounting, tax, fraud prevention or dispute resolution', $policy );
	}

	public function test_export_paginates_bookings_relations_reviews_and_parent_data(): void {
		global $wpdb;

		$user_id     = self::factory()->user->create( array( 'user_email' => 'privacy-export@example.test' ) );
		$parent_id   = self::factory()->user->create();
		$booking_ids = array();

		for ( $index = 0; $index < 21; $index++ ) {
			$booking_ids[] = $this->create_booking(
				20 === $index ? $parent_id : $user_id,
				20 === $index ? $user_id : $parent_id,
				$index,
				'Private lesson note ' . $index
			);
		}

		( new RelationRepository() )->invite( $parent_id, $user_id, 'guardian' );
		$wpdb->insert(
			Schema::table( Schema::REVIEWS ),
			array(
				'booking_id' => $booking_ids[0],
				'tutor_id'   => $this->tutor_id,
				'author_id'  => $user_id,
				'rating'     => 5,
				'body'       => 'A private review body',
				'status'     => 'approved',
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		$page_one     = $this->privacy->export( 'privacy-export@example.test', 1 );
		$page_two     = $this->privacy->export( 'privacy-export@example.test', 2 );
		$export_event = $this->audit_event( 'privacy.exported', $user_id );
		$this->assertNotNull( $export_event );
		$this->assertSame( array(), json_decode( (string) $export_event->meta, true ) );
		$all      = array_merge( $page_one['data'], $page_two['data'] );
		$item_ids = array_column( $all, 'item_id' );
		$groups   = array_unique( array_column( $all, 'group_id' ) );

		$this->assertCount( 20, $page_one['data'] );
		$this->assertFalse( $page_one['done'] );
		$this->assertCount( 3, $page_two['data'] );
		$this->assertTrue( $page_two['done'] );
		$this->assertCount( 23, array_unique( $item_ids ) );
		$this->assertContains( 'plumberslot-bookings', $groups );
		$this->assertContains( 'plumberslot-relations', $groups );
		$this->assertContains( 'plumberslot-reviews', $groups );

		$first_booking = $this->find_export_item( $all, 'booking-' . $booking_ids[0] );
		$this->assertStringContainsString( 'Student', wp_json_encode( $first_booking['data'] ) );
		$this->assertStringContainsString( 'Private lesson note 0', wp_json_encode( $first_booking['data'] ) );

		$parent_booking = $this->find_export_item( $all, 'booking-' . $booking_ids[20] );
		$this->assertStringContainsString( 'Paying parent', wp_json_encode( $parent_booking['data'] ) );
	}

	public function test_eraser_anonymizes_all_personal_links_without_losing_accounting_data(): void {
		global $wpdb;

		$user_id         = self::factory()->user->create( array( 'user_email' => 'privacy-erase@example.test' ) );
		$other_id        = self::factory()->user->create();
		$student_booking = $this->create_booking( $user_id, $other_id, 0, 'Student private note', 1250, 'pay-student' );
		$parent_booking  = $this->create_booking( $other_id, $user_id, 2, 'Parent private note', 2400, 'pay-parent' );

		( new RelationRepository() )->invite( $user_id, $other_id, 'guardian' );
		$wpdb->insert(
			Schema::table( Schema::REVIEWS ),
			array(
				'booking_id' => $student_booking,
				'tutor_id'   => $this->tutor_id,
				'author_id'  => $user_id,
				'rating'     => 4,
				'body'       => 'Erase this review body',
				'status'     => 'approved',
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$wpdb->insert(
			Schema::table( Schema::SERIES ),
			array(
				'tutor_id'    => $this->tutor_id,
				'student_id'  => $user_id,
				'rrule'       => 'FREQ=WEEKLY',
				'total_count' => 4,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$wpdb->insert(
			Schema::table( Schema::CREDITS ),
			array(
				'owner_id'    => $user_id,
				'tutor_id'    => $this->tutor_id,
				'subject_id'  => null,
				'total'       => 10,
				'used'        => 3,
				'price_minor' => 7500,
				'expires_at'  => null,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		for ( $index = 0; $index < 25; $index++ ) {
			$wpdb->insert(
				Schema::table( Schema::AUDIT ),
				array(
					'actor_id'    => $user_id,
					'action'      => 'privacy.fixture',
					'object_type' => 'booking',
					'object_id'   => $student_booking,
					'ip_hash'     => hash( 'sha256', 'private-ip-' . $index ),
					'meta'        => wp_json_encode( array( 'email' => 'privacy-erase@example.test' ) ),
					'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}

		$first = $this->privacy->erase( 'privacy-erase@example.test', 1 );
		$this->assertFalse( $first['done'] );

		$page = 2;
		do {
			$result = $this->privacy->erase( 'privacy-erase@example.test', $page );
			++$page;
		} while ( ! $result['done'] && $page < 10 );

		$this->assertTrue( $result['done'] );
		$this->assertTrue( $first['items_removed'] );
		$this->assertTrue( $first['items_retained'] );
		$this->assertNotEmpty( $first['messages'] );

		$student_row = $this->bookings->find( $student_booking );
		$parent_row  = $this->bookings->find( $parent_booking );
		$this->assertSame( 0, (int) $student_row->student_id );
		$this->assertSame( $other_id, (int) $student_row->parent_id );
		$this->assertNull( $student_row->notes );
		$this->assertSame( 1250, (int) $student_row->price_minor );
		$this->assertSame( 'pay-student', $student_row->payment_ref );
		$this->assertSame( $other_id, (int) $parent_row->student_id );
		$this->assertNull( $parent_row->parent_id );
		$this->assertNull( $parent_row->notes );
		$this->assertSame( 2400, (int) $parent_row->price_minor );
		$this->assertSame( 'pay-parent', $parent_row->payment_ref );

		$this->assertSame( 0, $this->count_for_user( Schema::RELATIONS, 'parent_id', $user_id ) );
		$review = $wpdb->get_row( 'SELECT * FROM ' . Schema::table( Schema::REVIEWS ) . ' LIMIT 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- integration-test table.
		$this->assertSame( 0, (int) $review->author_id );
		$this->assertNull( $review->body );
		$this->assertSame( 4, (int) $review->rating );
		$this->assertSame( 0, $this->count_for_user( Schema::SERIES, 'student_id', $user_id ) );
		$this->assertSame( 0, $this->count_for_user( Schema::CREDITS, 'owner_id', $user_id ) );
		$this->assertSame( 0, $this->count_for_user( Schema::AUDIT, 'actor_id', $user_id ) );
		$this->assertSame( 25, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( Schema::AUDIT ) . ' WHERE actor_id = 0 AND ip_hash IS NULL AND meta IS NULL' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		$erase_event = $this->audit_event( 'privacy.erased', $user_id );
		$this->assertNotNull( $erase_event );
		$this->assertSame( array( 'retained_accounting' => true ), json_decode( (string) $erase_event->meta, true ) );
	}

	public function test_unknown_email_finishes_without_mutation(): void {
		$result = $this->privacy->erase( 'missing-person@example.test', 1 );

		$this->assertFalse( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( array(), $this->privacy->export( 'missing-person@example.test', 1 )['data'] );
	}

	private function create_booking(
		int $student_id,
		?int $parent_id,
		int $offset,
		string $notes,
		int $price_minor = 1000,
		string $payment_ref = 'pay-fixture'
	): int {
		$start = gmdate( 'Y-m-d H:i:s', time() + ( ( 48 + ( $offset * 2 ) ) * HOUR_IN_SECONDS ) );
		$id    = $this->bookings->insert_unique(
			array(
				'tutor_id'      => $this->tutor_id,
				'student_id'    => $student_id,
				'parent_id'     => $parent_id,
				'subject_id'    => null,
				'series_id'     => null,
				'series_index'  => null,
				'start_utc'     => $start,
				'end_utc'       => gmdate( 'Y-m-d H:i:s', strtotime( $start ) + HOUR_IN_SECONDS ),
				'student_tz'    => 'UTC',
				'status'        => 'completed',
				'price_minor'   => $price_minor,
				'currency'      => 'USD',
				'credit_id'     => null,
				'payment_ref'   => $payment_ref,
				'meeting_ref'   => 'fake|retained-meeting-reference',
				'meeting_token' => bin2hex( random_bytes( 32 ) ),
				'notes'         => $notes,
			)
		);

		$this->assertNotNull( $id );

		return (int) $id;
	}

	/**
	 * @param list<array{item_id:string,data:list<array{name:string,value:string}>}> $items Export items.
	 * @return array{item_id:string,data:list<array{name:string,value:string}>}
	 */
	private function find_export_item( array $items, string $item_id ): array {
		foreach ( $items as $item ) {
			if ( $item_id === $item['item_id'] ) {
				return $item;
			}
		}

		$this->fail( 'Missing export item ' . $item_id );
	}

	private function count_for_user( string $table_key, string $column, int $user_id ): int {
		global $wpdb;

		$allowed = array( 'parent_id', 'student_id', 'author_id', 'owner_id', 'actor_id' );
		$this->assertContains( $column, $allowed );
		$table = Schema::table( $table_key );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- column is checked against the local whitelist, table comes from schema and user id is prepared.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = %d', $user_id ) );
	}

	private function audit_event( string $action, int $object_id ): ?object {
		foreach ( AuditLog::recent( 200 ) as $row ) {
			if ( $action === (string) $row->action && $object_id === (int) $row->object_id ) {
				return $row;
			}
		}

		return null;
	}
}
