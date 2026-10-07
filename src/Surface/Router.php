<?php
/**
 * Routing for the public surface.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Surface;

use SoapboxBlyg\Ledger\Id;
use SoapboxBlyg\Mentions\Receiver;
use SoapboxBlyg\Origins\Origin;
use SoapboxBlyg\Origins\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Routes each origin's mount through the Rewrite API: one rule per origin, covering everything
 * under the mount, answered on `parse_request` so the main query, the 404 guess and the canonical
 * redirect never run. They would only get the answer wrong.
 *
 * One catch-all rule rather than a rule per document, because the mount carries more than
 * documents: the manifest, the Webmention endpoint, a page for the bare mount, and a plain 404
 * for everything else. The layout then changes what is served under the mount, never the rules.
 *
 * The REST API is not used for the manifest: the protocol fixes that one filename at the origin
 * (spec §4, §16.6e), and {origin}blyg.json is what makes the origin's identity a path the site
 * owns. add_feed() is not used either: it serves /feed/{name}/, and the protocol's default feed
 * path is {origin}feed.xml.
 */
class Router {

	const VAR_ORIGIN = 'soapbox_blyg_origin';
	const VAR_PATH   = 'soapbox_blyg_path';

	/**
	 * A hash of the rules last flushed into WordPress's rewrite rules.
	 */
	const RULES_OPTION = 'soapbox_blyg_rules_hash';

	/**
	 * The registry.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * The documents.
	 *
	 * @var Documents
	 */
	private $documents;

	/**
	 * Pages served under a mount.
	 *
	 * @var ItemPage
	 */
	private $pages;

	/**
	 * The Webmention receiver.
	 *
	 * @var Receiver
	 */
	private $receiver;

	/**
	 * Constructor.
	 *
	 * @param Registry  $registry  Origin registry.
	 * @param Documents $documents The documents.
	 * @param ItemPage  $pages     Pages served under a mount.
	 * @param Receiver  $receiver  The Webmention receiver.
	 */
	public function __construct( Registry $registry, Documents $documents, ItemPage $pages, Receiver $receiver ) {
		$this->registry  = $registry;
		$this->documents = $documents;
		$this->pages     = $pages;
		$this->receiver  = $receiver;
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		// After origins are registered (init 1), and late enough that post types and other plugins'
		// rules exist when a flush is needed.
		add_action( 'init', array( $this, 'add_rules' ), 20 );
		add_action( 'init', array( $this, 'maybe_flush' ), 999 );
		add_action( 'parse_request', array( $this, 'serve' ), 0 );
		add_action( 'admin_init', array( $this, 'check_rules_present' ) );
	}

	/**
	 * Registers the two query variables the rules set.
	 *
	 * @param string[] $vars Public query variables.
	 * @return string[]
	 */
	public function query_vars( $vars ) {
		$vars[] = self::VAR_ORIGIN;
		$vars[] = self::VAR_PATH;
		return $vars;
	}

	/**
	 * The rules, as regex => query, longest mount first so a mount nested inside another
	 * (/ai/corpus/ under a later /ai/) is never shadowed by it.
	 *
	 * @return string[]
	 */
	public function rules(): array {
		$origins = $this->registry->all();
		uasort(
			$origins,
			static function ( Origin $a, Origin $b ) {
				return strlen( $b->path ) <=> strlen( $a->path );
			}
		);
		$rules = array();
		foreach ( $origins as $origin ) {
			$mount = trim( $origin->path, '/' );
			// The slash after the mount is optional so that /blyg, without it, reaches us as well.
			$rules[ '^' . preg_quote( $mount, '#' ) . '(?:/(.*))?$' ] = 'index.php?' . self::VAR_ORIGIN . '=' . $origin->key . '&' . self::VAR_PATH . '=$matches[1]';
		}
		return $rules;
	}

	/**
	 * Adds the rules on every request, so any flush by anyone regenerates them.
	 */
	public function add_rules(): void {
		foreach ( $this->rules() as $regex => $query ) {
			add_rewrite_rule( $regex, $query, 'top' );
		}
	}

	/**
	 * Flushes WordPress's rewrite rules when ours have changed since the last flush.
	 *
	 * One comparison covers every case that needs a flush: activation (where `init` has already
	 * fired before the plugin is loaded), network activation (each site on its next request), a
	 * mount change, an origin added in settings or registered by another plugin, and a plugin
	 * update. It costs one autoloaded option read when nothing changed.
	 */
	public function maybe_flush(): void {
		$hash = md5( (string) wp_json_encode( $this->rules() ) . '|' . SOAPBOX_BLYG_VERSION );
		if ( get_option( self::RULES_OPTION ) === $hash ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::RULES_OPTION, $hash, true );
	}

	/**
	 * If our rules are missing from the stored set (another plugin rebuilt the rules on a request
	 * where this one was not loaded), forget the hash so the next request flushes again.
	 */
	public function check_rules_present(): void {
		$stored = get_option( 'rewrite_rules' );
		if ( ! is_array( $stored ) ) {
			return; // Plain permalinks, or rules about to be rebuilt.
		}
		foreach ( array_keys( $this->rules() ) as $regex ) {
			if ( ! isset( $stored[ $regex ] ) ) {
				delete_option( self::RULES_OPTION );
				return;
			}
		}
	}

	/**
	 * Forgets the flush record and removes the rules. Called on deactivation: the stored rewrite
	 * rules are deleted, and core rebuilds them without ours on the next request.
	 */
	public static function forget(): void {
		delete_option( self::RULES_OPTION );
		delete_option( 'rewrite_rules' );
	}

	/**
	 * Answers a request under a mount, then exits.
	 *
	 * @param \WP $wp The request.
	 */
	public function serve( $wp ): void {
		if ( empty( $wp->query_vars[ self::VAR_ORIGIN ] ) ) {
			return;
		}
		// Only when one of our rules matched. The query variables are public, so without this check
		// /?soapbox_blyg_origin=posts would serve the surface on a query string, which the protocol
		// forbids (spec §4) and which caches key differently.
		if ( ! isset( $this->rules()[ (string) $wp->matched_rule ] ) ) {
			unset( $wp->query_vars[ self::VAR_ORIGIN ], $wp->query_vars[ self::VAR_PATH ] );
			return;
		}
		$key = (string) $wp->query_vars[ self::VAR_ORIGIN ];
		if ( ! $this->registry->has( $key ) ) {
			return;
		}
		$path = isset( $wp->query_vars[ self::VAR_PATH ] ) ? (string) $wp->query_vars[ self::VAR_PATH ] : '';

		// WordPress trims slashes before matching, so /blyg/blyg.json/ arrives looking like the
		// manifest. A file host would not serve it, so neither do we.
		$request_path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		if ( '' !== $path && '/' === substr( $request_path, -1 ) ) {
			$path .= '/';
		}

		Http::send( $this->respond( $this->registry->get( $key ), $path ) );
		exit;
	}

	/**
	 * The response for one path under an origin.
	 *
	 * @param Origin $origin The origin.
	 * @param string $path   The path below the mount, e.g. "blyg.json".
	 * @return array A response for Http::send().
	 */
	public function respond( Origin $origin, string $path ): array {
		$method = Http::method();

		if ( 'webmention' === $path && Documents::receives_mentions() ) {
			return $this->receiver->handle( $origin, $method );
		}
		if ( 'OPTIONS' === $method ) {
			return Http::preflight();
		}
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return Http::method_not_allowed( 'GET, HEAD, OPTIONS' );
		}

		if ( '' === $path ) {
			return $this->pages->bare_mount( $origin );
		}
		if ( 'blyg.json' === $path ) {
			list( $body, $modified ) = $this->documents->manifest( $origin );
			return Http::document( Http::JSON, $body, $modified );
		}

		// In a templated layout the documents live behind REST routes and the default paths answer
		// 404: one copy of each document, and none on the static-looking paths hosts edge-cache.
		if ( 'fixed' === $origin->layout ) {
			$document = $this->document( $origin, $path );
			if ( null !== $document ) {
				return $document;
			}
		}
		return Http::not_found();
	}

	/**
	 * A document at one of the protocol's default paths, or null.
	 *
	 * @param Origin $origin The origin.
	 * @param string $path   Path below the mount.
	 * @return array|null
	 */
	private function document( Origin $origin, string $path ) {
		if ( 'feed.xml' === $path ) {
			return self::served( Http::RSS, $this->documents->feed( $origin ) );
		}
		if ( 'items/index.json' === $path ) {
			return self::served( Http::JSON, $this->documents->index( $origin ) );
		}
		// The shape is checked before the database is touched, so a malformed id costs no query.
		if ( preg_match( '#^items/([^/]+)\.json$#', $path, $m ) ) {
			return $this->item( $origin, $m[1] );
		}
		if ( preg_match( '#^items/([^/]+)/v([^/]+)\.json$#', $path, $m ) ) {
			return $this->pinned( $origin, $m[1], $m[2] );
		}
		return null;
	}

	/**
	 * An item document response, or null. Shared with the REST routes.
	 *
	 * @param Origin $origin The origin.
	 * @param string $id     Item id as it appeared in the URL.
	 * @return array|null
	 */
	public function item( Origin $origin, string $id ) {
		if ( ! Id::is_valid( $id ) ) {
			return null;
		}
		$doc = $this->documents->item( $origin, $id );
		return $doc ? self::served( Http::JSON, $doc ) : null;
	}

	/**
	 * A response for a built document. One built with items held back (see Documents::enforce())
	 * is marked not to be stored by anything.
	 *
	 * @param string $type Content type.
	 * @param array  $doc  [ body, modified ] and, when items were held back, true.
	 * @return array A response for Http::send().
	 */
	public static function served( string $type, array $doc ): array {
		return Http::document( $type, $doc[0], $doc[1], empty( $doc[2] ) ? array() : array( 'cache' => false ) );
	}

	/**
	 * A pinned version response, or null. Shared with the REST routes.
	 *
	 * @param Origin $origin  The origin.
	 * @param string $id      Item id as it appeared in the URL.
	 * @param string $version Version as it appeared in the URL.
	 * @return array|null
	 */
	public function pinned( Origin $origin, string $id, string $version ) {
		if ( ! Id::is_valid( $id ) || ! preg_match( '/^[1-9][0-9]{0,8}$/', $version ) ) {
			return null;
		}
		$doc = $this->documents->pinned( $origin, $id, (int) $version );
		return $doc ? Http::document( Http::JSON, $doc[0], $doc[1], array( 'immutable' => true ) ) : null;
	}
}
