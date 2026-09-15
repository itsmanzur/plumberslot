<?php
/**
 * Slot holds. A student pays inside a window; nobody else can take the slot.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Database\Repository;

use TutorSlot\Database\Schema;
use TutorSlot\Domain\Contract\HoldOccupancySource;
use TutorSlot\Support\Cache;

defined( 'ABSPATH' ) || exit;

final class LockRepository extends AbstractRepository implements HoldOccupancySource {

	protected function table(): string {
		return Schema::table( Schema::LOCKS );
	}

	/**
	 * Claim a slot. Returns the token, or null when someone already holds it.
	 */
	public function acquire( int $tutor_id, int $owner_id, string $start_utc, int $minutes ): ?string {
		$this->purge_expired();

		$token = bin2hex( random_bytes( 32 ) );

		$sql = 'INSERT IGNORE INTO ' . $this->table() . ' (tutor_id, owner_id, start_utc, token, expires_at) VALUES (%d, %d, %s, %s, %s)';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- prepared here.
		$affected = $this->db->query(
			$this->db->prepare( $sql, $tutor_id, $owner_id, $start_utc, $token, gmdate( 'Y-m-d H:i:s', time() + ( $minutes * MINUTE_IN_SECONDS ) ) )
		);

		if ( 1 !== $affected ) {
			return null;
		}

		Cache::forget_tutor( $tutor_id );

		return $token;
	}

	public function release( string $token ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal lock token lookup.
		$tutor_id = (int) $this->db->get_var(
			$this->db->prepare( 'SELECT tutor_id FROM ' . $this->table() . ' WHERE token = %s', $token )
		);
		$this->db->delete( $this->table(), array( 'token' => $token ), array( '%s' ) );

		if ( $tutor_id > 0 ) {
			Cache::forget_tutor( $tutor_id );
		}
	}

	/**
	 * Release a hold only when it belongs to the requesting user.
	 */
	public function release_owned( string $token, int $owner_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal lock token lookup.
		$tutor_id = (int) $this->db->get_var(
			$this->db->prepare(
				'SELECT tutor_id FROM ' . $this->table() . ' WHERE token = %s AND owner_id = %d',
				$token,
				$owner_id
			)
		);

		if ( $tutor_id <= 0 ) {
			return false;
		}

		$released = $this->db->delete(
			$this->table(),
			array(
				'token'    => $token,
				'owner_id' => $owner_id,
			),
			array( '%s', '%d' )
		);

		if ( $released ) {
			Cache::forget_tutor( $tutor_id );
		}

		return 1 === $released;
	}

	public function verify( string $token, int $tutor_id, int $owner_id, string $start_utc ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (bool) $this->db->get_var(
			$this->db->prepare(
				'SELECT id FROM ' . $this->table() . ' WHERE token = %s AND tutor_id = %d AND owner_id = %d AND start_utc = %s AND expires_at > %s',
				$token,
				$tutor_id,
				$owner_id,
				$start_utc,
				$this->now()
			)
		);
	}

	/**
	 * Held start times in a window, so the slot engine can grey them out.
	 *
	 * @return list<string>
	 */
	public function held_in_range( int $tutor_id, string $from_utc, string $to_utc ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return array_map(
			'strval',
			(array) $this->db->get_col(
				$this->db->prepare(
					'SELECT start_utc FROM ' . $this->table() . ' WHERE tutor_id = %d AND expires_at > %s AND start_utc BETWEEN %s AND %s',
					$tutor_id,
					$this->now(),
					$from_utc,
					$to_utc
				)
			)
		);
	}

	public function purge_expired(): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (int) $this->db->query(
			$this->db->prepare( 'DELETE FROM ' . $this->table() . ' WHERE expires_at < %s', $this->now() )
		);
	}
}
