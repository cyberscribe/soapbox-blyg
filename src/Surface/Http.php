<?php
/**
 * The one sender.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Surface;

defined( 'ABSPATH' ) || exit;

/**
 * Sends every response of the public surface, whichever way the request arrived: through a
 * rewrite rule or through a REST route. One sender is what makes the two paths answer with the
 * same headers and the same bytes.
 *
 * The rule it keeps: every response is something a static file host could serve (spec §4). No
 * query string is read, and nothing varies by who is asking or when, apart from a 304. That is
 * also what keeps the ETags stable.
 */
class Http {

	const JSON = 'application/json; charset=utf-8';
	const RSS  = 'application/rss+xml; charset=utf-8';
	const TEXT = 'text/plain; charset=utf-8';
	const HTML = 'text/html; charset=utf-8';

	/**
	 * The Expires of a response that must not be stored: the date WordPress itself uses for that.
	 */
	const LONG_PAST = 'Wed, 11 Jan 1984 05:00:00 GMT';

	/**
	 * Headers set by core, the REST server or another plugin that must not ride along. These
	 * responses stand in for static files: a cookie or a no-cache header would stop shared caches
	 * from holding them or hand one visitor's cookie to another, and the REST server's own CORS
	 * headers are credentialed and echo the caller's Origin.
	 */
	const FOREIGN_HEADERS = array(
		'Set-Cookie',
		'Expires',
		'Pragma',
		'Cache-Control',
		'Last-Modified',
		'ETag',
		'Link',
		'Allow',
		'Vary',
		'X-Pingback',
		'X-Robots-Tag',
		'X-WP-Total',
		'X-WP-TotalPages',
		'X-WP-Nonce',
		'Access-Control-Allow-Origin',
		'Access-Control-Allow-Credentials',
		'Access-Control-Allow-Headers',
		'Access-Control-Allow-Methods',
		'Access-Control-Expose-Headers',
		'Content-Type',
	);

	/**
	 * A 200 with a document body.
	 *
	 * @param string $type     Content type.
	 * @param string $body     Body bytes.
	 * @param int    $modified Last-modified unix time.
	 * @param array  $more     immutable (bool) for pinned documents; link (array of Link header values).
	 * @return array A response for send().
	 */
	public static function document( string $type, string $body, int $modified, array $more = array() ): array {
		return array(
			'status'   => 200,
			'type'     => $type,
			'body'     => $body,
			'modified' => $modified,
		) + $more;
	}

	/**
	 * A plain-text 404.
	 *
	 * @return array A response for send().
	 */
	public static function not_found(): array {
		return array(
			'status' => 404,
			'type'   => self::TEXT,
			'body'   => "Not found\n",
		);
	}

	/**
	 * A 405 naming what is allowed.
	 *
	 * @param string $allow Allow header value.
	 * @return array A response for send().
	 */
	public static function method_not_allowed( string $allow ): array {
		return array(
			'status' => 405,
			'type'   => self::TEXT,
			'body'   => "Method not allowed\n",
			'allow'  => $allow,
		);
	}

	/**
	 * The answer to a CORS preflight. A browser preflights a cross-origin fetch that sets
	 * If-None-Match itself; answering lets in-browser readers poll conditionally (spec §12.3).
	 *
	 * @return array A response for send().
	 */
	public static function preflight(): array {
		return array(
			'status'    => 204,
			'type'      => null,
			'body'      => '',
			'preflight' => true,
		);
	}

	/**
	 * The request method.
	 */
	public static function method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	}

	/**
	 * Sends a response. Does not exit: the rewrite path exits afterwards, and the REST path returns
	 * to the server, which has nothing left to add.
	 *
	 * @param array $response status, type, body, and optionally modified, immutable, allow,
	 *                        location, retry_after, preflight, link, cache (false for responses
	 *                        that must not be stored, such as the Webmention endpoint's).
	 */
	public static function send( array $response ): void {
		// Page caches following the usual convention must not store these as pages.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the cache plugins' shared convention.
		}

		// Discard anything already buffered. On a site with debug display on, a stray notice from
		// another plugin would otherwise land in the middle of the JSON.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		if ( headers_sent() ) {
			return;
		}
		foreach ( self::FOREIGN_HEADERS as $name ) {
			header_remove( $name );
		}

		$status = (int) $response['status'];
		$body   = (string) $response['body'];

		status_header( $status );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Expose-Headers: ETag, Last-Modified' );
		header( 'X-Content-Type-Options: nosniff' );

		// Before anything returns early: a response sent without these is one the server fills in.
		foreach ( self::caching( $response, $status ) as $name => $value ) {
			header( $name . ': ' . $value );
		}

		if ( ! empty( $response['preflight'] ) ) {
			header( 'Access-Control-Allow-Methods: GET, HEAD, OPTIONS' );
			header( 'Access-Control-Allow-Headers: If-None-Match, If-Modified-Since' );
			header( 'Access-Control-Max-Age: ' . Caching::PREFLIGHT_LIFETIME );
			return;
		}
		if ( isset( $response['allow'] ) ) {
			header( 'Allow: ' . $response['allow'] );
		}
		if ( isset( $response['retry_after'] ) ) {
			header( 'Retry-After: ' . (int) $response['retry_after'] );
		}
		if ( isset( $response['location'] ) ) {
			header( 'Location: ' . $response['location'] );
		}
		foreach ( isset( $response['link'] ) ? (array) $response['link'] : array() as $link ) {
			header( 'Link: ' . $link, false );
		}

		if ( null !== $response['type'] ) {
			header( 'Content-Type: ' . $response['type'] );
		}

		if ( 200 === $status && isset( $response['modified'] ) ) {
			$etag     = self::etag( $body );
			$modified = (int) $response['modified'];
			header( 'ETag: ' . $etag );
			if ( $modified > 0 ) {
				header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified ) . ' GMT' );
			}
			if ( self::not_modified( $etag, $modified ) ) {
				status_header( 304 );
				header_remove( 'Content-Type' );
				return;
			}
		}

		header( 'Content-Length: ' . strlen( $body ) );
		if ( 'HEAD' !== self::method() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a complete JSON, XML or HTML document built and escaped by Surface\Documents; escaping it again would corrupt it.
			echo $body;
		}
	}

	/**
	 * The ETag of a body: strong, and the same for the same bytes whichever path served them.
	 *
	 * @param string $body Body bytes.
	 */
	public static function etag( string $body ): string {
		return '"' . substr( hash( 'sha256', $body ), 0, 32 ) . '"';
	}

	/**
	 * The caching headers of a response: Cache-Control and Expires, and for a response that must
	 * not be stored, the rest of core's no-cache set.
	 *
	 * The plugin cannot stop a web server adding caching headers of its own after PHP has finished,
	 * and stock hosting does. Breeze's "Browser Cache" option, for one, writes mod_expires rules
	 * by content type into .htaccess, with a default of a month; and up to Breeze 2.2.24 it also
	 * wrote `Header append Cache-Control "s-maxage=2592000"` for every response, which stays in
	 * .htaccess on a site that has upgraded since. What it can do is leave them nothing to change:
	 *
	 * - s-maxage is sent here, so the server's comes second. When a directive is repeated, a cache
	 *   uses the first or treats the response as stale (RFC 9111 §4.2.1), and either is safe.
	 *   Varnish joins repeated Cache-Control headers into one (http_CollectHdr in cache_fetch.c)
	 *   and reads the first s-maxage in it (http_GetHdrField in cache_rfc2616.c). nginx reads the
	 *   first within a header line too, but when the server's arrives as a line of its own it
	 *   reads each line in turn and the last one read stands
	 *   (ngx_http_upstream_process_cache_control in ngx_http_upstream.c). That case is beyond
	 *   reach from here, which is why Site Health also asks the cache itself.
	 * - Expires is sent here, so mod_expires adds neither its own nor the max-age it pairs with
	 *   it: it leaves alone a response that already has one (expires_filter in mod_expires.c).
	 *
	 * @param array $response A response for send().
	 * @param int   $status   The HTTP status.
	 * @return array Header name => value.
	 */
	public static function caching( array $response, int $status ): array {
		if ( isset( $response['cache'] ) && false === $response['cache'] ) {
			// Core's own set, which other plugins and hosts know, with no-store made certain: core
			// sends it only to logged-in users, and the set can be filtered.
			$headers = array_filter( wp_get_nocache_headers(), 'is_string' );
			$value   = isset( $headers['Cache-Control'] ) ? $headers['Cache-Control'] : '';
			if ( ! Caching::has( $value, 'no-store' ) ) {
				$headers['Cache-Control'] = rtrim( 'no-store, ' . $value, ', ' );
			}
			return array( 'Expires' => self::LONG_PAST ) + $headers;
		}

		$lifetime = empty( $response['immutable'] ) ? Caching::lifetime() : Caching::PIN_LIFETIME;

		/**
		 * Filters the Cache-Control value of a surface response.
		 *
		 * To change how long documents are cached, use the plugin's setting or the
		 * soapbox_blyg_cache_lifetime filter: Expires and Site Health follow those. This filter
		 * is for adding directives. Keep the lifetime short for everything but pinned documents:
		 * a withdrawn item must stop being served, and a cache that holds its old content for a
		 * month breaks that.
		 *
		 * Keep an s-maxage in the value. Hosts that append one of their own (thirty days is
		 * common) are harmless only because the plugin's comes first; a value without one hands
		 * every shared cache the host's lifetime.
		 *
		 * @param string $value     The header value.
		 * @param bool   $immutable Whether the document is a pinned version, which never changes.
		 * @param int    $status    The HTTP status.
		 */
		$value = (string) apply_filters(
			'soapbox_blyg_cache_control',
			sprintf( empty( $response['immutable'] ) ? 'public, max-age=%1$d, s-maxage=%1$d' : 'public, max-age=%1$d, s-maxage=%1$d, immutable', $lifetime ),
			! empty( $response['immutable'] ),
			$status
		);
		// Expires says what max-age says, for as long as the value allows storing at all.
		$seconds = Caching::has( $value, 'no-store' ) || Caching::has( $value, 'no-cache' ) || Caching::has( $value, 'private' ) ? null : Caching::first( $value, 'max-age' );
		return array(
			'Cache-Control' => $value,
			// No further ahead than a pin's lifetime, whatever a filter wrote: the date has to be one.
			'Expires'       => null === $seconds ? self::LONG_PAST : gmdate( 'D, d M Y H:i:s', time() + min( $seconds, Caching::PIN_LIFETIME ) ) . ' GMT',
		);
	}

	/**
	 * RFC 9110 §13.1: If-None-Match wins when present, compared weakly. If-Modified-Since applies
	 * only without it.
	 *
	 * @param string $etag     The response's ETag.
	 * @param int    $modified The response's last-modified time.
	 */
	private static function not_modified( string $etag, int $modified ): bool {
		// WordPress adds slashes to $_SERVER as it does to $_GET, and an ETag is quoted, so the header
		// arrives as \"...\" and never matches unless it is unslashed first.
		$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : null;
		if ( null !== $if_none_match ) {
			if ( '*' === trim( $if_none_match ) ) {
				return true;
			}
			foreach ( explode( ',', $if_none_match ) as $tag ) {
				if ( preg_replace( '#^W/#', '', trim( $tag ) ) === $etag ) {
					return true;
				}
			}
			return false;
		}
		$if_modified_since = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) : null;
		if ( null !== $if_modified_since && $modified > 0 ) {
			$since = strtotime( $if_modified_since );
			return false !== $since && $modified <= $since;
		}
		return false;
	}
}
