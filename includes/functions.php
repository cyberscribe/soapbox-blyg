<?php
/**
 * The public API.
 *
 * These functions are the stable interface for other plugins and themes. The classes under src/
 * are internal and may change between versions. Guard every call:
 *
 *     if ( function_exists( 'soapbox_blyg_publish' ) ) { ... }
 *
 * Register origins on the `soapbox_blyg_register_origins` action, which fires on `init`.
 *
 * @package SoapboxBlyg
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'soapbox_blyg_register_origin' ) ) {
	/**
	 * Registers an origin: one blyg, mounted at one path of this site. Modelled on
	 * register_post_type(): call it on every request, from the `soapbox_blyg_register_origins`
	 * action.
	 *
	 * Once an origin has published an item, its mount and layout are permanent. A later call with
	 * a different path is overruled and reported to the site's administrators.
	 *
	 * @param string $key  Lowercase letters, digits, hyphen and underscore, at most 32 characters.
	 * @param array  $args {
	 *     Origin arguments.
	 *
	 *     @type string   $path        Required. The mount, e.g. '/notes/'.
	 *     @type string   $title       Manifest and feed title.
	 *     @type string   $description Feed description.
	 *     @type array    $author      Manifest identity: name, bio (optional), links (label, url).
	 *     @type string   $layout      'templated' or 'fixed'. Default: the site's setting.
	 *     @type callable $discover    Returns true on pages that should advertise this origin.
	 *     @type int      $priority    Discovery order; lower is asked first. Default 10.
	 *     @type string|callable $redirect Where the bare mount sends a person.
	 * }
	 * @throws InvalidArgumentException For a bad key or path, or a mount another origin holds.
	 */
	function soapbox_blyg_register_origin( string $key, array $args ): void {
		SoapboxBlyg\Plugin::instance()->registry->register( $key, $args );
	}
}

if ( ! function_exists( 'soapbox_blyg_publish' ) ) {
	/**
	 * Hands the ledger the current state of one item. The ledger records a publish event only if
	 * the content differs from what is live, so calling this on every save is correct.
	 *
	 * @param string     $origin     Origin key.
	 * @param string     $source_key Your stable key for the item, at most 191 characters.
	 * @param array|null $snapshot   null for "not public". Otherwise kind ('fragment' or 'thread'),
	 *                               title, permalink (absolute), content_md, content_html, media
	 *                               (url, mime, alt), author (optional), transclusions (threads
	 *                               only, required for them), generated (optional).
	 * @param array      $opts       at, created (ISO 8601 UTC) and note.
	 * @return array { id: ?string, version: ?int, event: created|bumped|withdrawn|returned|none }
	 * @throws InvalidArgumentException For an unknown origin or a snapshot the protocol rejects.
	 */
	function soapbox_blyg_publish( string $origin, string $source_key, ?array $snapshot, array $opts = array() ): array {
		return SoapboxBlyg\Plugin::instance()->ledger->publish( $origin, $source_key, $snapshot, $opts );
	}
}

if ( ! function_exists( 'soapbox_blyg_reconcile' ) ) {
	/**
	 * For sources that publish in bulk: publishes every snapshot, then withdraws every live item
	 * of the origin that the set does not contain.
	 *
	 * @param string $origin              Origin key.
	 * @param array  $snapshots_by_key    source_key => snapshot, or null.
	 * @param array  $opts                As for soapbox_blyg_publish().
	 * @return array Counts per event.
	 */
	function soapbox_blyg_reconcile( string $origin, array $snapshots_by_key, array $opts = array() ): array {
		return SoapboxBlyg\Plugin::instance()->ledger->reconcile( $origin, $snapshots_by_key, $opts );
	}
}

if ( ! function_exists( 'soapbox_blyg_render_html' ) ) {
	/**
	 * Turns HTML into the three things a snapshot needs: sanitised, self-contained content_html,
	 * content_md, and the media list.
	 *
	 * @param string $html  Body HTML.
	 * @param string $title Item title.
	 * @param array  $opts  base: the URL relative links resolve against. Default: the site's home.
	 * @return array { content_md, content_html, media, stats }
	 */
	function soapbox_blyg_render_html( string $html, string $title, array $opts = array() ): array {
		return SoapboxBlyg\Render\ContentRenderer::render_html( $html, $title, $opts );
	}
}

if ( ! function_exists( 'soapbox_blyg_pin' ) ) {
	/**
	 * Pins a version. Irrevocable: that exact content is served at its own URL forever, through
	 * later edits and through withdrawal.
	 *
	 * @param string $id      Item id.
	 * @param int    $version Version to pin.
	 * @throws RuntimeException For a missing version, a withdrawal endcap or an existing pin.
	 */
	function soapbox_blyg_pin( string $id, int $version ): void {
		SoapboxBlyg\Plugin::instance()->ledger->pin( $id, $version );
	}
}

if ( ! function_exists( 'soapbox_blyg_get_status' ) ) {
	/**
	 * What the ledger holds for one source.
	 *
	 * @param string $origin     Origin key.
	 * @param string $source_key Source key.
	 * @return array|null null if it has never been public. Otherwise origin, source_key, id,
	 *                    version, kind, pinned (version numbers) and url (the item document).
	 */
	function soapbox_blyg_get_status( string $origin, string $source_key ): ?array {
		$plugin = SoapboxBlyg\Plugin::instance();
		$status = $plugin->ledger->status( $origin, $source_key );
		if ( null !== $status ) {
			$status['url'] = $plugin->locations->item( $plugin->registry->get( $origin ), $status['id'] );
		}
		return $status;
	}
}

if ( ! function_exists( 'soapbox_blyg_get_origin_url' ) ) {
	/**
	 * An origin's base URL, which is its identity on the wire.
	 *
	 * @param string $origin Origin key.
	 * @throws InvalidArgumentException When the origin is not registered.
	 */
	function soapbox_blyg_get_origin_url( string $origin ): string {
		return SoapboxBlyg\Plugin::instance()->registry->get( $origin )->url();
	}
}
