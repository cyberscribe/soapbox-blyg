<?php
/**
 * Where each document lives.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Surface;

use SoapboxBlyg\Ledger\Id;
use SoapboxBlyg\Ledger\Ledger;
use SoapboxBlyg\Origins\Origin;
use SoapboxBlyg\Origins\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that knows the URL of every document of an origin, in either layout.
 *
 * The manifest is always at {origin}blyg.json: that filename is what lets a reader handed a bare
 * origin find the blyg, and it is what makes the origin, and so the blyg's identity, a stable
 * path of the site's own choosing (spec §12.2, §16.6e). Everything past the manifest is located
 * by the manifest:
 *
 * - templated: the documents are behind REST routes, and the manifest carries absolute `feed` and
 *   `items` URLs and the `item` and `pin` templates. A change to the REST prefix moves those URLs
 *   and readers follow, because they expand the templates from the manifest every time.
 * - fixed: the protocol's default paths under the mount, for readers that cannot expand templates.
 *
 * Everything that builds or recognises a document URL goes through here: the manifest, the feed,
 * discovery, the router, and the Webmention receiver checking a target.
 */
class Locations {

	/**
	 * The registry.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * The ledger.
	 *
	 * @var Ledger
	 */
	private $ledger;

	/**
	 * Constructor.
	 *
	 * @param Registry $registry Origin registry.
	 * @param Ledger   $ledger   The ledger.
	 */
	public function __construct( Registry $registry, Ledger $ledger ) {
		$this->registry = $registry;
		$this->ledger   = $ledger;
	}

	/**
	 * The REST namespace of the public documents and the admin routes.
	 */
	public static function rest_namespace(): string {
		return SOAPBOX_BLYG_SLUG . '/v1';
	}

	/**
	 * The REST route base of an origin's documents, relative to the namespace.
	 *
	 * @param string $key Origin key.
	 */
	public static function rest_base( string $key ): string {
		return '/origins/' . $key;
	}

	/**
	 * The manifest URL.
	 *
	 * @param Origin $origin The origin.
	 */
	public function manifest( Origin $origin ): string {
		return $origin->url() . 'blyg.json';
	}

	/**
	 * The feed URL.
	 *
	 * @param Origin $origin The origin.
	 */
	public function feed( Origin $origin ): string {
		return 'fixed' === $origin->layout ? $origin->url() . 'feed.xml' : $this->rest( $origin, '/feed' );
	}

	/**
	 * The archive index URL.
	 *
	 * @param Origin $origin The origin.
	 */
	public function index( Origin $origin ): string {
		return 'fixed' === $origin->layout ? $origin->url() . 'items/index.json' : $this->rest( $origin, '/items' );
	}

	/**
	 * The item document URL, or with no id its RFC 6570 level-1 template.
	 *
	 * @param Origin      $origin The origin.
	 * @param string|null $id     Item id.
	 */
	public function item( Origin $origin, $id = null ): string {
		$template = 'fixed' === $origin->layout ? $origin->url() . 'items/{id}.json' : $this->rest( $origin, '/items/{id}' );
		return null === $id ? $template : str_replace( '{id}', rawurlencode( $id ), $template );
	}

	/**
	 * The pinned version URL, or with no id its template.
	 *
	 * @param Origin      $origin  The origin.
	 * @param string|null $id      Item id.
	 * @param int|null    $version Pinned version.
	 */
	public function pin( Origin $origin, $id = null, $version = null ): string {
		$template = 'fixed' === $origin->layout ? $origin->url() . 'items/{id}/v{n}.json' : $this->rest( $origin, '/items/{id}/v/{n}' );
		if ( null === $id ) {
			return $template;
		}
		return str_replace( array( '{id}', '{n}' ), array( rawurlencode( $id ), (int) $version ), $template );
	}

	/**
	 * The Webmention endpoint URL. It sits inside the origin in both layouts: it is a protocol
	 * surface of the blyg, not an API of the site (spec §15.1).
	 *
	 * @param Origin $origin The origin.
	 */
	public function webmention( Origin $origin ): string {
		return $origin->url() . 'webmention';
	}

	/**
	 * A REST URL under an origin's base. rest_url() is asked at serve time, so the result follows
	 * the site's permalink structure and any rest_url_prefix filter. The braces of a template are
	 * restored after rest_url() has encoded them.
	 *
	 * @param Origin $origin The origin.
	 * @param string $path   Path below the origin's base.
	 */
	private function rest( Origin $origin, string $path ): string {
		$url = rest_url( self::rest_namespace() . self::rest_base( $origin->key ) . $path );
		return str_replace( array( '%7B', '%7D', '%7b', '%7d' ), array( '{', '}', '{', '}' ), $url );
	}

	/**
	 * The manifest's pointers for an origin: what a reader follows from blyg.json.
	 *
	 * A fixed layout names the default feed and index and no templates: absent means default, and
	 * a reader that predates the templates reads exactly what it always has.
	 *
	 * @param Origin $origin The origin.
	 * @return array Manifest keys in order.
	 */
	public function pointers( Origin $origin ): array {
		if ( 'fixed' === $origin->layout ) {
			return array(
				'feed'  => 'feed.xml',
				'items' => 'items/index.json',
			);
		}
		return array(
			'feed'  => $this->feed( $origin ),
			'items' => $this->index( $origin ),
			'item'  => $this->item( $origin ),
			'pin'   => $this->pin( $origin ),
		);
	}

	/**
	 * The item a URL names, within one origin: by its page, in any spelling the item has ever had,
	 * or by its item document URL in the origin's current layout. This is the check a Webmention's
	 * target has to pass (spec §15.3, §16.6e: receivers check targets against their own templates).
	 *
	 * @param Origin $origin The origin.
	 * @param string $url    An absolute URL.
	 * @return array|null The item row.
	 */
	public function item_for_url( Origin $origin, string $url ) {
		$url = self::strip_fragment( $url );

		$template = $this->item( $origin );
		$pattern  = '#^' . str_replace( '\{id\}', '([0-9a-hjkmnp-tv-z]{26})', preg_quote( $template, '#' ) ) . '$#';
		if ( preg_match( $pattern, $url, $m ) && Id::is_valid( $m[1] ) ) {
			$item = $this->ledger->item_by_id( $m[1] );
			return $item && $item['origin_key'] === $origin->key ? $item : null;
		}
		// A pinned version's own address names its item too, where that version is in fact pinned:
		// somebody answering a pinned version is answering the item.
		$pattern = '#^' . str_replace( array( '\{id\}', '\{n\}' ), array( '([0-9a-hjkmnp-tv-z]{26})', '([1-9][0-9]{0,8})' ), preg_quote( $this->pin( $origin ), '#' ) ) . '$#';
		if ( preg_match( $pattern, $url, $m ) && Id::is_valid( $m[1] ) ) {
			$item    = $this->ledger->item_by_id( $m[1] );
			$version = $item && $item['origin_key'] === $origin->key ? $this->ledger->version_row( $m[1], (int) $m[2] ) : null;
			return $version && (int) $version['pinned'] ? $item : null;
		}
		return $this->ledger->item_by_page( self::spellings( $url ), $origin->key );
	}

	/**
	 * The spellings under which one page may have been recorded: with and without a trailing slash.
	 *
	 * @param string $url An absolute URL.
	 * @return string[]
	 */
	public static function spellings( string $url ): array {
		$url = self::strip_fragment( $url );
		return array( $url, untrailingslashit( $url ), trailingslashit( $url ) );
	}

	/**
	 * A URL without its fragment.
	 *
	 * @param string $url URL.
	 */
	private static function strip_fragment( string $url ): string {
		$hash = strpos( $url, '#' );
		return false === $hash ? $url : substr( $url, 0, $hash );
	}

	/**
	 * A URL's origin in the HTTP sense: scheme, host and port, normalised, with default ports
	 * elided. Two URLs speak for the same server exactly when these match.
	 *
	 * @param string $url URL.
	 * @return string e.g. "https://example.com" or "http://localhost:8888"; '' if not http(s).
	 */
	public static function http_origin( string $url ): string {
		$parts  = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
			return '';
		}
		$port    = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		$default = 'https' === $scheme ? 443 : 80;
		return $scheme . '://' . strtolower( $parts['host'] ) . ( null !== $port && $port !== $default ? ':' . $port : '' );
	}
}
