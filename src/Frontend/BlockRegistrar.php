<?php
/**
 * Gutenberg block wrapping the same widget as the shortcode.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Frontend;

defined( 'ABSPATH' ) || exit;

final class BlockRegistrar {

	public function __construct( private readonly Shortcode $shortcode ) {}

	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	public function register_block(): void {
		$asset_file = PLUMBERSLOT_PATH . 'assets/dist/block.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(
				'wp-blocks',
				'wp-block-editor',
				'wp-components',
				'wp-element',
				'wp-i18n',
				'wp-api-fetch',
			),
			'version'      => \PlumberSlot\VERSION,
		);

		if ( is_readable( PLUMBERSLOT_PATH . 'assets/dist/block.js' ) ) {
			wp_register_script(
				'plumberslot-block-editor',
				PLUMBERSLOT_URL . 'assets/dist/block.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);
		}

		if ( is_readable( PLUMBERSLOT_PATH . 'assets/dist/block.css' ) ) {
			wp_register_style(
				'plumberslot-block-editor',
				PLUMBERSLOT_URL . 'assets/dist/block.css',
				array(),
				$asset['version']
			);
		}

		register_block_type(
			'plumberslot/booking',
			// WordPress accepts an integer API version; the installed stub currently declares string.
			// @phpstan-ignore-next-line argument.type
			array(
				'api_version'     => 3,
				'title'           => __( 'Lesson booking', 'plumberslot' ),
				'category'        => 'widgets',
				'icon'            => 'calendar-alt',
				'description'     => __( 'Let visitors see your open hours and book a lesson.', 'plumberslot' ),
				'attributes'      => array(
					'tutor'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'subject' => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
				'editor_script'   => 'plumberslot-block-editor',
				'editor_style'    => 'plumberslot-block-editor',
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( array $attributes ): string {
		return $this->shortcode->render(
			array(
				'tutor'   => (string) ( $attributes['tutor'] ?? '' ),
				'subject' => (string) ( $attributes['subject'] ?? 0 ),
			)
		);
	}
}
