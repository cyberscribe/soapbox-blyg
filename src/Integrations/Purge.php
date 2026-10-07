<?php
/**
 * Says which of the surface's addresses have changed.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Integrations;

use SoapboxBlyg\Origins\Registry;
use SoapboxBlyg\Settings;
use SoapboxBlyg\Surface\Locations;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the addresses whose documents a request has changed and announces them once, when
 * the request is over, so that a cache in front of the site can drop its copies.
 *
 * The lifetime the plugin states is a request, and some caches do not keep to it: a host's
 * Varnish may hold a document for as long as its own configuration says. A withdrawn item would
 * go on being served from there. What such a cache does honour is being told that an address has
 * changed, and the cache plugin installed beside it knows how to tell it. This class does not
 * know any of them. It fires one action; the classes beside it listen and pass the list on.
 *
 * Pinned versions never change, so pinning announces nothing.
 */
class Purge {

	/**
	 * The origins.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * Where each document is.
	 *
	 * @var Locations
	 */
	private $locations;

	/**
	 * Addresses changed so far in this request, as keys.
	 *
	 * @var array<string,true>
	 */
	private $urls = array();

	/**
	 * Sets the collector up.
	 *
	 * @param Registry  $registry  The origins.
	 * @param Locations $locations Where each document is.
	 */
	public function __construct( Registry $registry, Locations $locations ) {
		$this->registry  = $registry;
		$this->locations = $locations;
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'soapbox_blyg_event', array( $this, 'on_event' ), 10, 4 );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings' ) );
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'on_settings' ) );
		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	/**
	 * A ledger event: an item was created, changed, withdrawn or brought back. Its own document
	 * changed, and so did everything that lists it.
	 *
	 * @param string $event  The event.
	 * @param string $id     Item id.
	 * @param int    $version The version it produced.
	 * @param string $origin Origin key.
	 */
	public function on_event( $event, $id, $version, $origin ): void {
		if ( ! $this->registry->has( (string) $origin ) ) {
			return;
		}
		$origin = $this->registry->get( (string) $origin );
		$this->origin_changed( $origin );
		$this->urls[ $this->locations->item( $origin, (string) $id ) ] = true;
	}

	/**
	 * The settings were saved: the manifest carries the title and the author, and the feed and
	 * the index carry what the manifest names.
	 */
	public function on_settings(): void {
		foreach ( $this->registry->all() as $origin ) {
			$this->origin_changed( $origin );
		}
	}

	/**
	 * Announces what was collected, once, and forgets it.
	 */
	public function flush(): void {
		if ( ! $this->urls ) {
			return;
		}
		$urls       = array_keys( $this->urls );
		$this->urls = array();
		try {
			/**
			 * Fires at the end of a request in which documents of the blyg changed, with the
			 * addresses of those documents. Hook a cache's own purge here.
			 *
			 * The plugin passes the list to the cache plugins it knows (see the readme). A
			 * listener must not purge more than it is given: this fires on every save of a post.
			 *
			 * @param string[] $urls Absolute URLs, each once.
			 */
			do_action( 'soapbox_blyg_purge_urls', $urls );
		} catch ( \Throwable $e ) {
			// A cache plugin's failure is not the request's: the post was saved and the ledger written.
			wp_trigger_error( __METHOD__, 'A listener on soapbox_blyg_purge_urls failed: ' . $e->getMessage() );
		}
	}

	/**
	 * The documents every change to an origin touches: the manifest, the feed, the archive
	 * index, and the bare address of the mount.
	 *
	 * @param \SoapboxBlyg\Origins\Origin $origin The origin.
	 */
	private function origin_changed( $origin ): void {
		foreach ( array( $this->locations->manifest( $origin ), $this->locations->feed( $origin ), $this->locations->index( $origin ), $origin->url() ) as $url ) {
			$this->urls[ $url ] = true;
		}
	}
}
