<?php
/**
 * Bundled PSR-4 autoloader.
 *
 * Composer is the right tool during development, but a plugin downloaded as a
 * ZIP has no vendor directory, and requiring one that isn't there is a fatal
 * error on activation rather than a helpful message. This loader means the
 * plugin runs from a plain ZIP; Composer's autoloader takes over when present.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	private const PREFIX = 'TutorSlot\\';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		spl_autoload_register(
			static function ( string $class_name ): void {
				if ( ! str_starts_with( $class_name, self::PREFIX ) ) {
					return;
				}

				$relative = substr( $class_name, strlen( self::PREFIX ) );
				$path     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

				// Keep the loader inside src/ even if a class name contains dots.
				if ( str_contains( $relative, '..' ) ) {
					return;
				}

				if ( is_readable( $path ) ) {
					require_once $path;
				}
			}
		);
	}
}
