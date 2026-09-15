<?php
/**
 * Subject repository integration tests.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Tests\Integration;

use TutorSlot\Database\Repository\SubjectRepository;
use TutorSlot\Database\Schema;
use WP_UnitTestCase;

final class SubjectRepositoryTest extends WP_UnitTestCase {

	private SubjectRepository $subjects;

	public function set_up(): void {
		parent::set_up();

		Schema::create_all();
		$this->subjects = new SubjectRepository();
		$this->empty_subjects();
	}

	public function tear_down(): void {
		$this->empty_subjects();

		parent::tear_down();
	}

	public function test_create_and_find_are_tutor_scoped(): void {
		$subject_id = $this->subjects->create(
			11,
			array(
				'name'         => '<b>Mathematics</b>',
				'level'        => 'A Level',
				'curriculum'   => '',
				'duration_min' => 90,
				'price_minor'  => 3500,
				'is_trial'     => 5,
				'sort_order'   => 3,
				'not_a_column' => 'ignored',
			)
		);

		$subject = $this->subjects->find_for_tutor( $subject_id, 11 );

		self::assertGreaterThan( 0, $subject_id );
		self::assertNotNull( $subject );
		self::assertSame( 'Mathematics', $subject->name );
		self::assertSame( 'A Level', $subject->level );
		self::assertNull( $subject->curriculum );
		self::assertSame( 90, (int) $subject->duration_min );
		self::assertSame( 3500, (int) $subject->price_minor );
		self::assertSame( 1, (int) $subject->is_trial );
		self::assertSame( 'active', $subject->status );
		self::assertSame( 3, (int) $subject->sort_order );
		self::assertNull( $this->subjects->find_for_tutor( $subject_id, 12 ) );
	}

	public function test_all_for_tutor_is_ordered_and_excludes_other_tutors(): void {
		$this->subjects->create( 21, array( 'name' => 'Algebra', 'sort_order' => 2 ) );
		$this->subjects->create( 21, array( 'name' => 'Physics', 'sort_order' => 1 ) );
		$this->subjects->create( 21, array( 'name' => 'Chemistry', 'sort_order' => 1 ) );
		$this->subjects->create( 22, array( 'name' => 'Biology', 'sort_order' => 0 ) );

		$subjects = $this->subjects->all_for_tutor( 21 );

		self::assertCount( 3, $subjects );
		self::assertSame(
			array( 'Chemistry', 'Physics', 'Algebra' ),
			array_map( static fn ( object $subject ): string => (string) $subject->name, $subjects )
		);
		self::assertSame( array(), $this->subjects->all_for_tutor( 23 ) );
	}

	public function test_update_and_delete_cannot_cross_tutor_boundaries(): void {
		$subject_id = $this->subjects->create(
			31,
			array(
				'name'         => 'English',
				'duration_min' => 60,
			)
		);

		self::assertFalse(
			$this->subjects->update_for_tutor(
				$subject_id,
				32,
				array( 'name' => 'Stolen' )
			)
		);
		self::assertFalse( $this->subjects->update_for_tutor( $subject_id, 31, array( 'unknown' => 'ignored' ) ) );
		self::assertTrue(
			$this->subjects->update_for_tutor(
				$subject_id,
				31,
				array(
					'name'         => 'English Language',
					'duration_min' => 45,
				)
			)
		);

		$subject = $this->subjects->find_for_tutor( $subject_id, 31 );

		self::assertNotNull( $subject );
		self::assertSame( 'English Language', $subject->name );
		self::assertSame( 45, (int) $subject->duration_min );
		self::assertFalse( $this->subjects->delete_for_tutor( $subject_id, 32 ) );
		self::assertTrue( $this->subjects->delete_for_tutor( $subject_id, 31 ) );
		self::assertNull( $this->subjects->find_for_tutor( $subject_id, 31 ) );
	}

	private function empty_subjects(): void {
		global $wpdb;

		$wpdb->query( 'DELETE FROM ' . Schema::table( Schema::SUBJECTS ) );
	}
}
