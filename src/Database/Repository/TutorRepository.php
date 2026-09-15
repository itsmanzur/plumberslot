<?php
/**
 * Tutor profiles.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Database\Repository;

use PlumberSlot\Database\Schema;
use PlumberSlot\Domain\Contract\TutorSource;

defined( 'ABSPATH' ) || exit;

final class TutorRepository extends AbstractRepository implements TutorSource {

	protected function table(): string {
		return Schema::table( Schema::TUTORS );
	}

	/**
	 * @return list<object>
	 */
	public function all( string $status = '' ): array {
		if ( '' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
			return (array) $this->db->get_results(
				"SELECT * FROM {$this->table()} ORDER BY display_name ASC"
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (array) $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE status = %s ORDER BY display_name ASC',
				$status
			)
		);
	}

	public function find_by_user( int $user_id ): ?object {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE user_id = %d', $user_id )
		);

		return $row;
	}

	public function find_by_slug( string $slug ): ?object {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE slug = %s', $slug )
		);

		return $row;
	}

	/**
	 * @return list<object>
	 */
	public function all_active(): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (array) $this->db->get_results(
			"SELECT * FROM {$this->table()} WHERE status = 'active' ORDER BY display_name ASC"
		);
	}

	/**
	 * @param array<string, mixed> $data Column values.
	 */
	public function create( array $data ): int {
		$data['created_at'] = $this->now();
		$data['updated_at'] = $this->now();

		$this->db->insert( $this->table(), $data );

		return (int) $this->db->insert_id;
	}

	/**
	 * @param array<string, mixed> $data Column values.
	 */
	public function update( int $id, array $data ): bool {
		$data['updated_at'] = $this->now();

		return (bool) $this->db->update( $this->table(), $data, array( 'id' => $id ), null, array( '%d' ) );
	}

	/**
	 * Map a WordPress user to a tutor row id, or 0.
	 *
	 * Used everywhere ownership is checked, so it is deliberately the only
	 * place that translation happens.
	 */
	public function tutor_id_for_user( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		$tutor = $this->find_by_user( $user_id );

		return $tutor ? (int) $tutor->id : 0;
	}
}
