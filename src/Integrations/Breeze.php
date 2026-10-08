<?php
/**
 * Breeze: purge Varnish, and name the page of a post that has been unpublished.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Integrations;

use SoapboxBlyg\Origins\PostTypeSource;
use SoapboxBlyg\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Asks Breeze to purge the changed addresses from Varnish, which on Cloudways keeps a document
 * for as long as its own configuration says, whatever lifetime the response states.
 *
 * The action is the one Breeze's own queued purges run on (breeze 2.6.1, inc/cache/purge-cache.php:
 * registered at line 1043, handled by Breeze_PurgeCache::purge_varnish_urls() at 395). It is
 * registered whether or not Breeze's page cache is on, and does nothing unless Breeze finds
 * Varnish. Fired like this it purges at once, one request to Varnish for each address: Breeze
 * queues a list only when it is called from outside its own action. It takes an array; a string
 * is read as the name of a transient that holds one.
 *
 * Breeze's own page cache never holds the blyg's documents: it does not store a response marked
 * DONOTCACHEPAGE (inc/cache/execute-cache.php:1792). It does hold the posts' pages, and with_page()
 * is about those.
 */
class Breeze {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		// Ahead of anyone else's listener, so that one of theirs failing cannot keep this from running.
		add_action( 'soapbox_blyg_purge_urls', array( __CLASS__, 'purge' ), 5, 2 );
		add_filter( 'breeze_collect_urls_for_cache_purge', array( __CLASS__, 'with_page' ), 10, 2 );
	}

	/**
	 * Adds the address a post was published at to the addresses Breeze is about to drop.
	 *
	 * When a post changes, Breeze clears its page from its own file cache, from Cloudflare and
	 * from Varnish by the addresses it collects once the change is made
	 * (collect_urls_for_cache_purge(), line 446). It starts from get_permalink(), and for a post
	 * that has just been unpublished that is the plain ?p= form: the page it cached is left where
	 * it was and goes on being served, in full. Breeze has no other way in to its file cache for
	 * one address, and the ledger has the address: an endcap keeps the page it replaced. The
	 * ledger is written after Breeze asks, so what it holds here is the page as it was before
	 * this save, which is the one a cache has.
	 *
	 * @param mixed $urls    Breeze's list.
	 * @param mixed $post_id The post it is collecting for.
	 * @return mixed
	 */
	public static function with_page( $urls, $post_id = 0 ) {
		if ( ! is_array( $urls ) || ! is_numeric( $post_id ) ) {
			return $urls;
		}
		try {
			$ledger = Plugin::instance()->ledger;
			$item   = $ledger->item_by_source( PostTypeSource::ORIGIN, PostTypeSource::key( (int) $post_id ) );
			$row    = $item ? $ledger->version_row( $item['blyg_id'], (int) $item['current_version'] ) : null;
			if ( $row && '' !== (string) $row['permalink'] && ! in_array( (string) $row['permalink'], $urls, true ) ) {
				$urls[] = (string) $row['permalink'];
			}
		} catch ( \Throwable $e ) {
			wp_trigger_error( __METHOD__, $e->getMessage() );
		}
		return $urls;
	}

	/**
	 * Passes the addresses on. Whatever the other plugin does with them, it is not this
	 * request's failure.
	 *
	 * @param string[] $urls      Absolute URLs.
	 * @param string[] $essential Those of them a long list is cut to.
	 */
	public static function purge( $urls, $essential = array() ): void {
		if ( ! has_action( 'breeze_purge_varnish_urls' ) ) {
			return;
		}
		try {
			$urls = array_values( array_filter( (array) $urls, 'is_string' ) );
			if ( count( $urls ) > Purge::MANY && function_exists( 'as_enqueue_async_action' ) ) {
				// A long list goes on the queue, as Breeze puts its own: the action is given the
				// name of a transient that holds the list (purge_varnish_urls(), lines 396-400 and 409-412).
				$key = 'breeze_purge_' . md5( wp_json_encode( $urls ) . microtime() );
				set_transient( $key, $urls, HOUR_IN_SECONDS );
				if ( 0 !== (int) as_enqueue_async_action( 'breeze_purge_varnish_urls', array( $key ), 'breeze' ) ) {
					return;
				}
				delete_transient( $key );
			}
			do_action( 'breeze_purge_varnish_urls', Purge::bounded( $urls, $essential ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Breeze's action.
		} catch ( \Throwable $e ) {
			wp_trigger_error( __METHOD__, $e->getMessage() );
		}
	}
}
