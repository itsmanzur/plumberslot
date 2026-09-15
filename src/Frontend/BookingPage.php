<?php
/**
 * Public booking page: create once, reuse, resolve URL.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Frontend;

use PlumberSlot\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class BookingPage {

	/**
	 * Permalink for the technician's public booking page.
	 * Creates the page on first use when none is saved yet.
	 */
	public static function url_for_technician( object $technician ): string {
		$slug    = sanitize_title( (string) ( $technician->slug ?? '' ) );
		$page_id = self::ensure( $slug );

		if ( $page_id > 0 ) {
			$url = get_permalink( $page_id );

			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		if ( '' === $slug ) {
			return home_url( '/' );
		}

		return home_url( '/?plumberslot=' . rawurlencode( $slug ) );
	}

	/**
	 * Ensure a published page exists with the booking shortcode.
	 *
	 * @return int Page ID, or 0 on failure.
	 */
	public static function ensure( string $technician_slug ): int {
		$slug = sanitize_title( $technician_slug );

		if ( '' === $slug ) {
			return 0;
		}

		$page_id = (int) Settings::int( 'booking_page_id', 0 );

		if ( $page_id > 0 && self::is_usable( $page_id ) ) {
			self::sync_shortcode( $page_id, $slug );

			return $page_id;
		}

		$existing = get_page_by_path( 'book' );

		if ( $existing instanceof \WP_Post && self::is_usable( (int) $existing->ID ) ) {
			$page_id = (int) $existing->ID;
			self::sync_shortcode( $page_id, $slug );
			Settings::update( array( 'booking_page_id' => $page_id ) );

			return $page_id;
		}

		$author_id = get_current_user_id();
		if ( $author_id <= 0 ) {
			$author_id = 1;
		}

		$created = wp_insert_post(
			array(
				'post_title'   => __( 'Book a lesson', 'plumberslot' ),
				'post_name'    => 'book',
				'post_content' => self::shortcode( $slug ),
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_author'  => $author_id,
			),
			true
		);

		if ( is_wp_error( $created ) || ! $created ) {
			return 0;
		}

		$page_id = (int) $created;
		Settings::update( array( 'booking_page_id' => $page_id ) );

		return $page_id;
	}

	private static function shortcode( string $slug ): string {
		return sprintf( '[plumberslot technician="%s"]', $slug );
	}

	private static function is_usable( int $page_id ): bool {
		$post = get_post( $page_id );

		return $post instanceof \WP_Post
			&& 'page' === $post->post_type
			&& 'trash' !== $post->post_status
			&& 'auto-draft' !== $post->post_status;
	}

	/**
	 * Keep the shortcode in sync when the page is still ours (empty or plumberslot only).
	 */
	private static function sync_shortcode( int $page_id, string $slug ): void {
		$post = get_post( $page_id );

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$content  = (string) $post->post_content;
		$expected = self::shortcode( $slug );

		if ( $content === $expected ) {
			return;
		}

		$empty     = '' === trim( wp_strip_all_tags( $content ) );
		$ours_only = has_shortcode( $content, 'plumberslot' )
			&& ! has_shortcode( $content, 'plumberslot_dashboard' )
			&& strlen( trim( $content ) ) < 120;

		if ( ! $empty && ! $ours_only ) {
			return;
		}

		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => $expected,
			)
		);
	}
}
