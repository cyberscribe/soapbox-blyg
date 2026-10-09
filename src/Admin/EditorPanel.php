<?php
/**
 * The editor's Blyg panel.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Admin;

use SoapboxBlyg\Origins\PostTypeSource;
use SoapboxBlyg\Plugin;
use SoapboxBlyg\Rest\AdminController;

defined( 'ABSPATH' ) || exit;

/**
 * Shows a post's blyg item where the post is edited: its id, version and changelog, and a button
 * to pin a version.
 *
 * In the block editor it is a document settings panel. With the Classic Editor it is a meta box.
 * Both read and pin through the same REST routes, and neither is part of the post form: the block
 * editor saves a post and then its meta boxes in separate requests, so a pin carried in the post
 * form could land on either side of a new version. A pin sent on its own pins exactly the version
 * the button names.
 */
class EditorPanel {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'block_editor_assets' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ), 10, 2 );
	}

	/**
	 * Whether the post being edited is of a type that publishes, on a site that publishes.
	 *
	 * @param string $post_type Post type.
	 */
	private static function applies( string $post_type ): bool {
		return Plugin::instance()->registry->has( PostTypeSource::ORIGIN ) && PostTypeSource::supports( $post_type );
	}

	/**
	 * Loads the panel in the block editor.
	 */
	public static function block_editor_assets(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || ! self::applies( (string) $screen->post_type ) ) {
			return;
		}
		ToolsPage::enqueue( 'editor-panel' );
	}

	/**
	 * Adds the meta box for the Classic Editor. It is marked as a back-compat box, so the block
	 * editor, which has the panel, does not show it as well.
	 *
	 * @param string   $post_type Post type.
	 * @param \WP_Post $post      The post.
	 */
	public static function meta_box( $post_type, $post ): void {
		if ( ! self::applies( (string) $post_type ) || ! $post instanceof \WP_Post ) {
			return;
		}
		add_meta_box(
			'soapbox-blyg',
			__( 'Blyg', 'soapbox-for-blygger' ),
			array( __CLASS__, 'render_meta_box' ),
			$post_type,
			'side',
			'low',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * Renders the meta box: the item as it stands, server-side, and a mount point the script
	 * takes over so that pinning refreshes it without a page load.
	 *
	 * @param \WP_Post $post The post.
	 */
	public static function render_meta_box( $post ): void {
		ToolsPage::enqueue( 'tools', array( 'post' => (int) $post->ID ) );
		$data = AdminController::item_data( (int) $post->ID );
		echo '<div data-soapbox-blyg-item="' . (int) $post->ID . '">';
		if ( ! $data['item'] ) {
			echo '<p>' . esc_html__( 'Not on the blyg yet. A post joins it when it is published.', 'soapbox-for-blygger' ) . '</p>';
		} else {
			printf(
				'<p><code style="font-size:11px">%1$s</code><br>%2$s &middot; <a href="%3$s" target="_blank" rel="noopener">%4$s</a></p>',
				esc_html( $data['item']['id'] ),
				esc_html(
					sprintf(
						/* translators: 1: version number, 2: kind (thread, fragment or withdrawn) */
						__( 'Version %1$d, %2$s', 'soapbox-for-blygger' ),
						(int) $data['item']['version'],
						$data['item']['kind']
					)
				),
				esc_url( $data['item']['url'] ),
				esc_html__( 'Item document', 'soapbox-for-blygger' )
			);
		}
		echo '</div>';
	}
}
