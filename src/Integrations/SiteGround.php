<?php
/**
 * SiteGround Speed Optimizer: purge changed addresses.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Asks SiteGround's Speed Optimizer to purge each changed address from the host's dynamic cache.
 * sg_cachepress_purge_cache() is that plugin's public function (sg-cachepress 7.8.4,
 * helpers/helpers.php:17, "Public function to purge cache").
 *
 * It purges an address and everything beneath it, and given the site's own address, or none, it
 * purges the whole site. So it is never given either: clearing a site's cache at every save of
 * a post would cost more than a few minutes' staleness.
 */
class SiteGround {

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
		if ( ! function_exists( 'sg_cachepress_purge_cache' ) ) {
			return;
		}
		try {
			foreach ( Purge::bounded( $urls, $essential ) as $url ) {
				if ( '' !== $url && untrailingslashit( $url ) !== untrailingslashit( home_url() ) ) {
					sg_cachepress_purge_cache( $url );
				}
			}
		} catch ( \Throwable $e ) {
			wp_trigger_error( __METHOD__, $e->getMessage() );
		}
	}
}
