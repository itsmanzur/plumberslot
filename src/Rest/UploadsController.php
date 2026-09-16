<?php
/**
 * /plumberslot/v1/public/uploads — job-site photos, before the booking exists.
 *
 * The Address step offers to attach photos while the customer may still be
 * anonymous (Account, if needed, comes after Address in the widget flow), so
 * this endpoint has to accept both signed-in and anonymous callers. Every
 * upload becomes a real Media Library attachment, marked "pending" until a
 * booking claims it, so the customer's own future attachment id cannot be
 * reused by anyone else and an abandoned upload is swept up by
 * PendingPhotoCleanup.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Rest;

use PlumberSlot\Media\PendingPhoto;
use PlumberSlot\Support\AuditLog;
use PlumberSlot\Support\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class UploadsController extends AbstractController {

	/**
	 * A phone photo of a leak or a nameplate rarely exceeds a couple of MB;
	 * 8 MB leaves headroom without letting an anonymous endpoint accept
	 * arbitrarily large bodies.
	 */
	private const MAX_BYTES = 8 * MB_IN_BYTES;

	/**
	 * @var list<string>
	 */
	private const ALLOWED_MIME_TYPES = array( 'image/jpeg', 'image/png', 'image/webp' );

	/**
	 * Extension => mime map passed straight to wp_handle_upload(), so the
	 * core upload path itself refuses anything outside this set.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED_EXTENSIONS = array(
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'webp'     => 'image/webp',
	);

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/public/uploads',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload' ),
				'permission_callback' => array( $this, 'can_upload' ),
			)
		);
	}

	/**
	 * Open to anonymous callers — the Address step, where this is offered,
	 * runs before the Account step in the booking widget — so the only
	 * defense against abuse of a public file-upload endpoint is a hard rate
	 * limit, keyed by user id when signed in and by hashed IP otherwise.
	 */
	public function can_upload( WP_REST_Request $request ): bool|WP_Error {
		$nonce = $this->verify_nonce( $request );

		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		if ( ! RateLimiter::allow( 'upload_photo', 10 ) ) {
			return new WP_Error(
				'plumberslot_too_many',
				__( 'Too many photo uploads. Wait a minute and try again.', 'plumberslot' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	public function upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;

		if ( null === $file || empty( $file['tmp_name'] ) ) {
			return new WP_Error(
				'plumberslot_no_file',
				__( 'Choose a photo to upload.', 'plumberslot' ),
				array( 'status' => 400 )
			);
		}

		if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error(
				'plumberslot_upload_failed',
				__( 'That photo could not be uploaded. Try again.', 'plumberslot' ),
				array( 'status' => 400 )
			);
		}

		if ( (int) ( $file['size'] ?? 0 ) > self::MAX_BYTES ) {
			return new WP_Error(
				'plumberslot_file_too_large',
				__( 'Photos must be 8 MB or smaller.', 'plumberslot' ),
				array( 'status' => 413 )
			);
		}

		if ( ! function_exists( 'wp_check_filetype_and_ext' ) || ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// The client's declared MIME type is never trusted; only the file's
		// own bytes decide what it actually is.
		$checked = wp_check_filetype_and_ext( (string) $file['tmp_name'], (string) $file['name'] );

		if ( empty( $checked['ext'] ) || empty( $checked['type'] )
			|| ! in_array( $checked['type'], self::ALLOWED_MIME_TYPES, true ) ) {
			return new WP_Error(
				'plumberslot_bad_file_type',
				__( 'Photos must be a JPEG, PNG or WebP image.', 'plumberslot' ),
				array( 'status' => 415 )
			);
		}

		$moved = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => self::ALLOWED_EXTENSIONS,
			)
		);

		if ( ! is_array( $moved ) || ! empty( $moved['error'] ) ) {
			return new WP_Error(
				'plumberslot_upload_failed',
				is_array( $moved ) && is_string( $moved['error'] ?? null )
					? $moved['error']
					: __( 'That photo could not be uploaded. Try again.', 'plumberslot' ),
				array( 'status' => 500 )
			);
		}

		// post_parent is 0: the booking this photo belongs to does not exist
		// yet and may never (an abandoned checkout is normal), so nothing
		// here can point at a booking id.
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $moved['type'],
				'post_title'     => sanitize_file_name( basename( (string) $moved['file'] ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_parent'    => 0,
			),
			$moved['file']
		);

		if ( ! is_int( $attachment_id ) || $attachment_id <= 0 ) {
			return new WP_Error(
				'plumberslot_upload_failed',
				__( 'That photo could not be saved. Try again.', 'plumberslot' ),
				array( 'status' => 500 )
			);
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, (string) $moved['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		PendingPhoto::mark_pending( $attachment_id );
		AuditLog::record( 'photo.uploaded', 'attachment', $attachment_id );

		$preview_url = wp_get_attachment_image_url( $attachment_id, 'medium' );

		return $this->ok(
			array(
				'id'  => $attachment_id,
				'url' => $preview_url ? $preview_url : (string) wp_get_attachment_url( $attachment_id ),
			),
			201
		);
	}
}
