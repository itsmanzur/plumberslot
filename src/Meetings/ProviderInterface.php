<?php
/**
 * Contract for video meeting providers.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Meetings;

use WP_Error;

defined( 'ABSPATH' ) || exit;

interface ProviderInterface {

	public function id(): string;

	public function label(): string;

	public function is_connected( int $tutor_id ): bool;

	/**
	 * Create a meeting for a booking.
	 *
	 * @return string|WP_Error Opaque provider reference. Persist it through
	 *                         ProviderRegistry::reference() so cleanup can
	 *                         route it back to the correct provider.
	 */
	public function create( int $booking_id, int $tutor_id, string $start_utc, int $duration_min, string $title ): string|WP_Error;

	public function cancel( string $reference ): bool|WP_Error;

	/**
	 * Resolve a reference into a join URL.
	 *
	 * Called only after the signed join token has been verified, never from a
	 * template and never inside an email body.
	 */
	public function join_url( string $reference ): ?string;
}
