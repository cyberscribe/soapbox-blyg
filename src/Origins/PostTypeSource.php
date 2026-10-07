<?php
/**
 * The posts source.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Origins;

use SoapboxBlyg\Ledger\Ledger;
use SoapboxBlyg\Plugin;
use SoapboxBlyg\Render\ContentRenderer;
use SoapboxBlyg\Render\Markdown;
use SoapboxBlyg\Settings;
use Throwable;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Publishes WordPress content: every post of a type that supports `blyg` becomes a thread in the
 * `posts` origin.
 *
 * A source owns two decisions and no others: what is public, and what a public item looks like.
 * It never decides whether something is a new version. It hands the ledger a snapshot, or null for
 * "not public", on every save, and the ledger compares.
 */
class PostTypeSource {

	const ORIGIN = 'posts';

	/**
	 * Post type support flag. Any plugin can opt its own post type in with
	 * add_post_type_support( 'book', 'blyg' ), the way core handles comments and revisions.
	 */
	const SUPPORT = 'blyg';

	/**
	 * Set while the repair queue has work in it, so that a request can tell without a query.
	 */
	const REPAIRS_OPTION = 'soapbox_blyg_repairs_pending';

	/**
	 * Retries the repair queue.
	 */
	const REPAIR_HOOK = 'soapbox_blyg_repair';

	/**
	 * Runs a requested reconcile, in the background.
	 */
	const RECONCILE_HOOK = 'soapbox_blyg_reconcile_run';

	/**
	 * How many times a repair is tried before it is left for a person. The waits between tries
	 * double from a minute, so this is most of a day.
	 */
	const MAX_REPAIR_TRIES = 10;

	/**
	 * The longest wait between two tries of a repair, in minutes (the unit the waits double in).
	 */
	const REPAIR_WAIT_CAP_MINUTES = DAY_IN_SECONDS / MINUTE_IN_SECONDS;

	/**
	 * How long, in seconds, repairs may run inside an administrator's page load, as opposed to
	 * a cron run, which has longer.
	 */
	const REPAIR_SECONDS_INLINE = 2.0;

	/**
	 * How long the answer to "which live items are no longer public?" is kept, when answering
	 * means running the site's own filter over every post. Cleared by every save.
	 */
	const STALE_TRANSIENT = 'soapbox_blyg_stale_posts';

	/**
	 * A hash of the post types that have pages of their own, as last seen.
	 */
	const TYPES_OPTION = 'soapbox_blyg_viewable_types';

	/**
	 * On a network: when the plugin was last activated for the whole network, and the activation
	 * this site has already acted on.
	 */
	const NETWORK_ACTIVATED = 'soapbox_blyg_network_activated';
	const ACTIVATION_SEEN   = 'soapbox_blyg_activation_seen';

	/**
	 * Present while a reconcile is wanted or under way: the id of the last item checked, or ''.
	 */
	const RECONCILE_OPTION = 'soapbox_blyg_reconcile';

	/**
	 * The ledger.
	 *
	 * @var Ledger
	 */
	private $ledger;

	/**
	 * Constructor.
	 *
	 * @param Ledger $ledger The ledger.
	 */
	public function __construct( Ledger $ledger ) {
		$this->ledger = $ledger;
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_support' ), 0 );
		add_action( 'soapbox_blyg_register_origins', array( $this, 'register_origin' ) );
		add_action( 'wp_after_insert_post', array( $this, 'on_save' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'on_delete' ) );
		// After every plugin has registered its post types (a type that is not registered yet would
		// look like one that has gone), and before a request is routed.
		// What is owed is worked off in the background and when an administrator is about, never
		// on a visitor's request: a repair renders a post, and a reconcile can write many rows.
		// What keeps unpublished content from being served meanwhile is the check
		// Surface\Documents makes before it serves anything.
		// A filter that withholds a post usually reads its meta or its terms, which change without
		// a save of the post. The kept answer to "what is stale?" goes when they do.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $changed ) {
			add_action( $changed, array( __CLASS__, 'forget_stale' ), 10, 3 );
		}
		foreach ( array( 'set_object_terms', 'deleted_term_relationships', 'edited_term', 'added_term_meta', 'updated_term_meta', 'deleted_term_meta', 'clean_post_cache', 'soapbox_blyg_forget_stale' ) as $changed ) {
			add_action( $changed, array( __CLASS__, 'forget_stale' ), 10, 0 );
		}
		add_action( 'soapbox_blyg_event', array( $this, 'after_event' ), 10, 4 );
		add_action( 'wp_loaded', array( $this, 'notice_network_activation' ), 5 );
		add_action( 'wp_loaded', array( $this, 'watch_post_types' ), 20 );
		add_action( 'admin_init', array( $this, 'admin_work' ) );
		add_action( self::RECONCILE_HOOK, array( $this, 'maybe_reconcile' ) );
		add_action( self::REPAIR_HOOK, array( $this, 'repair' ) );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'on_settings_change' ), 10, 2 );
		// The first save of the settings adds the option rather than updating it.
		add_action(
			'add_option_' . Settings::OPTION,
			static function ( $option, $value ) {
				self::on_settings_change( array(), $value );
			},
			10,
			2
		);
	}

	/**
	 * Gives the post types chosen in settings the `blyg` support flag.
	 */
	public function add_support(): void {
		foreach ( (array) Settings::get( 'post_types' ) as $type ) {
			add_post_type_support( (string) $type, self::SUPPORT );
		}
	}

	/**
	 * Registers the posts origin with the site's identity and mount.
	 */
	public function register_origin(): void {
		$identity = Settings::identity();
		soapbox_blyg_register_origin(
			self::ORIGIN,
			array(
				'path'          => (string) Settings::get( 'mount' ),
				'layout'        => (string) Settings::get( 'layout' ),
				'title'         => $identity['title'],
				'description'   => $identity['description'],
				'author'        => $identity['author'],
				// This origin claims every page no other origin claims, so it is asked last.
				'discover'      => '__return_true',
				'priority'      => 1000,
				'redirect'      => static function () {
					$page = (int) get_option( 'page_for_posts' );
					return $page ? get_permalink( $page ) : home_url( '/' );
				},
				'source_state'  => array( __CLASS__, 'source_state' ),
				'stale_sources' => array( __CLASS__, 'stale_sources' ),
				'deferred'      => array( $this, 'deferred' ),
			)
		);
	}

	/**
	 * The ledger's key for a post. Post ids are unique across post types.
	 *
	 * @param int $post_id Post id.
	 */
	public static function key( int $post_id ): string {
		return 'post:' . $post_id;
	}

	/**
	 * Whether a post type publishes.
	 *
	 * @param string $post_type Post type name.
	 */
	public static function supports( string $post_type ): bool {
		return post_type_supports( $post_type, self::SUPPORT );
	}

	/**
	 * Whether a post is public: published, with no password, and not withheld by the site's
	 * filter. This is the one test of whether a post may be on the blyg. Everything that decides
	 * it asks here: a save, the ledger under its lock, a pin, and each document as it is served.
	 *
	 * Two further conditions are about the post's type and not about the post, and are asked only
	 * where they belong. Whether the type publishes ($require_support) decides whether a post
	 * joins the blyg or gets a new version, not whether one already there may stay. Whether the
	 * type has pages of its own ($type_level) can be false for a moment while the plugin that
	 * registers the type is switched off, so it is asked when a post is rendered and when a
	 * reconcile is called for, and never when a reader's request is deciding what to serve.
	 *
	 * @param WP_Post $post            The post.
	 * @param bool    $require_support Whether the post's type must currently publish.
	 * @param bool    $type_level      Whether the post's type must have pages of its own.
	 */
	public static function is_public( WP_Post $post, bool $require_support = true, bool $type_level = true ): bool {
		$public = ( ! $require_support || self::supports( $post->post_type ) )
			&& 'publish' === $post->post_status
			// Exactly empty: a password of spaces is a password.
			&& '' === (string) $post->post_password
			&& ( ! $type_level || is_post_type_viewable( $post->post_type ) );

		/**
		 * Filters whether a post is public, and so on the blyg.
		 *
		 * Returning false for a post that is on the blyg withdraws it: at its next save, or the
		 * next time anything of it would be served, whichever comes first.
		 *
		 * The filter is always run as a logged-out visitor, whoever is making the request. If it
		 * reads post meta, name the keys with `soapbox_blyg_fingerprint_meta`; if it reads
		 * anything that changes without the post being saved, fire `soapbox_blyg_forget_stale`
		 * when it changes.
		 *
		 * @param bool    $public Whether the post is public.
		 * @param WP_Post $post   The post.
		 */
		// The blyg is read by people who are not signed in, so the question is always whether the
		// post is public to them. Whoever happens to be making this request (an administrator,
		// a member, a cron job running as somebody) is set aside while the filter runs: a filter
		// that asks who the current user is must never be answered "an administrator".
		$asker = get_current_user_id();
		if ( $asker ) {
			wp_set_current_user( 0 );
		}
		try {
			return (bool) apply_filters( 'soapbox_blyg_is_public', $public, $post );
		} finally {
			if ( $asker ) {
				wp_set_current_user( $asker );
			}
		}
	}

	/**
	 * A post as the database holds it now, not as any cache remembers it. Another request may have
	 * saved it since this one began.
	 *
	 * @param int $post_id Post id.
	 * @return WP_Post|null
	 */
	public static function fresh( int $post_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- must not be answered from the object cache: the point is to see another request's write.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
		return $row ? new WP_Post( sanitize_post( $row, 'raw' ) ) : null;
	}

	/**
	 * A fingerprint of what a post's snapshot, and the decision to publish it, are made from: its
	 * own row, its terms, its author's name as shown, its featured image, and the meta keys that
	 * bear on either. Two states of a post that would publish differently have different
	 * fingerprints, whatever the clock said when they were saved.
	 *
	 * It is deliberately not all of the post's meta. WordPress itself adds meta to a published
	 * post and takes it away minutes later (`_pingme`, `_encloseme`), and plugins write meta
	 * while a post is being rendered (view counts, page-builder caches). Neither changes what
	 * is published, and counting them would make every post look changed since it was published.
	 *
	 * @param WP_Post $post The post, read afresh.
	 */
	public static function fingerprint( WP_Post $post ): string {
		global $wpdb;
		/**
		 * Filters the post meta keys that are part of a post's fingerprint.
		 *
		 * Add a key here if your `soapbox_blyg_is_public` or `soapbox_blyg_snapshot` filter
		 * reads it, so that a change to it is seen as a change to the post.
		 *
		 * @param string[] $keys Meta keys. `_thumbnail_id` is the plugin's own.
		 * @param WP_Post  $post The post.
		 */
		$keys = array_values( array_unique( array_filter( array_map( 'strval', (array) apply_filters( 'soapbox_blyg_fingerprint_meta', array( '_thumbnail_id' ), $post ) ) ) ) );
		sort( $keys );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- as fresh(): a cache may hold another request's past.
		$meta      = $keys ? $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND FIND_IN_SET( meta_key, %s ) ORDER BY meta_key ASC, meta_id ASC", $post->ID, implode( ',', $keys ) ), ARRAY_N ) : array();
		$terms     = $wpdb->get_col( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d ORDER BY term_taxonomy_id ASC", $post->ID ) );
		$author    = $wpdb->get_var( $wpdb->prepare( "SELECT display_name FROM {$wpdb->users} WHERE ID = %d", (int) $post->post_author ) );
		$thumbnail = 0;
		foreach ( (array) $meta as $pair ) {
			if ( '_thumbnail_id' === $pair[0] ) {
				$thumbnail = (int) $pair[1];
			}
		}
		// Of the featured image, what the figure is built from: its file, its sizes, its alt text.
		$image = $thumbnail ? $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_image_alt' ) ORDER BY meta_key ASC, meta_id ASC", $thumbnail ), ARRAY_N ) : array();
		// phpcs:enable
		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					(int) $post->ID,
					(string) $post->post_type,
					(string) $post->post_status,
					(string) $post->post_password,
					(string) $post->post_name,
					(string) $post->post_title,
					(string) $post->post_content,
					(string) $post->post_excerpt,
					(string) $post->post_author,
					(int) $post->post_parent,
					(string) $post->post_date_gmt,
					(string) $post->post_modified_gmt,
					$meta,
					$terms,
					(string) $author,
					$image,
				),
				JSON_PARTIAL_OUTPUT_ON_ERROR
			)
		);
	}

	/**
	 * What the database holds for a source key, for the ledger to ask under an item's lock.
	 *
	 * @param string $source_key Source key.
	 * @param bool   $thorough   Whether the post's type must also still have pages of its own.
	 * @return array|null { public: bool, fingerprint: string }, or null for a key that is not a post's.
	 */
	public static function source_state( string $source_key, bool $thorough = false ) {
		if ( ! preg_match( '/^post:(\d+)$/', $source_key, $m ) ) {
			return null;
		}
		$post = self::fresh( (int) $m[1] );
		if ( ! $post ) {
			return array(
				'public'      => false,
				'fingerprint' => '',
			);
		}
		return array(
			// Whether it may stay on the blyg, not whether its type still publishes. A type that is
			// not registered at all is unknown, not private: the plugin that registers it may be
			// switched off for a minute. Only a type that is there and has no pages counts.
			'public'      => self::is_public( $post, false, $thorough && post_type_exists( $post->post_type ) ),
			'fingerprint' => self::fingerprint( $post ),
		);
	}

	/**
	 * Source keys whose items are live in the ledger although their posts are not public: gone,
	 * unpublished, given a password, or withheld by the site's filter. It is is_public() asked
	 * of every one of them, and it is the check made before anything is served, so it reads the
	 * posts table itself and looks only at what is true of a post, not of its type.
	 *
	 * When nothing is hooked to the filter it cannot change an answer, and the whole question is
	 * one query. When something is, each post has to be loaded and the filter run, so the answer
	 * for the whole ledger is kept for a minute (see kept_stale()).
	 *
	 * @param string[]|null $source_keys Keys to check, or null for every live item.
	 * @return string[]|null The stale keys; null when the question could not be answered, which
	 *                       a caller must treat as "any of them might be".
	 */
	public static function stale_sources( $source_keys = null ) {
		global $wpdb;
		$only = null === $source_keys ? '' : implode( ',', array_filter( (array) $source_keys, static fn( $key ) => (bool) preg_match( '/^post:\d+$/', (string) $key ) ) );
		if ( null !== $source_keys && '' === $only ) {
			return array();
		}
		$items    = \SoapboxBlyg\Ledger\Schema::table( 'items' );
		$versions = \SoapboxBlyg\Ledger\Schema::table( 'versions' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the join to the ledger has no core API, and the answer must be current.
		if ( ! has_filter( 'soapbox_blyg_is_public' ) ) {
			$stale = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT i.source_key FROM %i i
					 JOIN %i v ON v.blyg_id = i.blyg_id AND v.version = i.current_version
					 LEFT JOIN {$wpdb->posts} p ON p.ID = CAST( SUBSTRING( i.source_key, 6 ) AS UNSIGNED )
					 WHERE i.origin_key = %s AND v.kind <> 'withdrawn' AND i.source_key LIKE %s
					   AND ( %s = '' OR FIND_IN_SET( i.source_key, %s ) )
					   AND ( p.ID IS NULL OR p.post_status <> 'publish' OR LENGTH( p.post_password ) > 0 )",
					$items,
					$versions,
					self::ORIGIN,
					'post:%',
					$only,
					$only
				)
			);
			// An empty answer and a failed query look the same from get_col(). They are not.
			return '' !== (string) $wpdb->last_error ? null : $stale;
		}

		if ( null === $source_keys ) {
			return self::kept_stale(
				static function () {
					return self::stale_sources_filtered( '' );
				}
			);
		}
		return self::stale_sources_filtered( $only );
		// phpcs:enable
	}

	/**
	 * Does stale_sources()'s work where the site's filter has to be run: each live post is loaded
	 * and asked.
	 *
	 * @param string $only Comma-separated keys to check, or '' for every live item.
	 * @return string[]|null
	 */
	private static function stale_sources_filtered( string $only ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- as stale_sources().
		// Only items that are live: one already withdrawn has nothing left to check.
		$live = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT i.source_key FROM %i i JOIN %i v ON v.blyg_id = i.blyg_id AND v.version = i.current_version
				 WHERE i.origin_key = %s AND v.kind <> 'withdrawn' AND i.source_key LIKE %s AND ( %s = '' OR FIND_IN_SET( i.source_key, %s ) )",
				\SoapboxBlyg\Ledger\Schema::table( 'items' ),
				\SoapboxBlyg\Ledger\Schema::table( 'versions' ),
				self::ORIGIN,
				'post:%',
				$only,
				$only
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return null;
		}
		$stale = array();
		foreach ( array_chunk( $live, 200 ) as $chunk ) {
			$ids = array_map( static fn( $key ) => (int) substr( $key, 5 ), $chunk );
			// Read by primary key, through core, after dropping what the post cache holds for
			// them: a cached row may be another request's past.
			wp_cache_delete_multiple( $ids, 'posts' );
			_prime_post_caches( $ids, false, false );
			if ( '' !== (string) $wpdb->last_error ) {
				return null;
			}
			foreach ( $chunk as $key ) {
				$post = get_post( (int) substr( $key, 5 ) );
				if ( ! $post || ! self::is_public( $post, false, false ) ) {
					$stale[] = $key;
				}
			}
		}
		// phpcs:enable
		return $stale;
	}

	/**
	 * The kept answer to "which live items are stale?" for the whole ledger, where finding out
	 * means running the site's filter over every post.
	 *
	 * It is kept for a minute. So a change the plugin cannot see (to an option, to a user, to
	 * anything a filter reads that is not the post, its meta or its terms) reaches the feed and
	 * the index up to a minute late; an item document is always asked afresh. Changes it can see
	 * mark the kept answer old at once (forget_stale()).
	 *
	 * One request at a time works the answer out again. While it does, others are given the old
	 * one, rather than each loading every post.
	 *
	 * @param callable $compute Works the answer out: returns string[] or null.
	 * @return string[]|null
	 */
	private static function kept_stale( callable $compute ) {
		global $wpdb;
		$kept  = get_transient( self::STALE_TRANSIENT );
		$fresh = is_array( $kept ) && isset( $kept['at'], $kept['keys'] ) && time() - (int) $kept['at'] < MINUTE_IN_SECONDS;
		if ( $fresh ) {
			return (array) $kept['keys'];
		}
		$mutex = 'soapbox_blyg:stale:' . md5( $wpdb->prefix );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a named lock, not waited for.
		$mine = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $mutex ) );
		if ( ! $mine && is_array( $kept ) && isset( $kept['keys'] ) ) {
			return (array) $kept['keys']; // Somebody is on it: the old answer, for now.
		}
		try {
			$keys = $compute();
			if ( null !== $keys ) {
				set_transient(
					self::STALE_TRANSIENT,
					array(
						'at'   => time(),
						'keys' => $keys,
					),
					10 * MINUTE_IN_SECONDS
				);
			}
			return $keys;
		} finally {
			if ( $mine ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- releasing the lock taken above.
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $mutex ) );
			}
		}
	}

	/**
	 * Marks the kept answer of stale_sources() old, so the next request works it out again.
	 *
	 * Hooked to the changes a filter is most likely to read: a post's meta and terms. It is given
	 * the object the change was to, and ignores what cannot matter: meta on something that is not
	 * a post of a type that publishes, and the housekeeping keys core and editors write all day.
	 * Fire the `soapbox_blyg_forget_stale` action to call it for anything else.
	 *
	 * @param mixed $first   A meta id, or an object id, depending on the hook. Unused.
	 * @param mixed $post_id The object id, for the meta hooks.
	 * @param mixed $key     The meta key, for the meta hooks.
	 */
	public static function forget_stale( $first = null, $post_id = null, $key = null ): void {
		unset( $first );
		if ( is_string( $key ) && in_array( $key, array( '_edit_lock', '_edit_last', '_pingme', '_encloseme', '_wp_old_slug', '_wp_trash_meta_status', '_wp_trash_meta_time' ), true ) ) {
			return;
		}
		if ( is_numeric( $post_id ) && is_string( $key ) && doing_action() && false !== strpos( (string) current_action(), '_post_meta' ) && ! self::supports( (string) get_post_type( (int) $post_id ) ) ) {
			return;
		}
		$kept = get_transient( self::STALE_TRANSIENT );
		if ( is_array( $kept ) && isset( $kept['keys'] ) ) {
			// Kept, and marked old: whoever works it out again can hand this to those who ask meanwhile.
			set_transient(
				self::STALE_TRANSIENT,
				array(
					'at'   => 0,
					'keys' => $kept['keys'],
				),
				10 * MINUTE_IN_SECONDS
			);
		}
	}

	/**
	 * Keeps the kept answer and the repair queue in step with what the ledger has just done: a
	 * key that was withdrawn is no longer stale, and nothing is owed for it.
	 *
	 * @param string $event   The ledger event.
	 * @param string $id      Item id.
	 * @param int    $version The version the event produced. Unused.
	 * @param string $origin  Origin key.
	 */
	public function after_event( $event, $id, $version, $origin ): void {
		unset( $version );
		if ( self::ORIGIN !== $origin || 'withdrawn' !== $event ) {
			return;
		}
		$item = $this->ledger->item_by_id( (string) $id );
		if ( ! $item || ! preg_match( '/^post:(\d+)$/', $item['source_key'], $m ) ) {
			return;
		}
		$kept = get_transient( self::STALE_TRANSIENT );
		if ( is_array( $kept ) && isset( $kept['keys'] ) && in_array( $item['source_key'], (array) $kept['keys'], true ) ) {
			$kept['keys'] = array_values( array_diff( (array) $kept['keys'], array( $item['source_key'] ) ) );
			set_transient( self::STALE_TRANSIENT, $kept, 10 * MINUTE_IN_SECONDS );
		}
		$this->settled( (int) $m[1] );
	}

	/**
	 * Called when a stale item could not be withdrawn as it was about to be served. A write that
	 * failed is owed; one that was simply too many for a single request is left to a reconcile.
	 *
	 * @param string      $source_key Source key.
	 * @param string|null $failure    Why the write failed, or null for "too many".
	 */
	public function deferred( string $source_key, $failure ): void {
		if ( null === $failure ) {
			if ( false === get_option( self::RECONCILE_OPTION ) ) {
				self::request_reconcile();
			}
			return;
		}
		// Recorded, but not as another try: how often readers ask says nothing about how often
		// the repair has been attempted.
		if ( preg_match( '/^post:(\d+)$/', $source_key, $m ) ) {
			$this->owe( (int) $m[1], $failure, false );
		}
	}

	/**
	 * Runs after terms and meta are saved, for every save path: both editors, WP-CLI, a scheduled
	 * post going live, and trashing and untrashing, which both go through wp_update_post().
	 *
	 * A post of a type that no longer publishes is still watched if it is already on the blyg:
	 * it gets no new versions, but if it stops being public its item is withdrawn. Unpublishing
	 * something has to work whatever has happened to the settings since it was published.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    The post.
	 */
	public function on_save( $post_id, $post ): void {
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		do_action( 'soapbox_blyg_forget_stale' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the plugin's own prefix; documented where forget_stale() is.
		$this->guard(
			function () use ( $post ) {
				return $this->sync( (int) $post->ID );
			},
			(int) $post->ID
		);
	}

	/**
	 * Brings the ledger into step with one post, as the database holds it now: publishes it if its
	 * type publishes, and otherwise withdraws it if it has stopped being public.
	 *
	 * @param int   $post_id Post id.
	 * @param array $opts    Passed to the ledger (the backfill sets at and note).
	 * @return array|null The ledger's result, or null when there was nothing to do.
	 */
	public function sync( int $post_id, array $opts = array() ) {
		$post = self::fresh( $post_id );
		if ( $post && self::supports( $post->post_type ) ) {
			// The fingerprint is taken before the render and checked again inside the ledger's
			// lock: if the post has been saved again by then, this snapshot is not written.
			$opts += array(
				// created is the date the post first went out, however long before the blyg existed.
				'created'     => self::gmt_iso( $post->post_date_gmt, $post->post_date ),
				'fingerprint' => self::fingerprint( $post ),
			);
			return $this->ledger->publish( self::ORIGIN, self::key( $post_id ), $this->snapshot( $post ), $opts );
		}
		if ( ! $this->ledger->item_by_source( self::ORIGIN, self::key( $post_id ) ) ) {
			return null; // Never on the blyg, whatever its type.
		}
		return $this->ledger->withdraw_if_stale( self::ORIGIN, self::key( $post_id ) );
	}

	/**
	 * A post deleted outright, without passing through the trash, is withdrawn first. Its item row
	 * outlives it: the item document answers 200 forever (spec §4). The post is still there when
	 * this runs, so the withdrawal does not ask whether it is public.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_delete( $post_id ): void {
		$post_id = (int) $post_id;
		$this->guard(
			function () use ( $post_id ) {
				if ( ! $this->ledger->item_by_source( self::ORIGIN, self::key( $post_id ) ) ) {
					return null;
				}
				return $this->ledger->publish( self::ORIGIN, self::key( $post_id ), null );
			},
			$post_id
		);
	}

	/**
	 * Withdraws every live item whose post has gone or is no longer public. A save withdraws its
	 * own post, so this is for unpublishing that happened where no save was seen: while publishing
	 * was switched off for the site, or while the plugin was inactive.
	 *
	 * It re-renders nothing and never creates or updates a version; it only withdraws. It works
	 * through the ledger in batches and stops when its time is up, leaving a marker so that the
	 * next request carries on from where it stopped.
	 *
	 * @param float $seconds How long this call may run.
	 * @return bool Whether the whole ledger has now been checked.
	 */
	public function withdraw_missed( float $seconds = 5.0 ): bool {
		if ( ! Plugin::instance()->registry->has( self::ORIGIN ) ) {
			return false;
		}
		$started = microtime( true );
		$after   = (string) get_option( self::RECONCILE_OPTION, '' );
		do {
			$batch = $this->ledger->live_items_after( self::ORIGIN, $after, 200 );
			$ids   = array();
			foreach ( $batch as $row ) {
				if ( preg_match( '/^post:(\d+)$/', $row['source_key'], $m ) ) {
					$ids[] = (int) $m[1];
				}
			}
			_prime_post_caches( $ids, false, false );
			foreach ( $batch as $row ) {
				$after = $row['blyg_id'];
				if ( ! preg_match( '/^post:(\d+)$/', $row['source_key'], $m ) ) {
					continue;
				}
				$post = get_post( (int) $m[1] );
				if ( ! $post || ! self::is_public( $post, false, post_type_exists( $post->post_type ) ) ) {
					// Decided again under the item's lock, from the database. A reconcile is asked
					// for on purpose, so it also counts a type that no longer has pages.
					$this->ledger->withdraw_if_stale( self::ORIGIN, $row['source_key'], true );
				}
			}
			if ( count( $batch ) < 200 ) {
				delete_option( self::RECONCILE_OPTION );
				/**
				 * Fires when a reconcile of the posts origin has checked every live item.
				 */
				do_action( 'soapbox_blyg_reconciled' );
				return true;
			}
			update_option( self::RECONCILE_OPTION, $after, true );
		} while ( microtime( true ) - $started < $seconds );
		return false;
	}

	/**
	 * Asks for withdraw_missed() to run. Called when publishing is switched on for a site, when
	 * the post types that publish change, and on activation.
	 */
	public static function request_reconcile(): void {
		update_option( self::RECONCILE_OPTION, '', true );
		if ( ! wp_next_scheduled( self::RECONCILE_HOOK ) ) {
			wp_schedule_single_event( time(), self::RECONCILE_HOOK );
		}
	}

	/**
	 * On a network, a site learns on its own next request that the plugin was activated for the
	 * whole network. If it has a ledger, posts may have been unpublished while the plugin was
	 * off, so it asks for a reconcile. This is one option read on a request, and one write, once.
	 */
	public function notice_network_activation(): void {
		if ( ! is_multisite() ) {
			return;
		}
		$activated = (string) get_site_option( self::NETWORK_ACTIVATED, '' );
		if ( '' !== $activated && get_option( self::ACTIVATION_SEEN ) !== $activated ) {
			update_option( self::ACTIVATION_SEEN, $activated, true );
			// Activating is asking for a schema that could not be installed to be tried again.
			update_option( \SoapboxBlyg\Ledger\Schema::PROBLEM_OPTION, '', true );
			if ( false !== get_option( \SoapboxBlyg\Ledger\Schema::VERSION_OPTION ) ) {
				self::request_reconcile();
			}
		}
	}

	/**
	 * Runs maybe_reconcile() for an administrator's own page loads. `admin_init` also fires for
	 * admin-ajax.php and admin-post.php, which anybody can call, so it is not enough to be on it:
	 * the request must be a person who manages the plugin looking at a screen. Anything that goes
	 * wrong is left for cron, and never becomes that person's error page.
	 */
	public function admin_work(): void {
		if ( wp_doing_ajax() || ! current_user_can( \SoapboxBlyg\Admin\Capabilities::MANAGE ) ) {
			return;
		}
		try {
			$this->maybe_reconcile();
		} catch ( Throwable $e ) {
			if ( ! wp_next_scheduled( self::RECONCILE_HOOK ) ) {
				wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::RECONCILE_HOOK );
			}
		}
	}

	/**
	 * Asks for a reconcile when the set of post types that have pages changes. A reader's request
	 * never withdraws a post because of its type (the type may only be missing for a moment); so
	 * when a type really has stopped having pages, something has to notice. This does: one
	 * autoloaded option read on a request, and a write when the set changes.
	 */
	public function watch_post_types(): void {
		if ( ! Plugin::instance()->registry->has( self::ORIGIN ) ) {
			return;
		}
		$viewable = array();
		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			if ( is_post_type_viewable( $type ) ) {
				$viewable[] = $type->name;
			}
		}
		sort( $viewable );
		$now = md5( implode( ',', $viewable ) );
		if ( get_option( self::TYPES_OPTION ) !== $now ) {
			$first = false === get_option( self::TYPES_OPTION );
			update_option( self::TYPES_OPTION, $now, true );
			if ( ! $first ) {
				self::request_reconcile();
			}
		}
	}

	/**
	 * Works off what is owed: a requested reconcile, and the repair queue. Runs from cron and
	 * when an administrator loads an admin screen. Neither is relied on to finish in one go.
	 */
	public function maybe_reconcile(): void {
		// A site that is not publishing has nothing to reconcile, and asking again every minute
		// would never change that. Switching publishing on asks afresh.
		if ( ! Plugin::instance()->registry->has( self::ORIGIN ) ) {
			delete_option( self::RECONCILE_OPTION );
			return;
		}
		if ( false !== get_option( self::RECONCILE_OPTION ) ) {
			if ( ! $this->withdraw_missed() && ! wp_next_scheduled( self::RECONCILE_HOOK ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::RECONCILE_HOOK );
			}
		}
		if ( get_option( self::REPAIRS_OPTION ) ) {
			$this->repair( self::REPAIR_SECONDS_INLINE );
		}
	}

	/**
	 * Records that a post's publish or withdrawal failed, as work that is owed. One row per post:
	 * a second failure counts as another try, and puts the next one further off.
	 *
	 * If the queue itself cannot be written, the failure must not be lost with it. A reconcile
	 * of the whole ledger is asked for instead: it will find the post, whichever it was.
	 *
	 * @param int    $post_id Post id.
	 * @param string $message Why it failed.
	 * @param bool   $tried   Whether this was a try of the repair (a save, or the queue), which
	 *                        counts and puts the next one further off; or only a reader's request
	 *                        finding it still owed, which does neither.
	 */
	private function owe( int $post_id, string $message, bool $tried = true ): void {
		global $wpdb;
		if ( ! $tried ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table; one atomic statement.
			$written = $wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i ( origin_key, source_key, at, tries, next_try, message ) VALUES ( %s, %s, %s, 1, %s, %s ) ON DUPLICATE KEY UPDATE message = VALUES( message )',
					\SoapboxBlyg\Ledger\Schema::table( 'repairs' ),
					self::ORIGIN,
					self::key( $post_id ),
					Ledger::now(),
					gmdate( 'Y-m-d\TH:i:s\Z', time() + MINUTE_IN_SECONDS ),
					$message
				)
			);
			if ( false === $written ) {
				self::request_reconcile();
				return;
			}
			update_option( self::REPAIRS_OPTION, 1, true );
			if ( ! wp_next_scheduled( self::REPAIR_HOOK ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::REPAIR_HOOK );
			}
			return;
		}
		// The waits double: one minute, two, four... capped at a day.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table; one atomic statement.
		$written = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i ( origin_key, source_key, at, tries, next_try, message ) VALUES ( %s, %s, %s, 1, %s, %s )
				 ON DUPLICATE KEY UPDATE tries = tries + 1, at = VALUES( at ), message = VALUES( message ),
				   next_try = DATE_FORMAT( DATE_ADD( UTC_TIMESTAMP(), INTERVAL LEAST( %d, POW( 2, tries - 1 ) ) MINUTE ), %s )',
				\SoapboxBlyg\Ledger\Schema::table( 'repairs' ),
				self::ORIGIN,
				self::key( $post_id ),
				Ledger::now(),
				gmdate( 'Y-m-d\TH:i:s\Z', time() + MINUTE_IN_SECONDS ),
				$message,
				self::REPAIR_WAIT_CAP_MINUTES,
				'%Y-%m-%dT%H:%i:%sZ'
			)
		);
		if ( false === $written ) {
			self::request_reconcile();
			return;
		}
		update_option( self::REPAIRS_OPTION, 1, true );
		if ( ! wp_next_scheduled( self::REPAIR_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::REPAIR_HOOK );
		}
	}

	/**
	 * The repair queue, oldest first.
	 *
	 * @param int  $limit How many rows.
	 * @param bool $due   Only those whose next try has come and that have tries left.
	 * @return array[] { post: int, at, tries, next_try, message }
	 */
	public function owed( int $limit = 50, bool $due = false ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table, and it must be current.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source_key, at, tries, next_try, message FROM %i WHERE origin_key = %s AND ( %d = 0 OR ( next_try <= %s AND tries < %d ) ) ORDER BY at ASC LIMIT %d',
				\SoapboxBlyg\Ledger\Schema::table( 'repairs' ),
				self::ORIGIN,
				$due ? 1 : 0,
				Ledger::now(),
				self::MAX_REPAIR_TRIES,
				$limit
			),
			ARRAY_A
		);
		return array_map(
			static function ( $row ) {
				return array(
					'post'     => (int) substr( $row['source_key'], 5 ),
					'at'       => $row['at'],
					'tries'    => (int) $row['tries'],
					'next_try' => $row['next_try'],
					'message'  => $row['message'],
				);
			},
			$rows
		);
	}

	/**
	 * Takes a post off the repair queue: the ledger agrees with it again.
	 *
	 * @param int $post_id Post id.
	 */
	private function settled( int $post_id ): void {
		global $wpdb;
		if ( ! get_option( self::REPAIRS_OPTION ) ) {
			return;
		}
		$table = \SoapboxBlyg\Ledger\Schema::table( 'repairs' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE origin_key = %s AND source_key = %s', $table, self::ORIGIN, self::key( $post_id ) ) );
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $table ) ) ) {
			delete_option( self::REPAIRS_OPTION );
		}
		// phpcs:enable
	}

	/**
	 * Works through the repairs that are due: each post is brought into step with the ledger
	 * again, as a save of it would. One worker at a time. A post that fails again waits twice as
	 * long for its next try, and after MAX_REPAIR_TRIES it is left on the queue, where Site
	 * Health and Tools show it, for a person: saving the post tries again.
	 *
	 * @param float $seconds How long this call may run.
	 */
	public function repair( $seconds = 10.0 ): void {
		global $wpdb;
		if ( ! Plugin::instance()->registry->has( self::ORIGIN ) ) {
			return;
		}
		$mutex = 'soapbox_blyg:repair:' . md5( $wpdb->prefix );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a named lock, not waited for: a second worker has nothing to do.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $mutex ) ) ) {
			return;
		}
		try {
			$started = microtime( true );
			foreach ( $this->owed( 50, true ) as $row ) {
				try {
					$this->sync( $row['post'] );
					$this->settled( $row['post'] );
				} catch ( Throwable $e ) {
					$this->owe( $row['post'], $e->getMessage() );
				}
				if ( microtime( true ) - $started >= (float) $seconds ) {
					break;
				}
			}
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- releasing the lock taken above.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $mutex ) );
		}
		if ( get_option( self::REPAIRS_OPTION ) && ! wp_next_scheduled( self::REPAIR_HOOK ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::REPAIR_HOOK );
		}
	}

	/**
	 * Watches the settings for the two changes that call for a reconcile.
	 *
	 * @param mixed $before The settings before.
	 * @param mixed $after  The settings after.
	 */
	public static function on_settings_change( $before, $after ): void {
		$before = array_merge( Settings::defaults(), is_array( $before ) ? $before : array() );
		$after  = array_merge( Settings::defaults(), is_array( $after ) ? $after : array() );
		if ( ( ! $before['enabled'] && $after['enabled'] ) || $before['post_types'] !== $after['post_types'] ) {
			self::request_reconcile();
		}
	}

	/**
	 * Hands a post's current state to the ledger.
	 *
	 * @param WP_Post $post The post. It is read again from the database: what is published is
	 *                      what is stored, not what this request was handed.
	 * @param array   $opts Passed to the ledger (the backfill sets at and note).
	 * @return array|null The ledger's result, or null if publishing failed on a web request.
	 */
	public function publish( WP_Post $post, array $opts = array() ) {
		return $this->guard(
			function () use ( $post, $opts ) {
				return $this->sync( (int) $post->ID, $opts );
			},
			(int) $post->ID
		);
	}

	/**
	 * The post has already been saved by the time this runs, so an exception could only turn a
	 * successful save into an error screen for the editor. On the web a failure is put on the
	 * repair queue instead, retried until it succeeds, and shown in Tools and Site Health while
	 * it is owed. On the command line it fails visibly.
	 *
	 * @param callable $work    The publish or the withdrawal.
	 * @param int      $post_id Post id, for the queue.
	 * @return mixed
	 * @throws Throwable Under WP-CLI.
	 */
	private function guard( callable $work, int $post_id ) {
		if ( ! Plugin::instance()->registry->has( self::ORIGIN ) ) {
			return null; // Publishing is switched off for this site.
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			// The command line shows the failure, and it is owed all the same.
			try {
				$result = $work();
			} catch ( Throwable $e ) {
				$this->owe( $post_id, $e->getMessage() );
				throw $e;
			}
			$this->settled( $post_id );
			return $result;
		}
		try {
			$result = $work();
			$this->settled( $post_id );
			return $result;
		} catch ( Throwable $e ) {
			$this->owe( $post_id, $e->getMessage() );
			return null;
		}
	}

	/**
	 * A post's GMT date as ISO 8601. Drafts, and some imported posts, have a zero GMT date; the
	 * local date converted with the site's timezone is the best there is for those.
	 *
	 * @param string $gmt   The GMT date column.
	 * @param string $local The local date column.
	 */
	public static function gmt_iso( string $gmt, string $local ): string {
		if ( '0000-00-00 00:00:00' === $gmt || '' === $gmt ) {
			$gmt = get_gmt_from_date( $local );
		}
		return gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $gmt . ' UTC' ) );
	}

	/**
	 * A post's snapshot, or null when it is not public.
	 *
	 * @param WP_Post $post The post.
	 * @return array|null
	 */
	public function snapshot( WP_Post $post ) {
		return $this->build( $post )['snapshot'];
	}

	/**
	 * Builds the snapshot, plus a report of what the render did, for the backfill dump.
	 *
	 * @param WP_Post $post The post.
	 * @return array { snapshot: ?array, featured_added: bool, body_empty: bool, embed_links: int, stats: array }
	 */
	public function build( WP_Post $post ): array {
		if ( ! self::is_public( $post ) ) {
			return array(
				'snapshot'       => null,
				'featured_added' => false,
				'body_empty'     => false,
				'embed_links'    => 0,
				'stats'          => array(),
			);
		}
		// In the scheme the site's own address is stored with. A save from an https admin and a
		// save from cron would otherwise write two addresses for one page, and each change of
		// address is a version.
		$scheme    = (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_SCHEME );
		$permalink = set_url_scheme( (string) get_permalink( $post ), $scheme ? $scheme : null );
		$opts      = array(
			'base'        => $permalink,
			'repair'      => (bool) Settings::get( 'legacy_repair' ),
			// A post's author cannot mark part of a post as a quotation of another item, or as
			// machine-generated: those are the publisher's claims, and posts do not make them.
			'wire_tokens' => false,
		);

		$body        = ContentRenderer::post_body( $post );
		$embed_links = ContentRenderer::embed_links();
		$rendered    = ContentRenderer::render_html( $body, $post->post_title, $opts );
		$body_empty  = false === strpos( rtrim( $rendered['content_md'], "\n" ), "\n" ); // Just the title.

		// The featured image is part of what a reader sees on the post's page, but it lives outside
		// the content. When the body doesn't already show it, it leads the item, so setting or
		// changing it changes the hash and is a publish event.
		$featured       = Settings::get( 'featured_image' ) ? $this->featured_figure( $post, $rendered ) : '';
		$featured_added = '' !== $featured;
		if ( $featured_added ) {
			$rendered = ContentRenderer::render_html( $featured . $body, $post->post_title, $opts );
		}

		$snapshot = array(
			'kind'          => 'thread',
			'title'         => ContentRenderer::plain_title( $post->post_title ),
			'permalink'     => $permalink,
			'content_md'    => Markdown::escape_item_references( $rendered['content_md'] ),
			'content_html'  => $rendered['content_html'],
			'media'         => $rendered['media'],
			'transclusions' => array(), // Posts author no transclusion directives.
		);

		// The author is optional in the protocol (spec §5.5). A post with no author, which only
		// scripts create, makes no assertion rather than asserting an empty name.
		$name = (string) get_the_author_meta( 'display_name', (int) $post->post_author );
		if ( '' !== trim( $name ) ) {
			$snapshot['author'] = array(
				'name' => $name,
				'url'  => set_url_scheme( home_url( '/' ), $scheme ? $scheme : null ),
			);
		}

		return array(
			'snapshot'       => $snapshot,
			'featured_added' => $featured_added,
			'body_empty'     => $body_empty,
			'embed_links'    => $embed_links,
			'stats'          => $rendered['stats'],
		);
	}

	/**
	 * A figure for the post's featured image, or '' when it has none, or the body already shows
	 * it, or the body opens with an image of its own.
	 *
	 * "Already shows it" matches any size of the same file: a body carrying the 1024px copy of the
	 * featured image shouldn't get the full-size one stacked on top of it. The opening-image rule
	 * covers the rest: posts whose featured image is a separate upload of the picture they open
	 * with, and social-card images that differ from the post's own banner. Either way the reader
	 * already has a lead image.
	 *
	 * @param WP_Post $post     The post.
	 * @param array   $rendered The body rendered without the featured image.
	 */
	private function featured_figure( WP_Post $post, array $rendered ): string {
		$id  = (int) get_post_thumbnail_id( $post );
		$src = $id ? wp_get_attachment_image_src( $id, 'full' ) : false;
		if ( ! $src || empty( $src[0] ) ) {
			return '';
		}
		$unsized = static function ( string $url ): string {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			return preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $path );
		};
		foreach ( $rendered['media'] as $m ) {
			if ( $unsized( $m['url'] ) === $unsized( $src[0] ) ) {
				return '';
			}
		}
		// The first block after the heading, allowing for a link around the image.
		if ( preg_match( '#^<h1>.*?</h1>\n(?:<(?:p|figure|a)\b[^>]*>)*<img\b#s', $rendered['content_html'] ) ) {
			return '';
		}
		$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		return sprintf(
			'<figure><img src="%s" alt="%s" width="%d" height="%d"></figure>',
			esc_url( $src[0] ),
			esc_attr( $alt ),
			(int) $src[1],
			(int) $src[2]
		);
	}

	/**
	 * Ids of public posts that have no item yet, oldest first.
	 *
	 * @param int $limit Maximum to return; 0 for all.
	 * @return int[]
	 */
	public function unpublished_ids( int $limit = 0 ): array {
		global $wpdb;
		$types = get_post_types_by_support( self::SUPPORT );
		if ( ! $types ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the join to the ledger has no core API, and the answer must be current.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN %i i ON i.origin_key = %s AND i.source_key = CONCAT('post:', p.ID)
				 WHERE FIND_IN_SET( p.post_type, %s ) AND p.post_status = 'publish' AND LENGTH( p.post_password ) = 0 AND i.blyg_id IS NULL
				 ORDER BY p.post_date_gmt ASC, p.ID ASC LIMIT %d",
				\SoapboxBlyg\Ledger\Schema::table( 'items' ),
				self::ORIGIN,
				implode( ',', $types ),
				$limit > 0 ? $limit : PHP_INT_MAX
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * A post's slug as a dry run names its files: `{id}-{slug}.md`. One rule for the command line
	 * and for the Tools screen, so that a dump from either can be compared with a dump from the
	 * other, file for file.
	 *
	 * @param WP_Post $post The post.
	 */
	public static function dump_slug( WP_Post $post ): string {
		$slug = sanitize_file_name( urldecode( $post->post_name ) );
		return '' === $slug ? 'untitled' : substr( $slug, 0, 80 );
	}

	/**
	 * Runs something in the site's language, whatever language the person at the screen uses.
	 *
	 * A changelog note is part of a published document and can never be changed afterwards. It
	 * is written for the blyg's readers, so it is in the language of the site, not in that of
	 * whichever administrator happened to run the backfill.
	 *
	 * @param callable $work Returns the text.
	 * @return string
	 */
	private static function in_site_language( callable $work ): string {
		$switched = switch_to_locale( get_locale() );
		try {
			return (string) $work();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Gives v1 to one post that has no item yet, as the backfill does: created is the post's
	 * publish date, the event time is its last-modified date. Every backfill goes through here,
	 * and so through the same checks as a save.
	 *
	 * @param WP_Post $post The post.
	 * @return array The ledger's result.
	 * @throws Throwable When the post could not be published; the caller reports it.
	 */
	public function backfill_one( WP_Post $post ): array {
		$result = $this->sync(
			(int) $post->ID,
			array(
				'at'   => self::gmt_iso( $post->post_modified_gmt, $post->post_modified ),
				'note' => self::in_site_language(
					static function () {
						return __( 'imported from WordPress', 'soapbox-blyg' );
					}
				),
			)
		);
		return is_array( $result ) ? $result : array(
			'id'      => null,
			'version' => null,
			'event'   => 'none',
		);
	}
}
