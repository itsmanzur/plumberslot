<?php
/**
 * Parent ↔ child guardian relationships.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Database\Repository;

use PlumberSlot\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class RelationRepository extends AbstractRepository {

	protected function table(): string {
		return Schema::table( Schema::RELATIONS );
	}

	/**
	 * Invite (or re-invite) a child. Unconfirmed until the student accepts.
	 *
	 * @return int Relation id.
	 */
	public function invite( int $parent_id, int $student_id, string $relation = 'guardian' ): int {
		$existing = $this->find_pair( $parent_id, $student_id );
		$relation = sanitize_key( $relation );
		$relation = $relation ? $relation : 'guardian';

		if ( $existing ) {
			$this->db->update(
				$this->table(),
				array(
					'relation'  => $relation,
					'confirmed' => 0,
				),
				array( 'id' => (int) $existing->id ),
				array( '%s', '%d' ),
				array( '%d' )
			);

			return (int) $existing->id;
		}

		$this->db->insert(
			$this->table(),
			array(
				'parent_id'  => $parent_id,
				'student_id' => $student_id,
				'relation'   => $relation,
				'confirmed'  => 0,
				'created_at' => $this->now(),
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		);

		return (int) $this->db->insert_id;
	}

	public function confirm( int $relation_id ): bool {
		return (bool) $this->db->update(
			$this->table(),
			array( 'confirmed' => 1 ),
			array( 'id' => $relation_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	public function find_pair( int $parent_id, int $student_id ): ?object {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE parent_id = %d AND student_id = %d',
				$parent_id,
				$student_id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Confirmed children for a parent account.
	 *
	 * @return list<object>
	 */
	public function children_of( int $parent_id, bool $confirmed_only = true ): array {
		$sql = 'SELECT * FROM ' . $this->table() . ' WHERE parent_id = %d';

		if ( $confirmed_only ) {
			$sql .= ' AND confirmed = 1';
		}

		$sql .= ' ORDER BY id ASC';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (array) $this->db->get_results( $this->db->prepare( $sql, $parent_id ) );
	}

	/**
	 * Pending invitations waiting on this student.
	 *
	 * @return list<object>
	 */
	public function pending_for_student( int $student_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (array) $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE student_id = %d AND confirmed = 0 ORDER BY id DESC',
				$student_id
			)
		);
	}

	public function is_confirmed( int $parent_id, int $student_id ): bool {
		$pair = $this->find_pair( $parent_id, $student_id );

		return $pair && 1 === (int) $pair->confirmed;
	}
}
