<?php
/**
 * Tutor-scoped lesson subjects and their booking terms.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Database\Repository;

use PlumberSlot\Database\Schema;
use PlumberSlot\Support\Cache;

defined( 'ABSPATH' ) || exit;

final class SubjectRepository extends AbstractRepository {

	protected function table(): string {
		return Schema::table( Schema::SUBJECTS );
	}

	public function find_for_tutor( int $subject_id, int $tutor_id ): ?object {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE id = %d AND tutor_id = %d',
				$subject_id,
				$tutor_id
			)
		);

		return $row;
	}

	/**
	 * @return list<object>
	 */
	public function all_for_tutor( int $tutor_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (array) $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE tutor_id = %d ORDER BY sort_order ASC, name ASC, id ASC',
				$tutor_id
			)
		);
	}

	/**
	 * @param array<string, mixed> $data Subject values.
	 */
	public function create( int $tutor_id, array $data ): int {
		$values             = $this->writable_data( $data, true );
		$values['tutor_id'] = $tutor_id;

		$inserted = $this->db->insert( $this->table(), $values );

		if ( false === $inserted ) {
			return 0;
		}

		Cache::forget_tutor( $tutor_id );

		return (int) $this->db->insert_id;
	}

	/**
	 * Update only when the subject belongs to the supplied tutor.
	 *
	 * @param array<string, mixed> $data Subject values.
	 */
	public function update_for_tutor( int $subject_id, int $tutor_id, array $data ): bool {
		$values = $this->writable_data( $data, false );

		if ( array() === $values ) {
			return false;
		}

		$updated = $this->db->update(
			$this->table(),
			$values,
			array(
				'id'       => $subject_id,
				'tutor_id' => $tutor_id,
			)
		);

		if ( false === $updated || 0 === $updated ) {
			return false;
		}

		Cache::forget_tutor( $tutor_id );

		return true;
	}

	public function delete_for_tutor( int $subject_id, int $tutor_id ): bool {
		$deleted = $this->db->delete(
			$this->table(),
			array(
				'id'       => $subject_id,
				'tutor_id' => $tutor_id,
			),
			array( '%d', '%d' )
		);

		if ( false === $deleted || 0 === $deleted ) {
			return false;
		}

		Cache::forget_tutor( $tutor_id );

		return true;
	}

	/**
	 * Allow only schema-backed fields so callers cannot inject column names.
	 *
	 * @param array<string, mixed> $data             Subject values.
	 * @param bool                 $include_defaults Include create defaults.
	 * @return array<string, int|string|null>
	 */
	private function writable_data( array $data, bool $include_defaults ): array {
		$values = array();

		if ( $include_defaults || array_key_exists( 'name', $data ) ) {
			$values['name'] = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		}

		foreach ( array( 'level', 'curriculum' ) as $field ) {
			if ( ! $include_defaults && ! array_key_exists( $field, $data ) ) {
				continue;
			}

			$value            = sanitize_text_field( (string) ( $data[ $field ] ?? '' ) );
			$values[ $field ] = '' === $value ? null : $value;
		}

		foreach ( array(
			'duration_min' => 60,
			'price_minor'  => 0,
			'is_trial'     => 0,
			'sort_order'   => 0,
		) as $field => $default ) {
			if ( ! $include_defaults && ! array_key_exists( $field, $data ) ) {
				continue;
			}

			$values[ $field ] = max( 0, (int) ( $data[ $field ] ?? $default ) );
		}

		if ( isset( $values['is_trial'] ) ) {
			$values['is_trial'] = min( 1, $values['is_trial'] );
		}

		if ( $include_defaults || array_key_exists( 'status', $data ) ) {
			$status           = sanitize_key( (string) ( $data['status'] ?? 'active' ) );
			$values['status'] = in_array( $status, array( 'active', 'inactive' ), true ) ? $status : 'active';
		}

		return $values;
	}
}
