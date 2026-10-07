<?php
/**
 * Capabilities.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Admin;

use SoapboxBlyg\Origins\PostTypeSource;

defined( 'ABSPATH' ) || exit;

/**
 * Two meta capabilities, mapped onto what WordPress already grants, so no role is changed and
 * nothing is left behind in the database:
 *
 * - soapbox_blyg_manage: settings, tools, verify and the mentions list. Maps to manage_options.
 * - soapbox_blyg_pin_item: pinning one item's version. For a post's item it maps to being able
 *   to edit that post and to publish posts of its type; for anything else, to soapbox_blyg_manage.
 *
 * Pinning asks for more than editing does because it cannot be undone: a contributor can edit
 * their own draft, but may not make a permanent public promise on the site's behalf.
 *
 * To change either mapping, filter `map_meta_cap`, as for any meta capability.
 */
class Capabilities {

	const MANAGE = 'soapbox_blyg_manage';
	const PIN    = 'soapbox_blyg_pin_item';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'map_meta_cap', array( __CLASS__, 'map' ), 10, 4 );
	}

	/**
	 * Maps the two meta capabilities to primitive ones.
	 *
	 * @param string[] $caps    Primitive capabilities required so far.
	 * @param string   $cap     The capability being checked.
	 * @param int      $user_id The user.
	 * @param array    $args    For soapbox_blyg_pin_item: the source post's id, if the item has one.
	 * @return string[]
	 */
	public static function map( $caps, $cap, $user_id, $args ) {
		if ( self::MANAGE === $cap ) {
			return array( 'manage_options' );
		}
		if ( self::PIN !== $cap ) {
			return $caps;
		}
		$post = isset( $args[0] ) ? get_post( (int) $args[0] ) : null;
		if ( ! $post || ! PostTypeSource::supports( $post->post_type ) ) {
			return array( 'manage_options' );
		}
		$type = get_post_type_object( $post->post_type );
		return array_values(
			array_unique(
				array_merge(
					map_meta_cap( 'edit_post', $user_id, $post->ID ),
					array( $type && isset( $type->cap->publish_posts ) ? $type->cap->publish_posts : 'publish_posts' )
				)
			)
		);
	}
}
