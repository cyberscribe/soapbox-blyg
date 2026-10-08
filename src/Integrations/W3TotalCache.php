<?php
/**
 * W3 Total Cache: flush changed addresses.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Asks W3 Total Cache to flush each changed address. w3tc_flush_url() is in its public API file
 * (w3-total-cache 2.10.7, w3-total-cache-api.php:324) and is defined on every request. What it
 * reaches depends on the modules switched on there: Varnish and a CDN serving the whole site
 * are the ones that can hold the blyg's documents. Its page cache does not store a response
 * marked DONOTCACHEPAGE (PgCache_ContentGrabber.php:227).
 */
class W3TotalCache {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		// Ahead of anyone else's listener, so that one of theirs failing cannot keep this from running.
		add_action( 'soapbox_blyg_purge_urls', array( __CLASS__, 'purge' ), 5, 2 );
	}

	/**
	 * Passes the addresses on. Whatever the other plugin does with them, it is not this
	 * request's failure.
	 *
	 * @param string[] $urls      Absolute URLs.
	 * @param string[] $essential Those of them a long list is cut to.
	 */
	public static function purge( $urls, $essential = array() ): void {
		if ( ! function_exists( 'w3tc_flush_url' ) ) {
			return;
		}
		try {
			// One flush for each address, so a long list is cut to what cannot wait.
			foreach ( Purge::bounded( $urls, $essential ) as $url ) {
				if ( '' !== $url ) {
					w3tc_flush_url( $url );
				}
			}
		} catch ( \Throwable $e ) {
			wp_trigger_error( __METHOD__, $e->getMessage() );
		}
	}
}
