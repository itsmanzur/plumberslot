<?php
/**
 * Object-cache wrapper for computed slots.
 *
 * Everything lives in one group so a single flush clears a technician's whole
 * calendar the instant a booking or a rule changes.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Support;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

final class Cache {

	public const GROUP          = 'plumberslot';
	private const DASHBOARD_TTL = 15;

	public static function slot_key( int $technician_id, DateTimeImmutable $from, DateTimeImmutable $to, int $duration ): string {
		return sprintf(
			'slots:%d:%s:%s:%d:%d',
			$technician_id,
			$from->format( 'YmdHi' ),
			$to->format( 'YmdHi' ),
			$duration,
			self::generation( $technician_id )
		);
	}

	public static function get( string $key ): mixed {
		return wp_cache_get( $key, self::GROUP );
	}

	public static function set( string $key, mixed $value, int $ttl ): void {
		wp_cache_set( $key, $value, self::GROUP, $ttl );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function dashboard( int $technician_id ): ?array {
		$value = get_transient( self::dashboard_key( $technician_id ) );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * @param array<string, mixed> $value Dashboard aggregate.
	 */
	public static function set_dashboard( int $technician_id, array $value ): void {
		set_transient( self::dashboard_key( $technician_id ), $value, self::DASHBOARD_TTL );
	}

	/**
	 * Invalidate everything for one technician.
	 *
	 * Bumping a generation counter is cheaper and more reliable than trying to
	 * enumerate keys, and it works on object caches with no group flush.
	 */
	public static function forget_technician( int $technician_id ): void {
		wp_cache_set( self::generation_key( $technician_id ), self::generation( $technician_id ) + 1, self::GROUP, 0 );
		delete_transient( self::dashboard_key( $technician_id ) );
	}

	public static function generation( int $technician_id ): int {
		$value = wp_cache_get( self::generation_key( $technician_id ), self::GROUP );

		return is_numeric( $value ) ? (int) $value : 1;
	}

	private static function generation_key( int $technician_id ): string {
		return 'gen:' . $technician_id;
	}

	private static function dashboard_key( int $technician_id ): string {
		return 'plumberslot_dashboard_' . $technician_id;
	}
}
