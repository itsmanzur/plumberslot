<?php
/**
 * The `photos` column on a booking row: a JSON array of attachment ids.
 *
 * Kept out of BookingsController so both the REST layer and BookingService
 * (which needs to copy photos forward on reschedule) share one encoding and
 * one ownership check, rather than two slightly different implementations.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Media;

defined( 'ABSPATH' ) || exit;

final class BookingPhotos {

	/**
	 * A job-site photo set is a quick visual aid, not a gallery.
	 */
	public const MAX_PER_BOOKING = 3;

	/**
	 * Encode a list of attachment ids for the `photos` column. Empty input
	 * is stored as NULL rather than an empty-array string, matching how the
	 * rest of this schema represents "nothing here".
	 *
	 * @param list<mixed> $ids Attachment ids.
	 */
	public static function encode( array $ids ): ?string {
		$clean = self::clean_ids( $ids );

		return array() === $clean ? null : wp_json_encode( $clean );
	}

	/**
	 * @param string|null $json Raw `photos` column value.
	 * @return list<int>
	 */
	public static function decode( ?string $json ): array {
		if ( null === $json || '' === $json ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? self::clean_ids( $decoded ) : array();
	}

	/**
	 * Attachment ids the client sent, filtered down to ones that are real
	 * attachments and still marked as an unclaimed pending upload. A
	 * customer must not be able to attach someone else's attachment simply
	 * by guessing its id.
	 *
	 * @param list<mixed> $raw Raw client-supplied ids.
	 * @return list<int>
	 */
	public static function validate_pending( array $raw ): array {
		$valid = array();

		foreach ( self::clean_ids( $raw ) as $id ) {
			$post = get_post( $id );

			if ( ! $post || 'attachment' !== $post->post_type ) {
				continue;
			}

			if ( ! PendingPhoto::is_pending( $id ) ) {
				continue;
			}

			$valid[] = $id;
		}

		return $valid;
	}

	/**
	 * Attachment ids resolved to the shape the admin UI needs — real image
	 * URLs, never raw attachment internals.
	 *
	 * @param string|null $json Raw `photos` column value.
	 * @return list<array{id:int,url:string,thumb_url:string}>
	 */
	public static function present( ?string $json ): array {
		return self::present_ids( self::decode( $json ) );
	}

	/**
	 * Same as present(), starting from already-known ids rather than an
	 * encoded column value — used right after create(), before the row has
	 * been re-read from the database.
	 *
	 * @param list<int> $ids Attachment ids.
	 * @return list<array{id:int,url:string,thumb_url:string}>
	 */
	public static function present_ids( array $ids ): array {
		$out = array();

		foreach ( $ids as $id ) {
			$full  = wp_get_attachment_image_url( $id, 'large' );
			$thumb = wp_get_attachment_image_url( $id, 'thumbnail' );

			// The attachment may have been deleted from the media library since.
			if ( ! $full && ! $thumb ) {
				continue;
			}

			$fallback = (string) wp_get_attachment_url( $id );

			$out[] = array(
				'id'        => $id,
				'url'       => $full ? $full : $fallback,
				'thumb_url' => $thumb ? $thumb : ( $full ? $full : $fallback ),
			);
		}

		return $out;
	}

	/**
	 * @param list<mixed> $ids Raw ids.
	 * @return list<int>
	 */
	private static function clean_ids( array $ids ): array {
		$positive = array_filter(
			array_map( 'absint', $ids ),
			static fn ( int $id ): bool => $id > 0
		);

		return array_slice( array_values( array_unique( $positive ) ), 0, self::MAX_PER_BOOKING );
	}
}
