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
}
