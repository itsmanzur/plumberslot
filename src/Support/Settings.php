<?php
/**
 * Typed access to the single settings option.
 *
 * One option row, never autoloaded: PlumberSlot settings are only read on
 * PlumberSlot requests, so there is no reason to pay for them on every page load
 * of the whole site.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Support;

defined( 'ABSPATH' ) || exit;

final class Settings {

	private const OPTION = 'plumberslot_settings';

	/** @var array<string, mixed>|null */
	private static ?array $cache = null;

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = is_array( $stored ) ? $stored : array();
		}

		return self::$cache;
	}

	public static function int( string $key, int $fallback = 0 ): int {
		$value = self::all()[ $key ] ?? $fallback;

		return is_numeric( $value ) ? (int) $value : $fallback;
	}

	public static function bool( string $key, bool $fallback = false ): bool {
		$value = self::all()[ $key ] ?? $fallback;

		return (bool) $value;
	}

	public static function string( string $key, string $fallback = '' ): string {
		$value = self::all()[ $key ] ?? $fallback;

		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	/**
	 * @param array<string, mixed> $values Sanitised values.
	 */
	public static function update( array $values ): void {
		update_option( self::OPTION, array_merge( self::all(), $values ), false );
		self::$cache = null;
	}
}
