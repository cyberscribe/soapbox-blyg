<?php
/**
 * Discovery.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg;

use SoapboxBlyg\Ledger\Ledger;
use SoapboxBlyg\Origins\PostTypeSource;
use SoapboxBlyg\Origins\Registry;
use SoapboxBlyg\Surface\Documents;
use SoapboxBlyg\Surface\Locations;

defined( 'ABSPATH' ) || exit;

/**
 * How a URL someone already shared resolves to the right blyg (spec §12), and how a page leads
 * back to its item's document (spec §5.8).
 *
 * These are the only additions the plugin makes to the site's own pages:
 *
 * - `rel="blyg"` on every page, naming the origin that claims it.
 * - On a published post's page: `rel="alternate" type="application/json"`, naming its item
 *   document, and, while the Webmention receiver is on, `rel="webmention"` with a matching Link
 *   header.
 * - On the main feed: the blyg namespace and a `blyg:manifest` element.
 */
class Discovery {

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
	 * Where documents live.
	 *
	 * @var Locations
	 */
	private $locations;

	/**
	 * Constructor.
	 *
	 * @param Registry  $registry  Origin registry.
	 * @param Ledger    $ledger    The ledger.
	 * @param Locations $locations Where documents live.
	 */
	public function __construct( Registry $registry, Ledger $ledger, Locations $locations ) {
		$this->registry  = $registry;
		$this->ledger    = $ledger;
		$this->locations = $locations;
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'head_links' ), 5 );
		add_action( 'template_redirect', array( $this, 'link_header' ) );
		add_action( 'rss2_ns', array( $this, 'feed_namespace' ) );
		add_action( 'rss2_head', array( $this, 'feed_manifest' ) );
	}

	/**
	 * The origin that claims the current page: the first, in priority order, whose discover
	 * callback says yes.
	 *
	 * @return \SoapboxBlyg\Origins\Origin|null
	 */
	private function claiming_origin() {
		$claimed = null;
		foreach ( $this->registry->all() as $origin ) {
			if ( $origin->discover && call_user_func( $origin->discover ) ) {
				$claimed = $origin;
				break;
			}
		}
		/**
		 * Filters which origin the current page advertises with rel="blyg".
		 *
		 * @param \SoapboxBlyg\Origins\Origin|null $origin   The origin that claimed the page, or null.
		 * @param \SoapboxBlyg\Origins\Origin[]    $origins  Every registered origin.
		 */
		$claimed = apply_filters( 'soapbox_blyg_discovery_origin', $claimed, $this->registry->all() );
		return $claimed instanceof Origins\Origin ? $claimed : null;
	}

	/**
	 * The item behind the page being viewed, when it is the page of a post that is on the blyg and
	 * live.
	 *
	 * @return array|null The item row.
	 */
	private function current_item() {
		if ( ! is_singular() || ! $this->registry->has( PostTypeSource::ORIGIN ) ) {
			return null;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! PostTypeSource::is_public( $post, false ) ) {
			return null;
		}
		$key  = 'post_item:' . $post->ID . ':' . Ledger::generation( PostTypeSource::ORIGIN );
		$item = wp_cache_get( $key, 'soapbox_blyg' );
		if ( false === $item ) {
			$item = $this->ledger->item_by_source( PostTypeSource::ORIGIN, PostTypeSource::key( $post->ID ) );
			wp_cache_set( $key, $item ? $item : 0, 'soapbox_blyg', HOUR_IN_SECONDS );
		}
		return $item ? $item : null;
	}

	/**
	 * Whether this plugin should advertise its Webmention endpoint on pages. When the IndieWeb
	 * Webmention plugin is active it owns the W3C endpoint on the site, and two endpoints on one
	 * page would leave a sender guessing; ours is then advertised in the manifest only, where
	 * Blygger clients look first (spec §15.2).
	 */
	public static function advertises_webmention_on_pages(): bool {
		if ( ! Documents::receives_mentions() ) {
			return false;
		}
		// Checked against the IndieWeb Webmention plugin 5.10: it defines the constant and the class.
		return ! ( defined( 'WEBMENTION_VERSION' ) || class_exists( '\Webmention\Receiver', false ) );
	}

	/**
	 * Prints the link elements.
	 */
	public function head_links(): void {
		$origin = $this->claiming_origin();
		if ( $origin ) {
			// The href is the origin itself, not the manifest; readers append blyg.json (spec §12.1).
			printf( '<link rel="blyg" href="%s" />' . "\n", esc_url( $origin->url() ) );
		}
		$item = $this->current_item();
		if ( ! $item ) {
			return;
		}
		$posts = $this->registry->get( PostTypeSource::ORIGIN );
		printf( '<link rel="alternate" type="application/json" href="%s" />' . "\n", esc_url( $this->locations->item( $posts, $item['blyg_id'] ) ) );
		if ( self::advertises_webmention_on_pages() ) {
			printf( '<link rel="webmention" href="%s" />' . "\n", esc_url( $this->locations->webmention( $posts ) ) );
		}
	}

	/**
	 * Sends the Link header that announces the Webmention endpoint, for senders that only make a
	 * HEAD request (W3C discovery).
	 */
	public function link_header(): void {
		if ( headers_sent() || ! self::advertises_webmention_on_pages() || ! $this->current_item() ) {
			return;
		}
		header( 'Link: <' . esc_url_raw( $this->locations->webmention( $this->registry->get( PostTypeSource::ORIGIN ) ) ) . '>; rel="webmention"', false );
	}

	/**
	 * Whether this is the site's main posts feed. rss2_ns and rss2_head also fire for category,
	 * tag, author and search feeds; those are other feeds, not this blyg's upgrade hook.
	 */
	private function is_main_feed(): bool {
		return is_feed( 'rss2' ) && ! is_archive() && ! is_search() && ! is_singular() && ! is_comment_feed()
			&& $this->registry->has( PostTypeSource::ORIGIN );
	}

	/**
	 * Declares the blyg namespace on the main feed.
	 */
	public function feed_namespace(): void {
		if ( $this->is_main_feed() ) {
			echo 'xmlns:blyg="' . esc_url( Documents::NS_BLYG ) . '"' . "\n\t";
		}
	}

	/**
	 * Adds the element that lets a plain feed URL resolve to the full blyg (spec §12.1 step 3).
	 */
	public function feed_manifest(): void {
		if ( $this->is_main_feed() ) {
			echo "\t<blyg:manifest>" . esc_url( $this->locations->manifest( $this->registry->get( PostTypeSource::ORIGIN ) ) ) . "</blyg:manifest>\n";
		}
	}
}
