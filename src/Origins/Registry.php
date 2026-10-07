<?php
/**
 * The origin registry.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Origins;

use InvalidArgumentException;
use SoapboxBlyg\Ledger\Ledger;
use SoapboxBlyg\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Origins are registered on every request, on `init`, the way post types are. The code or setting
 * that defines an origin is the record of its identity, with one exception: its mount.
 *
 * Once an origin has published an item, its mount and layout are recorded in an option and that
 * record wins over whatever is registered later. Published URLs are promises, and a setting, a
 * filter or another plugin's update must not be able to move them. So the lock is kept with the
 * data rather than in the settings screen, where only one of the ways to change a mount passes.
 */
class Registry {

	/**
	 * Mount and layout of each origin that has published, keyed by origin: { path, layout, since }.
	 */
	const LOCKS_OPTION = 'soapbox_blyg_origin_locks';

	/**
	 * When each origin was first registered: its manifest's `updated` until it has items.
	 */
	const REGISTERED_OPTION = 'soapbox_blyg_registered';

	/**
	 * Top-level paths WordPress itself answers. An origin mounted on one would never be reached,
	 * or would take the path away from core.
	 */
	const RESERVED = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'rdf', 'rss', 'rss2', 'atom', 'comments', 'comment-page', 'search', 'author', 'category', 'tag', 'type', 'page', 'paged', 'attachment', 'embed', 'trackback', 'date', 'index.php', 'xmlrpc.php', 'robots.txt', 'favicon.ico', 'sitemap.xml', 'wp-sitemap.xml', '.well-known' );

	/**
	 * Registered origins by key.
	 *
	 * @var Origin[]
	 */
	private $origins = array();

	/**
	 * Origins whose registration disagreed with their lock: key => the path that was asked for.
	 *
	 * @var string[]
	 */
	private $conflicts = array();

	/**
	 * Registers an origin.
	 *
	 * @param string $key  Lowercase letters, digits, hyphen and underscore, at most 32.
	 * @param array  $args path (required, e.g. '/blyg/'), title, description, author (name, bio,
	 *                     links), layout ('templated' or 'fixed'), discover (callable), priority
	 *                     (int), redirect (URL or callable for the bare mount), source_state and
	 *                     stale_sources (callables; see Origin).
	 * @throws InvalidArgumentException For a bad key or path, or a mount another origin holds.
	 */
	public function register( string $key, array $args ): void {
		if ( ! preg_match( '/^[a-z0-9_-]{1,32}$/', $key ) ) {
			throw new InvalidArgumentException( sprintf( 'soapbox-blyg: bad origin key "%s"', esc_html( $key ) ) );
		}

		/**
		 * Filters an origin's arguments before they are validated.
		 *
		 * @param array  $args Origin arguments.
		 * @param string $key  Origin key.
		 */
		$args = (array) apply_filters( 'soapbox_blyg_origin_args', $args, $key );

		$path   = isset( $args['path'] ) ? (string) $args['path'] : '';
		$layout = isset( $args['layout'] ) ? (string) $args['layout'] : (string) Settings::get( 'layout' );

		$error = self::path_error( $path );
		if ( null !== $error ) {
			throw new InvalidArgumentException( sprintf( 'soapbox-blyg: origin "%s": %s', esc_html( $key ), esc_html( $error ) ) );
		}
		if ( ! in_array( $layout, Settings::LAYOUTS, true ) ) {
			$layout = (string) Settings::defaults()['layout'];
		}

		// The lock wins. What was asked for is kept so the admin can be told.
		$locks = $this->locks();
		if ( ! isset( $locks[ $key ] ) ) {
			$found = $this->lock_from_items( $key );
			if ( $found ) {
				$locks[ $key ] = $found;
				update_option( self::LOCKS_OPTION, $locks, true );
			}
		}
		if ( isset( $locks[ $key ] ) ) {
			if ( $locks[ $key ]['path'] !== $path || $locks[ $key ]['layout'] !== $layout ) {
				$this->conflicts[ $key ] = $path;
			}
			$path   = $locks[ $key ]['path'];
			$layout = $locks[ $key ]['layout'];
		}

		foreach ( $this->origins as $other ) {
			if ( $other->key !== $key && $other->path === $path ) {
				throw new InvalidArgumentException( sprintf( 'soapbox-blyg: origins "%1$s" and "%2$s" both claim %3$s', esc_html( $other->key ), esc_html( $key ), esc_html( $path ) ) );
			}
		}

		$origin              = new Origin();
		$origin->key         = $key;
		$origin->path        = $path;
		$origin->layout      = $layout;
		$origin->title       = isset( $args['title'] ) ? (string) $args['title'] : $key;
		$origin->description = isset( $args['description'] ) ? (string) $args['description'] : '';
		$origin->author      = isset( $args['author'] ) ? (array) $args['author'] : array();
		$origin->discover    = isset( $args['discover'] ) && is_callable( $args['discover'] ) ? $args['discover'] : null;
		$origin->priority    = isset( $args['priority'] ) ? (int) $args['priority'] : 10;
		$origin->redirect    = isset( $args['redirect'] ) ? $args['redirect'] : null;
		foreach ( array( 'source_state', 'stale_sources', 'deferred' ) as $ask ) {
			$origin->$ask = isset( $args[ $ask ] ) && is_callable( $args[ $ask ] ) ? $args[ $ask ] : null;
		}
		$origin->order = isset( $this->origins[ $key ] ) ? $this->origins[ $key ]->order : count( $this->origins );

		$this->origins[ $key ] = $origin;

		$seen = (array) get_option( self::REGISTERED_OPTION, array() );
		if ( ! isset( $seen[ $key ] ) ) {
			$seen[ $key ] = Ledger::now();
			update_option( self::REGISTERED_OPTION, $seen, true );
		}
	}

	/**
	 * Why a mount path cannot be used, or null if it can. This is the shape check only; whether
	 * something else on the site already answers at the path is Admin\SettingsPage's question.
	 *
	 * @param string $path Candidate mount.
	 * @return string|null
	 */
	public static function path_error( string $path ) {
		if ( ! preg_match( '#^/([a-z0-9._~-]+/)+$#i', $path ) ) {
			return 'the mount must look like /name/ or /name/other/';
		}
		$segments = explode( '/', strtolower( trim( $path, '/' ) ) );
		$first    = $segments[0];
		foreach ( $segments as $segment ) {
			if ( '' === trim( $segment, '.' ) ) {
				return 'the mount has a segment made only of dots';
			}
		}
		if ( in_array( $first, self::RESERVED, true ) || 0 === strpos( $first, 'wp-' ) || preg_match( '/^(wp-)?sitemap.*\.xml$/', $first ) ) {
			return 'the mount is on a path WordPress itself uses';
		}
		// A number in first place is how date archives and date-based permalinks begin (/2024/05/).
		if ( ctype_digit( $first ) ) {
			return 'the mount begins with a number, which WordPress reads as a date';
		}
		if ( rest_get_url_prefix() === $first ) {
			return 'the mount is on the REST API path';
		}
		return null;
	}

	/**
	 * Registered origins in discovery order: priority, then registration order.
	 *
	 * @return Origin[] Keyed by origin key.
	 */
	public function all(): array {
		$origins = $this->origins;
		uasort(
			$origins,
			static function ( Origin $a, Origin $b ) {
				return array( $a->priority, $a->order ) <=> array( $b->priority, $b->order );
			}
		);
		return $origins;
	}

	/**
	 * Whether an origin is registered.
	 *
	 * @param string $key Origin key.
	 */
	public function has( string $key ): bool {
		return isset( $this->origins[ $key ] );
	}

	/**
	 * One origin.
	 *
	 * @param string $key Origin key.
	 * @throws InvalidArgumentException When it is not registered.
	 */
	public function get( string $key ): Origin {
		if ( ! isset( $this->origins[ $key ] ) ) {
			throw new InvalidArgumentException( sprintf( 'soapbox-blyg: unknown origin "%s"', esc_html( $key ) ) );
		}
		return $this->origins[ $key ];
	}

	/**
	 * When an origin was first registered.
	 *
	 * @param string $key Origin key.
	 */
	public function registered_at( string $key ): string {
		$seen = (array) get_option( self::REGISTERED_OPTION, array() );
		return isset( $seen[ $key ] ) ? (string) $seen[ $key ] : Ledger::now();
	}

	/**
	 * The recorded locks.
	 *
	 * @return array[] key => { path, layout, since }
	 */
	public function locks(): array {
		$locks = get_option( self::LOCKS_OPTION, array() );
		return is_array( $locks ) ? $locks : array();
	}

	/**
	 * The lock an origin ought to have, read from its first item, or null if it has none.
	 *
	 * The lock is an entry in one option shared by every origin, written when an origin's first
	 * item is. That write can be lost: two origins publishing their first items at once each
	 * write the map as they read it, and a process can die between the item and the option. Each
	 * item therefore records where its origin was mounted when it was made, and a lock that is
	 * missing is put back from the oldest of them.
	 *
	 * @param string $key Origin key.
	 * @return array|null { path, layout, since }
	 */
	private function lock_from_items( string $key ) {
		global $wpdb;
		if ( false === get_option( \SoapboxBlyg\Ledger\Schema::VERSION_OPTION ) ) {
			return null; // No tables yet.
		}
		$cached = wp_cache_get( 'first-item:' . $key, 'soapbox_blyg' );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table; cached for the request below.
			$row    = $wpdb->get_row( $wpdb->prepare( 'SELECT mount, layout, created FROM %i WHERE origin_key = %s AND mount IS NOT NULL ORDER BY created ASC, blyg_id ASC LIMIT 1', \SoapboxBlyg\Ledger\Schema::table( 'items' ), $key ), ARRAY_A );
			$cached = $row ? $row : 'none';
			wp_cache_set( 'first-item:' . $key, $cached, 'soapbox_blyg', MINUTE_IN_SECONDS );
		}
		if ( ! is_array( $cached ) || ! in_array( $cached['layout'], Settings::LAYOUTS, true ) || null !== self::path_error( (string) $cached['mount'] ) ) {
			return null;
		}
		return array(
			'path'   => $cached['mount'],
			'layout' => $cached['layout'],
			'since'  => $cached['created'],
		);
	}

	/**
	 * An origin's lock, whether it is recorded or has to be read back from its first item.
	 *
	 * @param string $key Origin key.
	 * @return array|null { path, layout, since }
	 */
	public function lock_of( string $key ) {
		$locks = $this->locks();
		return isset( $locks[ $key ] ) ? $locks[ $key ] : $this->lock_from_items( $key );
	}

	/**
	 * Whether an origin's mount and layout are fixed for good.
	 *
	 * @param string $key Origin key.
	 */
	public function is_locked( string $key ): bool {
		return isset( $this->locks()[ $key ] );
	}

	/**
	 * Records an origin's mount and layout as permanent. Called when it publishes its first item.
	 * Does nothing if the lock is already there: a lock is never rewritten.
	 *
	 * @param string $key Origin key.
	 */
	public function lock( string $key ): void {
		$locks = $this->locks();
		if ( isset( $locks[ $key ] ) || ! isset( $this->origins[ $key ] ) ) {
			return;
		}
		$locks[ $key ] = array(
			'path'   => $this->origins[ $key ]->path,
			'layout' => $this->origins[ $key ]->layout,
			'since'  => Ledger::now(),
		);
		update_option( self::LOCKS_OPTION, $locks, true );
		wp_cache_delete( 'first-item:' . $key, 'soapbox_blyg' );
	}

	/**
	 * Origins that were registered with a mount or layout other than their lock.
	 *
	 * @return string[] key => the path that was asked for and overruled.
	 */
	public function conflicts(): array {
		return $this->conflicts;
	}
}
