<?php
/**
 * Object-cache wrapper for computed slots.
 *
 * Everything lives in one group so a single flush clears a tutor's whole
 * calendar the instant a booking or a rule changes.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Support;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

final class Cache {

	public const GROUP          = 'tutorslot';
	private const DASHBOARD_TTL = 15;

	public static function slot_key( int $tutor_id, DateTimeImmutable $from, DateTimeImmutable $to, int $duration ): string {
		return sprintf(
			'slots:%d:%s:%s:%d:%d',
			$tutor_id,
			$from->format( 'YmdHi' ),
			$to->format( 'YmdHi' ),
			$duration,
			self::generation( $tutor_id )
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
	public static function dashboard( int $tutor_id ): ?array {
		$value = get_transient( self::dashboard_key( $tutor_id ) );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * @param array<string, mixed> $value Dashboard aggregate.
	 */
	public static function set_dashboard( int $tutor_id, array $value ): void {
		set_transient( self::dashboard_key( $tutor_id ), $value, self::DASHBOARD_TTL );
	}

	/**
	 * Invalidate everything for one tutor.
	 *
	 * Bumping a generation counter is cheaper and more reliable than trying to
	 * enumerate keys, and it works on object caches with no group flush.
	 */
	public static function forget_tutor( int $tutor_id ): void {
		wp_cache_set( self::generation_key( $tutor_id ), self::generation( $tutor_id ) + 1, self::GROUP, 0 );
		delete_transient( self::dashboard_key( $tutor_id ) );
	}

	public static function generation( int $tutor_id ): int {
		$value = wp_cache_get( self::generation_key( $tutor_id ), self::GROUP );

		return is_numeric( $value ) ? (int) $value : 1;
	}

	private static function generation_key( int $tutor_id ): string {
		return 'gen:' . $tutor_id;
	}

	private static function dashboard_key( int $tutor_id ): string {
		return 'tutorslot_dashboard_' . $tutor_id;
	}
}
