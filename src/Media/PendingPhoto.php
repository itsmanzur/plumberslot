<?php
/**
 * Lifecycle meta for an uploaded-but-not-yet-attached job-site photo.
 *
 * A photo is uploaded before the booking it belongs to exists (see
 * UploadsController), so the attachment sits "pending" — owned by nobody in
 * particular — until a booking is created and claims it. Any upload that is
 * never claimed is swept up by PendingPhotoCleanup.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Media;

defined( 'ABSPATH' ) || exit;

final class PendingPhoto {

	public const META_PENDING = '_plumberslot_pending_photo';
	public const META_EXPIRES = '_plumberslot_pending_expires';
	public const META_BOOKING = '_plumberslot_booking_id';

	/**
	 * How long an unclaimed upload is kept before the cleanup job deletes it.
	 */
	public const TTL_SECONDS = 2 * HOUR_IN_SECONDS;

	/**
	 * Mark a freshly uploaded attachment as pending, with an expiry the
	 * cleanup job can query against.
	 */
	public static function mark_pending( int $attachment_id ): void {
		update_post_meta( $attachment_id, self::META_PENDING, 1 );
		update_post_meta( $attachment_id, self::META_EXPIRES, time() + self::TTL_SECONDS );
	}

	/**
	 * Whether this attachment is still an unclaimed pending upload — proof it
	 * came through the upload endpoint rather than being an arbitrary
	 * attachment id guessed by the caller.
	 */
	public static function is_pending( int $attachment_id ): bool {
		return (bool) get_post_meta( $attachment_id, self::META_PENDING, true );
	}

	/**
	 * Claim a pending upload for a real booking: record which booking owns it
	 * and clear the pending/expiry meta so the cleanup job leaves it alone.
	 */
	public static function attach_to_booking( int $attachment_id, int $booking_id ): void {
		update_post_meta( $attachment_id, self::META_BOOKING, $booking_id );
		delete_post_meta( $attachment_id, self::META_PENDING );
		delete_post_meta( $attachment_id, self::META_EXPIRES );
	}
}
