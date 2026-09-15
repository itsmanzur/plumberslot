<?php
/**
 * Available meeting providers.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Meetings;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ProviderRegistry {

	/** @var array<string, ProviderInterface> */
	private array $providers = array();

	public function register( ProviderInterface $provider ): void {
		$this->providers[ $provider->id() ] = $provider;
	}

	public function get( string $id ): ?ProviderInterface {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * @return array<string, ProviderInterface>
	 */
	public function all(): array {
		/** @param array<string, ProviderInterface> $providers Registered providers. */
		return apply_filters( 'tutorslot_meeting_providers', $this->providers );
	}

	/**
	 * Store provider identity with its opaque reference.
	 */
	public static function reference( string $provider_id, string $reference ): string {
		return $provider_id . '|' . $reference;
	}

	public function cancel_reference( string $stored_reference ): bool|WP_Error {
		$parts = explode( '|', $stored_reference, 2 );

		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return new WP_Error(
				'tutorslot_meeting_provider_missing',
				__( 'The meeting provider could not be identified.', 'tutorslot' )
			);
		}

		$provider = $this->get( $parts[0] );

		if ( ! $provider ) {
			return new WP_Error(
				'tutorslot_meeting_provider_missing',
				__( 'The meeting provider is unavailable.', 'tutorslot' )
			);
		}

		return $provider->cancel( $parts[1] );
	}
}
