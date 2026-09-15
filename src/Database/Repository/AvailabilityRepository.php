<?php
/**
 * Weekly rules and one-off exceptions.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Database\Repository;

use PlumberSlot\Database\Schema;
use PlumberSlot\Domain\Contract\AvailabilitySource;
use PlumberSlot\Support\Cache;

defined( 'ABSPATH' ) || exit;

final class AvailabilityRepository extends AbstractRepository implements AvailabilitySource {

	protected function table(): string {
		return Schema::table( Schema::AVAILABILITY );
	}

	/**
	 * @return list<object>
	 */
	public function rules_for( int $technician_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (array) $this->db->get_results(
			$this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE technician_id = %d ORDER BY weekday, start_min', $technician_id )
		);
	}

	/**
	 * @return list<object>
	 */
	public function exceptions_between( int $technician_id, string $from_date, string $to_date ): array {
		$table = Schema::table( Schema::EXCEPTIONS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (array) $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $table . ' WHERE technician_id = %d AND on_date BETWEEN %s AND %s ORDER BY on_date ASC, id ASC',
				$technician_id,
				$from_date,
				$to_date
			)
		);
	}

	/**
	 * Upcoming and recent exceptions for the availability editor.
	 *
	 * @return list<object>
	 */
	public function exceptions_for_technician( int $technician_id, int $limit = 50 ): array {
		$table = Schema::table( Schema::EXCEPTIONS );
		$limit = max( 1, min( 200, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (array) $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $table . ' WHERE technician_id = %d ORDER BY on_date DESC, id DESC LIMIT %d',
				$technician_id,
				$limit
			)
		);
	}

	/**
	 * @param array{on_date:string,kind:string,start_min?:?int,end_min?:?int,note?:?string} $data Exception.
	 */
	public function add_exception( int $technician_id, array $data ): int {
		$table = Schema::table( Schema::EXCEPTIONS );
		$kind  = sanitize_key( (string) ( $data['kind'] ?? 'closed' ) );

		if ( ! in_array( $kind, array( 'closed', 'open' ), true ) ) {
			$kind = 'closed';
		}

		$inserted = $this->db->insert(
			$table,
			array(
				'technician_id' => $technician_id,
				'on_date'       => (string) $data['on_date'],
				'kind'          => $kind,
				'start_min'     => isset( $data['start_min'] ) ? (int) $data['start_min'] : null,
				'end_min'       => isset( $data['end_min'] ) ? (int) $data['end_min'] : null,
				'note'          => isset( $data['note'] ) ? sanitize_text_field( (string) $data['note'] ) : null,
			)
		);

		if ( false === $inserted ) {
			return 0;
		}

		Cache::forget_technician( $technician_id );

		return (int) $this->db->insert_id;
	}

	public function delete_exception( int $technician_id, int $exception_id ): bool {
		$table   = Schema::table( Schema::EXCEPTIONS );
		$deleted = $this->db->delete(
			$table,
			array(
				'id'            => $exception_id,
				'technician_id' => $technician_id,
			),
			array( '%d', '%d' )
		);

		if ( false === $deleted || 0 === $deleted ) {
			return false;
		}

		Cache::forget_technician( $technician_id );

		return true;
	}

	/**
	 * Replace a technician's whole week in one transaction. The grid editor always
	 * sends the complete week, so a partial write can never leave a half-saved
	 * schedule behind.
	 *
	 * @param list<array{weekday:int,start_min:int,end_min:int}> $rules Rules.
	 */
	public function replace_week( int $technician_id, array $rules ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transaction control statement.
		if ( false === $this->db->query( 'START TRANSACTION' ) ) {
			return false;
		}

		$deleted = $this->db->delete( $this->table(), array( 'technician_id' => $technician_id ), array( '%d' ) );

		if ( false === $deleted ) {
			$this->rollback();

			return false;
		}

		foreach ( $rules as $rule ) {
			$inserted = $this->db->insert(
				$this->table(),
				array(
					'technician_id' => $technician_id,
					'weekday'       => $rule['weekday'],
					'start_min'     => $rule['start_min'],
					'end_min'       => $rule['end_min'],
				),
				array( '%d', '%d', '%d', '%d' )
			);

			if ( false === $inserted ) {
				$this->rollback();

				return false;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transaction control statement.
		if ( false === $this->db->query( 'COMMIT' ) ) {
			$this->rollback();

			return false;
		}

		Cache::forget_technician( $technician_id );

		return true;
	}

	private function rollback(): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transaction control statement.
		$this->db->query( 'ROLLBACK' );
	}
}
