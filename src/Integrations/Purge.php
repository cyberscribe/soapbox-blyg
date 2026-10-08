<?php
/**
 * Says which of the surface's addresses have changed.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Integrations;

use SoapboxBlyg\Ledger\Ledger;
use SoapboxBlyg\Origins\Registry;
use SoapboxBlyg\Settings;
use SoapboxBlyg\Surface\Caching;
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
 * Only addresses that were served and now answer differently are named. A new item's own
 * address was never served; the bare mount changes only with the settings. Some caches purge an
 * address and everything beneath it, and some purge one address per request to the cache, so a
 * list that is longer than it need be costs something real.
 */
class Purge {

	/**
	 * Above this many addresses a listener that purges one at a time, in the request, is given
	 * only what cannot wait. A backfill, or a bulk edit, changes hundreds of items; the manifest,
	 * the feed and the index are what a reader meets first. What was withdrawn cannot wait either,
	 * however much of it there is: its document and its page hold what was taken down, and a cache
	 * that is not told goes on serving them.
	 */
	const MANY = 50;

	/**
	 * The list is announced a second time a little later: a request already under way when the
	 * purge landed can put the old copy back into the cache after it. The wait is a fraction of
	 * the cache lifetime, and never less than this many seconds.
	 */
	const AGAIN_HOOK     = 'soapbox_blyg_purge_again';
	const AGAIN_AT_LEAST = 30;
	const AGAIN_FRACTION = 10;

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
	 * The ledger, for the page an item had.
	 *
	 * @var Ledger
	 */
	private $ledger;

	/**
	 * Addresses changed so far in this request, as keys.
	 *
	 * @var array<string,true>
	 */
	private $urls = array();

	/**
	 * Those of them that a long list is cut to: an origin's own documents (the manifest, the
	 * feed, the index), and the document and the page of anything withdrawn.
	 *
	 * @var array<string,true>
	 */
	private $essential = array();

	/**
	 * Whether every origin's documents changed, to be worked out when the request ends: what
	 * says so can run before the origins are registered.
	 *
	 * @var bool
	 */
	private $everything = false;

	/**
	 * Sets the collector up.
	 *
	 * @param Registry  $registry  The origins.
	 * @param Locations $locations Where each document is.
	 * @param Ledger    $ledger    The ledger.
	 */
	public function __construct( Registry $registry, Locations $locations, Ledger $ledger ) {
		$this->registry  = $registry;
		$this->locations = $locations;
		$this->ledger    = $ledger;
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'soapbox_blyg_event', array( $this, 'on_event' ), 10, 4 );
		add_action( 'soapbox_blyg_pinned', array( $this, 'on_pin' ), 10, 3 );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings' ), 10, 2 );
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'on_settings_added' ), 10, 2 );
		// What the documents carry that is not the plugin's: the site's name and tagline, where
		// the blyg has none of its own, and the addresses every link in them is built from.
		foreach ( array( 'blogname', 'blogdescription', 'home', 'permalink_structure' ) as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'everything_changed' ), 10, 0 );
		}
		add_action( 'shutdown', array( $this, 'flush' ) );
		add_action( self::AGAIN_HOOK, array( $this, 'announce' ), 10, 2 );
	}

	/**
	 * A ledger event. The documents that list items changed. So did the item's own, unless it is
	 * new. When an item goes or comes back its page changes too, and a withdrawal the plugin
	 * makes by itself (a reconcile, a reader's request) passes no post through WordPress for a
	 * cache plugin to notice.
	 *
	 * @param string $event   created, bumped, withdrawn or returned.
	 * @param string $id      Item id.
	 * @param int    $version The version it produced.
	 * @param string $origin  Origin key.
	 */
	public function on_event( $event, $id, $version, $origin ): void {
		if ( ! $this->registry->has( (string) $origin ) ) {
			return;
		}
		$origin = $this->registry->get( (string) $origin );
		$this->origin_changed( $origin );
		$changed = array();
		if ( 'created' !== $event ) {
			$changed[] = $this->locations->item( $origin, (string) $id );
		}
		if ( 'withdrawn' === $event || 'returned' === $event ) {
			// An endcap keeps the address of the page it replaced (spec §5.8).
			$row = $this->ledger->version_row( (string) $id, (int) $version );
			if ( $row && '' !== (string) $row['permalink'] ) {
				$changed[] = (string) $row['permalink'];
			}
		}
		foreach ( $changed as $url ) {
			$this->urls[ $url ] = true;
			if ( 'withdrawn' === $event ) {
				$this->essential[ $url ] = true;
			}
		}
	}

	/**
	 * A version was pinned: the item's document says so in its changelog. Nothing that lists
	 * the item does.
	 *
	 * @param string $id      Item id.
	 * @param int    $version The version pinned.
	 * @param string $origin  Origin key.
	 */
	public function on_pin( $id, $version, $origin ): void {
		if ( $this->registry->has( (string) $origin ) ) {
			$this->urls[ $this->locations->item( $this->registry->get( (string) $origin ), (string) $id ) ] = true;
		}
	}

	/**
	 * The settings were saved: the manifest carries the title and the author, and the feed and
	 * the index carry what the manifest names. The bare mount answers by them too. And if the
	 * save switched publishing on, or moved a mount that has nothing at it yet, what a cache may
	 * be holding at the new address is a "not found".
	 *
	 * @param mixed $old The settings before.
	 * @param mixed $now The settings as saved.
	 */
	public function on_settings( $old = null, $now = null ): void {
		foreach ( $this->registry->all() as $origin ) {
			$this->origin_changed( $origin );
			$this->urls[ $origin->url() ] = true;
		}
		if ( is_array( $now ) && ! empty( $now['enabled'] ) && isset( $now['mount'] ) && is_string( $now['mount'] ) ) {
			$mount = home_url( trailingslashit( '/' . trim( $now['mount'], '/' ) ) );
			foreach ( array( '', 'blyg.json', 'feed.xml', 'items/index.json' ) as $path ) {
				$this->urls[ $mount . $path ] = true;
			}
		}
	}

	/**
	 * The settings were saved for the first time.
	 *
	 * @param string $option The option's name.
	 * @param mixed  $now    The settings as saved.
	 */
	public function on_settings_added( $option = '', $now = null ): void {
		$this->on_settings( null, $now );
	}

	/**
	 * Something every document carries changed: the site's name, its address, the plugin's
	 * version (which the generator names). Worked out when the request ends.
	 */
	public function everything_changed(): void {
		$this->everything = true;
	}

	/**
	 * Announces what was collected, once, and forgets it. Then asks for it to be announced once
	 * more, a little later.
	 */
	public function flush(): void {
		if ( $this->everything ) {
			$this->everything = false;
			$this->on_settings();
		}
		if ( ! $this->urls ) {
			return;
		}
		$urls            = array_keys( $this->urls );
		$essential       = array_keys( $this->essential );
		$this->urls      = array();
		$this->essential = array();
		$this->announce( $urls, $essential );

		// Cron keeps an event's arguments in an option, so a long list is cut to what cannot wait.
		$again = count( $urls ) > self::MANY ? array( $essential, $essential ) : array( $urls, $essential );
		if ( $again[0] && ! wp_next_scheduled( self::AGAIN_HOOK, $again ) ) {
			wp_schedule_single_event( time() + max( self::AGAIN_AT_LEAST, (int) ( Caching::lifetime() / self::AGAIN_FRACTION ) ), self::AGAIN_HOOK, $again );
		}
	}

	/**
	 * Fires the action.
	 *
	 * @param string[] $urls      Absolute URLs, each once.
	 * @param string[] $essential Those of them a long list is cut to.
	 */
	public function announce( $urls, $essential = array() ): void {
		try {
			/**
			 * Fires at the end of a request in which documents of the blyg changed, with the
			 * addresses of those documents, and once more a little later. Hook a cache's own
			 * purge here.
			 *
			 * The plugin passes the list to the cache plugins it knows (see the readme). A
			 * listener must not purge more than it is given: this fires on every save of a post.
			 * One that purges an address at a time, in the request, should take $essential
			 * instead when the list is longer than Purge::MANY.
			 *
			 * @param string[] $urls      Absolute URLs, each once.
			 * @param string[] $essential Those of them that cannot wait: each origin's manifest,
			 *                            feed and index, and the document and the page of
			 *                            anything withdrawn. Never leave one of these out.
			 */
			do_action( 'soapbox_blyg_purge_urls', array_values( (array) $urls ), array_values( (array) $essential ) );
		} catch ( \Throwable $e ) {
			// A cache plugin's failure is not the request's: the post was saved and the ledger written.
			wp_trigger_error( __METHOD__, 'A listener on soapbox_blyg_purge_urls failed: ' . $e->getMessage() );
		}
	}

	/**
	 * The list for a listener that purges one address per call, in the request.
	 *
	 * @param mixed $urls      The list announced.
	 * @param mixed $essential What of it cannot wait.
	 * @return string[]
	 */
	public static function bounded( $urls, $essential ): array {
		$urls = array_values( array_filter( (array) $urls, 'is_string' ) );
		return count( $urls ) > self::MANY ? array_values( array_filter( (array) $essential, 'is_string' ) ) : $urls;
	}

	/**
	 * The documents every change to an origin touches: the manifest, the feed and the archive index.
	 *
	 * @param \SoapboxBlyg\Origins\Origin $origin The origin.
	 */
	private function origin_changed( $origin ): void {
		foreach ( array( $this->locations->manifest( $origin ), $this->locations->feed( $origin ), $this->locations->index( $origin ) ) as $url ) {
			$this->urls[ $url ]      = true;
			$this->essential[ $url ] = true;
		}
	}
}
