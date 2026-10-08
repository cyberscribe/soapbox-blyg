<?php
/**
 * Human-readable pages the plugin itself serves.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Surface;

use SoapboxBlyg\Ledger\Ledger;
use SoapboxBlyg\Origins\Origin;
use SoapboxBlyg\Origins\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Two small pages, both for people rather than readers' software:
 *
 * - The withdrawn page. An item's `page` is promised to answer for as long as the item exists,
 *   which is forever, and a mention aimed at a withdrawn item needs somewhere to land (spec §5.8,
 *   §9). A trashed or deleted post's permalink is a 404, so where WordPress would answer 404 at a
 *   withdrawn item's last page, this answers 200 with a page saying it was withdrawn. It is the one
 *   URL outside the mounts whose response the plugin changes. If a later post takes the same
 *   address, WordPress no longer 404s there and that post wins.
 * - The origin page, at a bare mount that has nowhere better to send a person.
 *
 * Both render through templates a theme can override by providing a file of the same name.
 */
class ItemPage {

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
		// Core's canonical redirect runs at priority 10 and, on a 404, guesses a similar permalink
		// to redirect to. The guess is switched off for a withdrawn page, and the page is served
		// after core's own redirects have had their turn: if WordPress knows a better answer for
		// this URL (an old-slug redirect, a newer post), it has already given it.
		add_filter( 'do_redirect_guess_404_permalink', array( $this, 'keep_withdrawn_from_guess' ) );
		add_filter( 'old_slug_redirect_post_id', array( $this, 'keep_withdrawn_from_old_slug' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_withdrawn' ), 11 );
	}

	/**
	 * The URL being requested, without its query string.
	 */
	private static function requested_url(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = wp_parse_url( home_url( '/' ) );
		return $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $path;
	}

	/**
	 * The withdrawn item whose page is the URL being requested, if there is one.
	 *
	 * @return array|null { item, endcap, origin }
	 */
	private function withdrawn_here() {
		// Asked up to three times in a request, of the same address.
		static $found = array();
		$url          = self::requested_url();
		if ( array_key_exists( $url, $found ) ) {
			return $found[ $url ];
		}
		$found[ $url ] = null;
		$item          = $this->ledger->item_by_page( Locations::spellings( $url ) );
		if ( $item && $this->registry->has( $item['origin_key'] ) ) {
			$endcap = $this->ledger->version_row( $item['blyg_id'], (int) $item['current_version'] );
			if ( $endcap && 'withdrawn' === $endcap['kind'] ) {
				$found[ $url ] = array(
					'item'   => $item,
					'endcap' => $endcap,
					'origin' => $this->registry->get( $item['origin_key'] ),
				);
			}
		}
		return $found[ $url ];
	}

	/**
	 * Stops core redirecting a withdrawn item's page to the post that was withdrawn.
	 *
	 * WordPress keeps a post's earlier slugs and dates, and on a 404 redirects an address that
	 * carries one to the post's permalink (wp_old_slug_redirect()). It does not ask whether
	 * anyone can see the post: for a draft the permalink is /?p=N, and that is a 404. A redirect
	 * to a post that can be seen is left alone: it is a better answer than the withdrawn page.
	 *
	 * @param int $id The post core means to redirect to, or 0.
	 * @return int
	 */
	public function keep_withdrawn_from_old_slug( $id ) {
		return $id && is_404() && ! is_post_publicly_viewable( (int) $id ) && $this->withdrawn_here() ? 0 : $id;
	}

	/**
	 * Stops core redirecting a withdrawn item's page to some other post with a similar slug.
	 *
	 * @param bool $guess Whether core should guess.
	 * @return bool
	 */
	public function keep_withdrawn_from_guess( $guess ) {
		return is_404() && $this->withdrawn_here() ? false : $guess;
	}

	/**
	 * Serves the withdrawn page where WordPress would answer 404.
	 */
	public function maybe_serve_withdrawn(): void {
		if ( ! is_404() ) {
			return;
		}
		$here = $this->withdrawn_here();
		if ( ! $here ) {
			return;
		}
		$origin = $here['origin'];
		$links  = array();
		if ( Documents::receives_mentions() ) {
			// A withdrawn item keeps accepting mentions (spec §15.3): people may respond to a withdrawal.
			$links[] = '<' . $this->locations->webmention( $origin ) . '>; rel="webmention"';
		}
		$html = self::render(
			'soapbox-blyg-withdrawn.php',
			array(
				'origin'       => $origin,
				'item_url'     => $this->locations->item( $origin, $here['item']['blyg_id'] ),
				'withdrawn_at' => $here['endcap']['at'],
				'webmention'   => Documents::receives_mentions() ? $this->locations->webmention( $origin ) : '',
			)
		);
		Http::send(
			array(
				'status'   => 200,
				'type'     => Http::HTML,
				'body'     => $html,
				'modified' => (int) strtotime( $here['endcap']['at'] ),
				'link'     => $links,
			)
		);
		exit;
	}

	/**
	 * The response for a bare mount: a redirect to wherever the origin sends people, or a small
	 * page describing the blyg. The mount is the blyg's address, the thing people share and paste
	 * into a reader, so it should answer a person with something.
	 *
	 * @param Origin $origin The origin.
	 * @return array A response for Http::send().
	 */
	public function bare_mount( Origin $origin ): array {
		$target = $origin->redirect;
		if ( is_callable( $target ) ) {
			$target = call_user_func( $target );
		}
		if ( is_string( $target ) && '' !== $target ) {
			return array(
				'status'   => 302,
				'type'     => Http::TEXT,
				'body'     => '',
				'location' => esc_url_raw( $target ),
			);
		}
		$html = self::render(
			'soapbox-blyg-origin.php',
			array(
				'origin'   => $origin,
				'manifest' => $this->locations->manifest( $origin ),
				'feed'     => $this->locations->feed( $origin ),
			)
		);
		return array(
			'status' => 200,
			'type'   => Http::HTML,
			'body'   => $html,
		);
	}

	/**
	 * Renders a template to a string. A theme's copy of the file wins over the plugin's.
	 *
	 * @param string $file Template file name.
	 * @param array  $data Values the template reads from $data.
	 */
	private static function render( string $file, array $data ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $data is read by the included template.
		$template = locate_template( $file );
		if ( '' === $template ) {
			$template = dirname( SOAPBOX_BLYG_FILE ) . '/templates/' . $file;
		}
		// These pages are whole documents with no theme around them, so their few rules travel
		// with them: a style handle with no file, printed by the template.
		if ( ! wp_style_is( 'soapbox-blyg-page', 'registered' ) ) {
			wp_register_style( 'soapbox-blyg-page', false, array(), SOAPBOX_BLYG_VERSION );
			wp_add_inline_style( 'soapbox-blyg-page', 'body{font:1rem/1.6 system-ui,sans-serif;max-width:36rem;margin:4rem auto;padding:0 1.25rem;color:#1d2327}a{color:inherit}' );
		}
		ob_start();
		include $template;
		return (string) ob_get_clean();
	}
}
