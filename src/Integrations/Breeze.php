<?php
/**
 * Breeze: purge Varnish.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Asks Breeze to purge the changed addresses from Varnish, which on Cloudways keeps a document
 * for as long as its own configuration says, whatever lifetime the response states.
 *
 * The action is the one Breeze's own queued purges run on (breeze 2.6.1, inc/cache/purge-cache.php:
 * registered at line 1043, handled by Breeze_PurgeCache::purge_varnish_urls() at 395). Fired from
 * here it does not queue: Breeze queues a list only when its handler is called from outside the
 * action, so these addresses are purged in this request, one request to Varnish for each. It is
 * registered whether or not Breeze's page cache is on, and does nothing unless Breeze finds
 * Varnish. It takes an array: a string would be read as the name of a transient. Breeze's own
 * page cache needs nothing, since it does not store a response marked DONOTCACHEPAGE
 * (inc/cache/execute-cache.php:1792).
 */
class Breeze {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'soapbox_blyg_purge_urls', array( __CLASS__, 'purge' ) );
	}

	/**
	 * Passes the addresses on. Whatever the other plugin does with them, it is not this
	 * request's failure.
	 *
	 * @param string[] $urls Absolute URLs.
	 */
	public static function purge( $urls ): void {
		if ( ! has_action( 'breeze_purge_varnish_urls' ) ) {
			return;
		}
		try {
			do_action( 'breeze_purge_varnish_urls', array_values( array_filter( (array) $urls, 'is_string' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Breeze's action.
		} catch ( \Throwable $e ) {
			wp_trigger_error( __METHOD__, $e->getMessage() );
		}
	}
}
