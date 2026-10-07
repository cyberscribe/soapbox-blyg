<?php
/**
 * Site Health tests.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Admin;

use SoapboxBlyg\Ledger\Verifier;
use SoapboxBlyg\Origins\PostTypeSource;
use SoapboxBlyg\Plugin;
use SoapboxBlyg\Surface\Caching;
use SoapboxBlyg\Surface\Http;
use SoapboxBlyg\Surface\Locations;

defined( 'ABSPATH' ) || exit;

/**
 * The things that silently break a blyg, checked where a site owner already looks.
 *
 * The edge-cache test exists because of a real failure. A managed host put a 30-day shared-cache
 * lifetime on every path ending in .json, whatever the site asked for. Left like that, an item
 * the author withdrew would have kept being served for a month. Any site behind a page cache or
 * a CDN can meet the same thing.
 */
class SiteHealth {

	/**
	 * How long a request for one of the site's own documents may take.
	 */
	const LOOPBACK_TIMEOUT = 10;

	/**
	 * How long test_edge_cache() waits between its two requests for a document: long enough for
	 * a cache that stored the first to answer the second.
	 */
	const PROBE_GAP = 2;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'site_status_tests', array( __CLASS__, 'tests' ) );
	}

	/**
	 * Adds the tests. The two that make HTTP requests run asynchronously, so they never slow the
	 * Site Health screen's first paint.
	 *
	 * @param array $tests Core's tests.
	 * @return array
	 */
	public static function tests( $tests ) {
		if ( ! Plugin::instance()->registry->all() ) {
			return $tests; // Publishing is off: there is nothing to check.
		}
		$direct = array(
			'permalinks' => __( 'Blyg: permalinks', 'soapbox-blyg' ),
			'mount'      => __( 'Blyg: mount path', 'soapbox-blyg' ),
			'ledger'     => __( 'Blyg: ledger integrity', 'soapbox-blyg' ),
		);
		foreach ( $direct as $test => $label ) {
			$tests['direct'][ 'soapbox_blyg_' . $test ] = array(
				'label' => $label,
				'test'  => array( __CLASS__, 'test_' . $test ),
			);
		}
		$async = array(
			'reachable'  => __( 'Blyg: surface reachable', 'soapbox-blyg' ),
			'edge-cache' => __( 'Blyg: edge cache', 'soapbox-blyg' ),
		);
		foreach ( $async as $test => $label ) {
			$tests['async'][ 'soapbox_blyg_' . str_replace( '-', '_', $test ) ] = array(
				'label'             => $label,
				'test'              => rest_url( Locations::rest_namespace() . '/admin/health/' . $test ),
				'has_rest'          => true,
				'async_direct_test' => array( __CLASS__, 'test_' . str_replace( '-', '_', $test ) ),
			);
		}
		return $tests;
	}

	/**
	 * A result in the shape Site Health expects.
	 *
	 * @param string $test        Test name.
	 * @param string $status      good, recommended or critical.
	 * @param string $label       One line.
	 * @param string $description Explanation, plain text.
	 * @param string $action_url  Where to go to fix it.
	 * @param string $action_text Link text.
	 */
	private static function result( string $test, string $status, string $label, string $description, string $action_url = '', string $action_text = '' ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Soapbox Blyg', 'soapbox-blyg' ),
				'color' => 'good' === $status ? 'blue' : ( 'critical' === $status ? 'red' : 'orange' ),
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '' === $action_url ? '' : sprintf( '<p><a href="%s">%s</a></p>', esc_url( $action_url ), esc_html( $action_text ) ),
			'test'        => 'soapbox_blyg_' . $test,
		);
	}

	/**
	 * Fetches a URL of this site as a reader would.
	 *
	 * @param string $url URL.
	 * @return array|\WP_Error
	 */
	private static function loopback( string $url ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'   => self::LOOPBACK_TIMEOUT,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter for loopback requests, as Site Health's own tests use it.
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'cookies'   => array(),
				// Nothing that asks a cache to step aside: the question is what a reader is given.
				// And no compression, so that the ETag arrives as the plugin sent it.
				'headers'   => array( 'Accept-Encoding' => 'identity' ),
			)
		);
	}

	/**
	 * The surface answers: every origin's manifest parses, and the feed and index it points to
	 * answer too. Following the manifest's own pointers is what catches a REST API that another
	 * plugin has disabled or put behind a login.
	 */
	public static function test_reachable(): array {
		$plugin = Plugin::instance();
		foreach ( $plugin->registry->all() as $origin ) {
			$manifest_url = $plugin->locations->manifest( $origin );
			$response     = self::loopback( $manifest_url );
			if ( is_wp_error( $response ) ) {
				return self::result(
					'reachable',
					'recommended',
					__( 'The blyg could not be checked from the server', 'soapbox-blyg' ),
					/* translators: 1: manifest URL, 2: error message */
					sprintf( __( 'This site could not request its own manifest at %1$s (%2$s). That usually means the server cannot reach itself by its public address, which also affects other WordPress features. The blyg may still be reachable from outside: open the address in a browser to check.', 'soapbox-blyg' ), $manifest_url, $response->get_error_message() )
				);
			}
			$manifest = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $manifest ) || ! isset( $manifest['blyg'] ) ) {
				return self::result(
					'reachable',
					'critical',
					__( 'The blyg\'s manifest is not being served', 'soapbox-blyg' ),
					/* translators: 1: manifest URL, 2: HTTP status */
					sprintf( __( '%1$s answered with status %2$d, or with something that is not a manifest. Readers cannot find the blyg. Re-saving Settings > Permalinks rebuilds the rewrite rules; if that does not help, another plugin or a server rule is answering at that path.', 'soapbox-blyg' ), $manifest_url, (int) wp_remote_retrieve_response_code( $response ) ),
					admin_url( 'options-permalink.php' ),
					__( 'Permalink settings', 'soapbox-blyg' )
				);
			}
			foreach ( array(
				'feed'  => $plugin->locations->feed( $origin ),
				'items' => $plugin->locations->index( $origin ),
			) as $name => $url ) {
				$status = (int) wp_remote_retrieve_response_code( self::loopback( $url ) );
				if ( 401 === $status || 403 === $status ) {
					return self::result(
						'reachable',
						'critical',
						__( 'The blyg\'s documents are behind a login', 'soapbox-blyg' ),
						/* translators: %s: URL */
						sprintf( __( '%s requires authentication. In the templated layout the blyg\'s documents are served through the REST API, and something on this site is restricting it to logged-in users: usually a security plugin, or a plugin that disables the REST API. Allow anonymous access to the soapbox-blyg/v1 namespace in that plugin\'s settings.', 'soapbox-blyg' ), $url )
					);
				}
				if ( 200 !== $status ) {
					return self::result(
						'reachable',
						'critical',
						__( 'Part of the blyg is not being served', 'soapbox-blyg' ),
						sprintf(
							'feed' === $name
								/* translators: 1: URL, 2: HTTP status */
								? __( 'The manifest points to its feed at %1$s, which answered with status %2$d. Readers can find the blyg but cannot read it.', 'soapbox-blyg' )
								/* translators: 1: URL, 2: HTTP status */
								: __( 'The manifest points to its archive index at %1$s, which answered with status %2$d. Readers can find the blyg but cannot read it.', 'soapbox-blyg' ),
							$url,
							$status
						)
					);
				}
			}
		}
		return self::result( 'reachable', 'good', __( 'The blyg is being served', 'soapbox-blyg' ), __( 'Every blyg on this site answers with a manifest, a feed and an archive index.', 'soapbox-blyg' ) );
	}

	/**
	 * What a response's Cache-Control lets a shared cache do.
	 *
	 * A response can carry the header more than once, and the HTTP client hands repeats over as a
	 * list or joined with commas. Hosts append their own after the plugin's, which is why the
	 * plugin sends an s-maxage: when a directive is repeated, a cache uses the first or treats the
	 * response as stale (RFC 9111 §4.2.1). So the first is the one read here, not the longest.
	 * Not every cache keeps to that (see Http::caching()), so a good answer here is necessary and
	 * not enough: test_edge_cache() also looks at how old the copies it is given are.
	 *
	 * @param string|string[] $header The Cache-Control header or headers.
	 * @return array { seconds: the lifetime a shared cache is given, safe: whether that is no
	 *               more than the lifetime the site chose, stored: whether it may be stored at all, header: the header
	 *               as one string }
	 */
	public static function shared_lifetime( $header ): array {
		$header = strtolower( implode( ', ', array_filter( array_map( 'trim', (array) $header ), 'strlen' ) ) );
		$shared = Caching::first( $header, 's-maxage' );
		$stored = ! Caching::has( $header, 'no-store' );
		// s-maxage is what a shared cache follows; without one it falls back to max-age.
		$seconds = $stored ? (int) ( null !== $shared ? $shared : Caching::first( $header, 'max-age' ) ) : 0;
		return array(
			'seconds' => $seconds,
			'safe'    => $seconds <= Caching::lifetime(),
			'stored'  => $stored,
			'header'  => $header,
		);
	}

	/**
	 * Nothing in front of the site holds the blyg's documents for long. Warns when a response
	 * carries a shared-cache lifetime over the one the site chose, or when its Age grows between two
	 * requests a few seconds apart, which means a cache is answering instead of WordPress.
	 */
	public static function test_edge_cache(): array {
		$plugin  = Plugin::instance();
		$checked = 0;
		$allowed = Caching::lifetime();
		$words   = Caching::in_words( $allowed );
		$holding = false;
		foreach ( $plugin->registry->all() as $origin ) {
			// The manifest, and the feed: in the templated layout they travel different paths. In
			// the fixed layout the item documents sit on paths that hosts cache by their endings,
			// and an item is what a withdrawal is about, so one of those is asked for as well.
			// Each is paired with the document as the plugin would make it now.
			$documents = array(
				$plugin->locations->manifest( $origin ) => $plugin->documents->manifest( $origin ),
				$plugin->locations->feed( $origin )     => $plugin->documents->feed( $origin ),
			);
			$live      = 'fixed' === $origin->layout ? $plugin->ledger->live_items_after( $origin->key, '', 1 ) : array();
			if ( $live ) {
				$documents[ $plugin->locations->item( $origin, $live[0]['blyg_id'] ) ] = $plugin->documents->item( $origin, $live[0]['blyg_id'] );
			}
			foreach ( $documents as $url => $now ) {
				$first = self::loopback( $url );
				if ( is_wp_error( $first ) ) {
					continue;
				}
				++$checked;
				$lifetime = self::shared_lifetime( wp_remote_retrieve_header( $first, 'cache-control' ) );
				// The header itself hands a shared cache too long a lifetime: the plugin's is not
				// first, or is not there.
				if ( ! $lifetime['safe'] ) {
					return self::result(
						'edge_cache',
						'recommended',
						__( 'Your host adds its own caching instructions to the blyg', 'soapbox-blyg' ),
						/* translators: 1: URL, 2: the Cache-Control header as received, 3: mount path, 4: a length of time, such as "5 mins" */
						sprintf( __( '%1$s arrives with caching instructions that are not the plugin\'s (%2$s). The plugin puts its own lifetime (%4$s) first, and a cache follows the first it reads; here a longer one, added by the web server or by something else in front of WordPress, comes first. An item you withdraw, or a post you edit, could keep being served in its old form. If a plugin or theme on this site filters soapbox_blyg_cache_control, its value must keep s-maxage. Otherwise this comes from the server\'s own configuration, so excluding the path from a page cache does not remove it: ask your host to stop adding a Cache-Control header for %3$s and for /wp-json/soapbox-blyg/.', 'soapbox-blyg' ), $url, $lifetime['header'], $origin->path, $words )
					);
				}
				// What the header allows is one half. The other is what a cache does with it, and
				// some keep a document for as long as their own configuration says whatever the
				// header allows. That matters only if the copy handed out is not the current one,
				// and the ETag says which copy it is: it is a hash of the body. An answer with an
				// Age came from a cache. One without may be the request that filled it, so the
				// document is asked for once more.
				$answers = array( $first );
				if ( '' === implode( '', (array) wp_remote_retrieve_header( $first, 'age' ) ) ) {
					sleep( self::PROBE_GAP );
					$answers[] = self::loopback( $url );
				}
				// A document built with items held back is sent not to be stored, and is no measure.
				$expected = is_array( $now ) && empty( $now[2] ) ? Http::etag( (string) $now[0] ) : '';
				$old      = 0;
				$current  = false;
				foreach ( $answers as $answer ) {
					if ( is_wp_error( $answer ) ) {
						continue;
					}
					// A server that compresses a response marks its ETag (W/, -gzip): the same document.
					$received = (string) preg_replace( '/^w\/|[-;](gzip|br|deflate|zstd)(?="$)/i', '', trim( implode( '', (array) wp_remote_retrieve_header( $answer, 'etag' ) ) ) );
					$current  = $current || ( '' !== $expected && $received === $expected );
					if ( '' !== $expected && '' !== $received && $received !== $expected && 200 === (int) wp_remote_retrieve_response_code( $answer ) ) {
						return self::result(
							'edge_cache',
							'recommended',
							__( 'A cache in front of this site is serving an old copy of the blyg', 'soapbox-blyg' ),
							/* translators: 1: URL, 2: mount path */
							sprintf( __( '%1$s was answered with a copy that is not the document as it is now. Something in front of WordPress (a CDN, Varnish, or your host\'s own cache) kept an earlier one and has not been told it changed. An item you withdraw, or a post you edit, keeps being served in its old form from there. Purge that cache now. So that it does not happen again, exclude the path %2$s, and the REST namespace /wp-json/soapbox-blyg/, from it, or connect it to the soapbox_blyg_purge_urls action. The plugin\'s readme lists the cache plugins it purges by itself.', 'soapbox-blyg' ), $url, $origin->path )
						);
					}
					$old = max( $old, max( array_map( 'intval', (array) wp_remote_retrieve_header( $answer, 'age' ) ) ) );
				}
				// Kept past its lifetime, and nothing to show that the copy is current.
				if ( $old > $allowed && ! $current ) {
					return self::result(
						'edge_cache',
						'recommended',
						__( 'A cache in front of this site is holding the blyg', 'soapbox-blyg' ),
						/* translators: 1: URL, 2: mount path, 3: a length of time, such as "5 mins" */
						sprintf( __( '%1$s is being served from a shared cache (a CDN, Varnish, or your host\'s own cache) for longer than the cache lifetime in the plugin\'s settings (%3$s). An item you withdraw, or a post you edit, would keep being served in its old form until that cache expires. Exclude the path %2$s, and the REST namespace /wp-json/soapbox-blyg/, from that cache. The plugin\'s readme has instructions for Varnish, Cloudflare and nginx.', 'soapbox-blyg' ), $url, $origin->path, $words )
					);
				}
				$holding = $holding || $old > $allowed;
			}
		}
		if ( 0 === $checked ) {
			// Nothing could be fetched, so nothing is known. Saying "good" here would be a guess.
			return self::result(
				'edge_cache',
				'recommended',
				__( 'The blyg\'s caching could not be checked from the server', 'soapbox-blyg' ),
				/* translators: %d: a number of seconds */
				sprintf( __( 'This site could not request its own blyg, so it cannot tell whether a cache in front of it is holding the documents. To check by hand, request the manifest twice, a few seconds apart, and look at the response headers: the first s-maxage in Cache-Control should be %1$d, and Age should never be more than %1$d.', 'soapbox-blyg' ), $allowed )
			);
		}
		if ( $holding ) {
			return self::result(
				'edge_cache',
				'good',
				__( 'The copies of the blyg being served are current', 'soapbox-blyg' ),
				/* translators: %s: a length of time, such as "5 mins" */
				sprintf( __( 'A cache in front of this site keeps the blyg\'s documents for longer than the cache lifetime in the plugin\'s settings (%s), and the copies it is serving are the documents as they are now. That is safe for as long as the cache is told when a document changes; the plugin\'s readme lists the cache plugins it tells by itself. If you withdraw a post and it is still being served, this test will say so.', 'soapbox-blyg' ), $words )
			);
		}
		return self::result(
			'edge_cache',
			'good',
			__( 'No cache is holding the blyg', 'soapbox-blyg' ),
			/* translators: %s: a length of time, such as "5 mins" */
			sprintf( __( 'No cache in front of this site keeps the blyg\'s documents for longer than the cache lifetime in the plugin\'s settings (%s).', 'soapbox-blyg' ), $words )
		);
	}

	/**
	 * The site is not on "Plain" permalinks, which have no rewrite rules to serve a blyg with.
	 */
	public static function test_permalinks(): array {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return self::result(
				'permalinks',
				'critical',
				__( 'The blyg cannot be served on "Plain" permalinks', 'soapbox-blyg' ),
				__( 'A blyg is served at an address of its own, such as /blyg/blyg.json, which needs WordPress\'s rewrite rules. "Plain" permalinks switch those off. Choose any other permalink structure.', 'soapbox-blyg' ),
				admin_url( 'options-permalink.php' ),
				__( 'Permalink settings', 'soapbox-blyg' )
			);
		}
		return self::result( 'permalinks', 'good', __( 'Permalinks can serve the blyg', 'soapbox-blyg' ), __( 'The site\'s permalink structure supports the addresses a blyg needs.', 'soapbox-blyg' ) );
	}

	/**
	 * Nothing else claims a mount, and no origin was registered away from the mount it published at.
	 */
	public static function test_mount(): array {
		$plugin    = Plugin::instance();
		$conflicts = $plugin->registry->conflicts();
		if ( $conflicts ) {
			$key = (string) array_key_first( $conflicts );
			return self::result(
				'mount',
				'critical',
				__( 'A blyg was asked to move, and was not moved', 'soapbox-blyg' ),
				/* translators: 1: origin key, 2: the path asked for, 3: the path it is locked to */
				sprintf( __( 'The blyg "%1$s" is now configured for %2$s, but it has already published at %3$s and is still served there: published addresses are permanent. Put the configuration back to %3$s.', 'soapbox-blyg' ), $key, $conflicts[ $key ], $plugin->registry->get( $key )->path )
			);
		}
		foreach ( $plugin->registry->all() as $origin ) {
			$page = get_page_by_path( trim( $origin->path, '/' ), OBJECT, array_keys( get_post_types( array( 'public' => true ) ) ) );
			if ( $page ) {
				return self::result(
					'mount',
					'recommended',
					__( 'A page has the same address as the blyg', 'soapbox-blyg' ),
					/* translators: 1: page title, 2: mount path */
					sprintf( __( '"%1$s" has the address %2$s, which is where the blyg is mounted. The blyg wins, so that page cannot be reached. Give the page a different slug.', 'soapbox-blyg' ), get_the_title( $page ), $origin->path ),
					(string) get_edit_post_link( $page, 'raw' ),
					__( 'Edit the page', 'soapbox-blyg' )
				);
			}
		}
		return self::result( 'mount', 'good', __( 'The blyg\'s address is its own', 'soapbox-blyg' ), __( 'No page or post shares an address with a blyg, and every blyg is served where it first published.', 'soapbox-blyg' ) );
	}

	/**
	 * The ledger was verified recently and passed, and no save has failed to publish. This reads a
	 * stored result; it does not run the verification, which can take a while on a large site.
	 */
	public static function test_ledger(): array {
		$tools  = admin_url( 'tools.php?page=' . ToolsPage::PAGE );
		$action = __( 'Open Tools > Soapbox Blyg', 'soapbox-blyg' );
		$schema = \SoapboxBlyg\Ledger\Schema::blocked();
		if ( '' !== $schema ) {
			return self::result(
				'ledger',
				'critical',
				__( 'The blyg\'s database tables cannot be relied on', 'soapbox-blyg' ),
				'engine' === $schema
					? __( 'The plugin\'s tables are not using the InnoDB storage engine. Without it an item and its version cannot be written together or not at all, so the plugin is publishing nothing and the blyg is offline. Ask your host to convert the soapbox_blyg tables to InnoDB, then deactivate and activate the plugin.', 'soapbox-blyg' )
					: __( 'The plugin\'s tables could not be brought up to date, so the plugin is publishing nothing and the blyg is offline. It tries again by itself; if this stays, deactivate and activate the plugin, and check that the database user may create and alter tables.', 'soapbox-blyg' ),
				$tools,
				$action
			);
		}
		$owed  = Plugin::instance()->posts->owed( 1 );
		$error = $owed ? $owed[0] : null;
		if ( is_array( $error ) ) {
			return self::result(
				'ledger',
				'recommended',
				__( 'A post was saved but not published to the blyg', 'soapbox-blyg' ),
				/* translators: 1: post id, 2: date and time, 3: error message */
				sprintf(
					(int) $error['tries'] >= PostTypeSource::MAX_REPAIR_TRIES
						/* translators: 1: post id, 2: date and time, 3: error message */
						? __( 'Post %1$d was saved on %2$s, but the blyg could not be brought into step with it: %3$s. The plugin has stopped trying. Nothing the post no longer shows is being served; open the post and save it to try again.', 'soapbox-blyg' )
						/* translators: 1: post id, 2: date and time, 3: error message */
						: __( 'Post %1$d was saved on %2$s, but the blyg could not be brought into step with it: %3$s. The plugin keeps trying, and until it succeeds nothing the post no longer shows is served. Saving the post again also retries.', 'soapbox-blyg' ),
					(int) $error['post'],
					(string) $error['at'],
					(string) $error['message']
				),
				$tools,
				$action
			);
		}
		$last = get_option( Verifier::RESULT_OPTION );
		if ( ! is_array( $last ) ) {
			return self::result( 'ledger', 'recommended', __( 'The blyg\'s ledger has never been verified', 'soapbox-blyg' ), __( 'Verifying recomputes the hash of every version ever published and checks the ledger is complete. Run it once now, and again after WordPress or plugin updates.', 'soapbox-blyg' ), $tools, $action );
		}
		if ( empty( $last['passed'] ) ) {
			return self::result(
				'ledger',
				'critical',
				__( 'The blyg\'s ledger failed verification', 'soapbox-blyg' ),
				sprintf(
					/* translators: 1: number of problems, already formatted for the locale, 2: date and time */
					_n( 'The last verification, on %2$s, found %1$s problem. The ledger is the record of everything this site has published; run verify again from Tools to see what is wrong.', 'The last verification, on %2$s, found %1$s problems. The ledger is the record of everything this site has published; run verify again from Tools to see what is wrong.', (int) $last['problems'], 'soapbox-blyg' ),
					number_format_i18n( (int) $last['problems'] ),
					(string) $last['at']
				),
				$tools,
				$action
			);
		}
		if ( time() - (int) strtotime( (string) $last['at'] ) > 30 * DAY_IN_SECONDS ) {
			return self::result( 'ledger', 'recommended', __( 'The blyg\'s ledger was last verified over 30 days ago', 'soapbox-blyg' ), __( 'It passed then. Verify again, especially after WordPress or plugin updates, which can change how posts render.', 'soapbox-blyg' ), $tools, $action );
		}
		return self::result( 'ledger', 'good', __( 'The blyg\'s ledger is verified', 'soapbox-blyg' ), __( 'Every stored version matches its hash, and the ledger is complete.', 'soapbox-blyg' ) );
	}
}
