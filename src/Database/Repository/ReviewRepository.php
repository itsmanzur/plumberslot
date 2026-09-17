<?php
/**
 * Published reviews for technician profile pages.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Database\Repository;

use PlumberSlot\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class ReviewRepository extends AbstractRepository {

	protected function table(): string {
		return Schema::table( Schema::REVIEWS );
	}

	/**
	 * @return list<object>
	 */
	public function approved_for_technician( int $technician_id, int $limit = 6 ): array {
		$limit = max( 1, min( 20, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (array) $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table() . " WHERE technician_id = %d AND status = 'approved' ORDER BY created_at DESC LIMIT %d",
				$technician_id,
				$limit
			)
		);
	}

	/**
	 * @return array{average:float,count:int}
	 */
	public function rating_summary( int $technician_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT AVG(rating) AS average, COUNT(*) AS total FROM ' . $this->table() . " WHERE technician_id = %d AND status = 'approved'",
				$technician_id
			)
		);

		return array(
			'average' => $row && null !== $row->average ? round( (float) $row->average, 1 ) : 0.0,
			'count'   => $row ? (int) $row->total : 0,
		);
	}

	/**
	 * The one review (if any) already on file for a booking -- the
	 * UNIQUE(booking_id) constraint's read-side counterpart, checked before
	 * insert so a duplicate submission gets a clean error instead of a raw
	 * SQL failure.
	 */
	public function for_booking( int $booking_id ): ?object {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE booking_id = %d', $booking_id )
		);
	}

	/**
	 * @param array{booking_id:int, technician_id:int, author_id:int, rating:int, body:?string} $data Review fields.
	 * @return int Review id, or 0 on failure (including the UNIQUE(booking_id) constraint already being taken).
	 */
	public function create( array $data ): int {
		$inserted = $this->db->insert(
			$this->table(),
			array(
				'booking_id'    => $data['booking_id'],
				'technician_id' => $data['technician_id'],
				'author_id'     => $data['author_id'],
				'rating'        => $data['rating'],
				'body'          => $data['body'] ?? null,
				'status'        => 'pending',
				'created_at'    => $this->now(),
			)
		);

		return false === $inserted ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Plain status flip -- admin-only, no concurrent-write race to guard
	 * against, so a compare-and-set is unnecessary here.
	 */
	public function set_status( int $review_id, string $status ): bool {
		return (bool) $this->db->update(
			$this->table(),
			array( 'status' => $status ),
			array( 'id' => $review_id )
		);
	}

	/**
	 * @return list<object>
	 */
	public function pending( int $limit = 50 ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (array) $this->db->get_results(
			$this->db->prepare( 'SELECT * FROM ' . $this->table() . " WHERE status = 'pending' ORDER BY created_at ASC LIMIT %d", max( 1, min( 200, $limit ) ) )
		);
	}
}
