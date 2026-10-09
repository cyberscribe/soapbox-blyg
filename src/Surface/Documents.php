<?php
/**
 * The served documents.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Surface;

use SoapboxBlyg\Ledger\Ledger;
use SoapboxBlyg\Ledger\Schema;
use SoapboxBlyg\Ledger\Snapshot;
use SoapboxBlyg\Origins\Origin;
use SoapboxBlyg\Origins\Registry;
use SoapboxBlyg\Render\ContentRenderer;
use SoapboxBlyg\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the five documents of an origin's surface from the ledger: manifest, archive index, item,
 * pinned version and feed (spec §§5-8). Each builder returns [ body, last-modified unix time ], or
 * null for "not here".
 *
 * Nothing is rendered here. Content comes out of the ledger exactly as it went in.
 */
class Documents {

	/**
	 * The protocol version these documents are written to (spec §3.1).
	 */
	const PROTOCOL = '0.3';

	/**
	 * The conformance level claimed (spec §3). The documents carry `page` and may advertise a
	 * Webmention endpoint, which are level 2 constructs. The key is informative: readers must not
	 * gate on it.
	 */
	const LEVEL = 2;

	const FEED_WINDOW = 50;

	const NS_BLYG = 'https://blygger.org/ns/0.1';
	const NS_DC   = 'http://purl.org/dc/elements/1.1/';

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
	 * The generator string: this client's name and version (spec §3.2).
	 */
	public static function generator(): string {
		return SOAPBOX_BLYG_GENERATOR . '/' . SOAPBOX_BLYG_VERSION;
	}

	/**
	 * Unix time of a protocol timestamp.
	 *
	 * @param string $iso ISO 8601 UTC.
	 */
	private static function time( string $iso ): int {
		return (int) strtotime( $iso );
	}

	/**
	 * A protocol timestamp in the format RSS requires.
	 *
	 * @param string $iso ISO 8601 UTC.
	 */
	private static function rfc822( string $iso ): string {
		return gmdate( 'D, d M Y H:i:s', self::time( $iso ) ) . ' GMT';
	}

	/**
	 * Builds a document through the object cache. The key includes the origin's generation, which
	 * the ledger moves on every event and pin, and everything else a document depends on that is
	 * not a ledger event.
	 *
	 * @param Origin   $origin The origin.
	 * @param string   $name   Document name within the origin.
	 * @param callable $build  Builds the document.
	 * @param bool     $fresh  Build it now and store nothing: for a document served with items
	 *                         held back, which must not outlive the request.
	 * @return array|null
	 */
	private function cached( Origin $origin, string $name, callable $build, bool $fresh = false ) {
		if ( $fresh ) {
			return $build();
		}
		$key = md5(
			implode(
				'|',
				array(
					$name,
					$origin->key,
					$origin->url(),
					$origin->layout,
					$origin->title,
					$origin->description,
					(string) wp_json_encode( $origin->author ),
					Ledger::generation( $origin->key ),
					// The settings themselves, not when they changed: two changes inside one second
					// would share a timestamp.
					(string) wp_json_encode( Settings::all() ),
					(string) get_option( Settings::CHANGED_OPTION, '' ),
					SOAPBOX_BLYG_VERSION,
					rest_url(),
				)
			)
		);
		$hit = wp_cache_get( $key, 'soapbox_blyg' );
		if ( is_array( $hit ) ) {
			return $hit['doc'];
		}
		$doc = $build();
		// A miss is not cached: anyone can ask for ids that do not exist, and each would take a slot.
		if ( null !== $doc ) {
			wp_cache_set( $key, array( 'doc' => $doc ), 'soapbox_blyg', HOUR_IN_SECONDS );
		}
		return $doc;
	}

	/**
	 * The most withdrawals one request will write before it serves. A reader's request is not
	 * the place to work through a backlog: beyond this the items are simply left out of what is
	 * served, and the origin is asked to catch up in the background.
	 */
	const MAX_SERVE_WRITES = 5;

	/**
	 * In a list of items to hold back: all of them.
	 */
	const ALL = '*';

	/**
	 * Whether an item is among those to hold back.
	 *
	 * @param string   $id     Item id.
	 * @param string[] $hidden What enforce() returned.
	 */
	private static function held( string $id, array $hidden ): bool {
		return $hidden && ( in_array( self::ALL, $hidden, true ) || in_array( $id, $hidden, true ) );
	}

	/**
	 * Makes sure nothing about to be served is something its source has already taken down.
	 *
	 * The ledger is told of a change after WordPress has saved it, and the telling can fail, be
	 * late, or never happen (the plugin was inactive, a reconcile has not run). So the ledger is
	 * not taken at its word: the origin is asked which of its live items have a source that is
	 * no longer public, and those are withdrawn here, through the ledger's ordinary path, before
	 * the document is built. Withdrawing moves the origin's cache generation, so the documents
	 * that were cached go too. Pinned versions are not touched: a pin is a promise.
	 *
	 * What cannot be withdrawn now (the write failed, or there are more than a request should
	 * write) is returned instead, and the caller serves its documents with those items shown as
	 * withdrawn or left out. The answer is always a document. An error status would invite a
	 * cache in front of the site to serve the copy it has, which is the live one.
	 *
	 * @param Origin        $origin      The origin.
	 * @param string[]|null $source_keys The sources about to be served, or null for all of them.
	 * @return string[] Ids of items that must not be served as the ledger has them. self::ALL
	 *                  alone means every item: the origin could not say which are stale.
	 */
	private function enforce( Origin $origin, $source_keys ): array {
		if ( ! $origin->stale_sources ) {
			return array();
		}
		$stale = call_user_func( $origin->stale_sources, $source_keys );
		if ( ! is_array( $stale ) ) {
			// The check itself failed. What cannot be checked is not shown: not knowing is not
			// the same as there being nothing wrong.
			return array( self::ALL );
		}
		$hidden = array();
		$wrote  = 0;
		foreach ( $stale as $source_key ) {
			$source_key = (string) $source_key;
			$failure    = null;
			if ( $wrote < self::MAX_SERVE_WRITES ) {
				++$wrote;
				try {
					// A second at most for the lock: this is a reader's request, and the item is
					// held back from it either way.
					$this->ledger->withdraw_if_stale( $origin->key, $source_key, false, 1 );
					continue;
				} catch ( \Throwable $e ) {
					$failure = $e->getMessage();
				}
			}
			$item = $this->ledger->item_by_source( $origin->key, $source_key );
			if ( $item ) {
				$hidden[] = $item['blyg_id'];
			}
			// A failure is owed; a backlog is for the origin's own reconcile.
			if ( $origin->deferred ) {
				call_user_func( $origin->deferred, $source_key, $failure );
			}
		}
		return $hidden;
	}

	/**
	 * The origin's latest `updated`, or when it was first registered if it has no items yet.
	 *
	 * @param Origin $origin The origin.
	 */
	public function origin_updated( Origin $origin ): string {
		$updated = $this->ledger->latest_updated( $origin->key );
		return null !== $updated ? $updated : $this->registry->registered_at( $origin->key );
	}

	/**
	 * The manifest (spec §6.1), at {origin}blyg.json in every layout.
	 *
	 * @param Origin $origin The origin.
	 * @return array [ body, modified ]
	 */
	public function manifest( Origin $origin ): array {
		return $this->cached(
			$origin,
			'manifest',
			function () use ( $origin ) {
				$author = $origin->author;
				if ( isset( $author['bio'] ) && '' === $author['bio'] ) {
					unset( $author['bio'] ); // Left out rather than sent as an empty string.
				}
				$updated = $this->origin_updated( $origin );

				$doc = array(
					'blyg'      => self::PROTOCOL,
					'level'     => self::LEVEL,
					'generator' => self::generator(),
				);
				if ( '' !== SOAPBOX_BLYG_GENERATOR_URL ) {
					$doc['generator_url'] = SOAPBOX_BLYG_GENERATOR_URL;
				}
				$doc['site']   = $origin->url();
				$doc['title']  = $origin->title;
				$doc['author'] = (object) $author;
				$doc          += $this->locations->pointers( $origin );
				if ( self::receives_mentions() ) {
					$doc['webmention'] = 'webmention';
				}
				$doc['updated'] = $updated;

				// No Last-Modified: the manifest changes with the settings, the site's name and the
				// plugin's version, none of which is a dated event. The ETag carries revalidation.
				return array( Ledger::json( $doc ), 0 );
			}
		);
	}

	/**
	 * Whether this site accepts mentions. Off unless the site owner switched the receiver on.
	 */
	public static function receives_mentions(): bool {
		return (bool) Settings::get( 'webmention' );
	}

	/**
	 * The archive index (spec §6.2): every item this origin ever published, withdrawn ones
	 * included, newest update first, with no window.
	 *
	 * @param Origin $origin The origin.
	 * @return array [ body, modified ], with a third element, true, when items were held back
	 *               and the response must not be stored.
	 */
	public function index( Origin $origin ): array {
		$hidden = $this->enforce( $origin, null );
		return $this->cached(
			$origin,
			'index',
			function () use ( $origin, $hidden ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- cached by the caller.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT i.blyg_id, v.kind, i.created, i.updated, i.current_version FROM %i i
						 JOIN %i v ON v.blyg_id = i.blyg_id AND v.version = i.current_version
						 WHERE i.origin_key = %s ORDER BY i.updated DESC, i.blyg_id ASC',
						Schema::table( 'items' ),
						Schema::table( 'versions' ),
						$origin->key
					),
					ARRAY_A
				);

				$items = array();
				foreach ( (array) $rows as $r ) {
					$items[] = array(
						'id'      => $r['blyg_id'],
						'kind'    => self::held( $r['blyg_id'], $hidden ) ? 'withdrawn' : $r['kind'],
						'created' => $r['created'],
						'updated' => $r['updated'],
						'version' => (int) $r['current_version'],
					);
				}
				$updated = $items ? $items[0]['updated'] : $this->origin_updated( $origin );
				return array(
					Ledger::json(
						array(
							'updated' => $updated,
							'items'   => $items,
						)
					),
					0, // No Last-Modified: a backfill adds entries without moving `updated`.
				) + ( $hidden ? array( 2 => true ) : array() );
			},
			(bool) $hidden
		);
	}

	/**
	 * The item document (spec §5). Null unless the id is in this origin's ledger: an id from
	 * another origin on the same site is not here.
	 *
	 * @param Origin $origin The origin.
	 * @param string $id     Item id.
	 * @return array|null [ body, modified ], with a third element, true, when the item was held
	 *                    back and the response must not be stored.
	 */
	public function item( Origin $origin, string $id ) {
		$hidden = array();
		if ( $origin->stale_sources ) {
			$row = $this->ledger->item_by_id( $id );
			if ( $row && $row['origin_key'] === $origin->key ) {
				$hidden = $this->enforce( $origin, array( $row['source_key'] ) );
			}
		}
		return $this->cached(
			$origin,
			'item:' . $id,
			function () use ( $origin, $id, $hidden ) {
				$item = $this->ledger->item_by_id( $id );
				if ( ! $item || $item['origin_key'] !== $origin->key ) {
					return null;
				}
				$v = $this->ledger->version_row( $id, (int) $item['current_version'] );
				if ( $hidden ) {
					// The withdrawal is owed and not yet written. What is served has the shape the
					// endcap will have: no title, no content, no media.
					$v = array(
						'kind'          => 'withdrawn',
						'content_md'    => '',
						'content_html'  => '',
						'content_hash'  => Snapshot::hash_of( '' ),
						'media'         => '[]',
						'transclusions' => 'thread' === $v['kind'] ? '[]' : null,
						'generated'     => null,
					) + $v;
				}

				$changelog = array();
				foreach ( $this->ledger->changelog_rows( $id ) as $row ) {
					$entry = array(
						'version' => (int) $row['version'],
						'at'      => $row['at'],
						'note'    => $row['note'],
					);
					if ( (int) $row['pinned'] ) {
						$entry['pinned'] = true;
					}
					$changelog[] = $entry;
				}

				// Field order follows the protocol's example.
				$doc = array(
					'blyg'   => self::PROTOCOL,
					'id'     => $id,
					'kind'   => $v['kind'],
					'origin' => $origin->url(),
				);
				if ( '' !== $v['permalink'] ) {
					$doc['page'] = $v['permalink']; // Kept on a withdrawal endcap too (spec §5.8, §9).
				}
				$author = Ledger::unjson( $v['author'] );
				if ( null !== $author ) {
					$doc['author'] = $author; // Optional (spec §5.5): no assertion, no key.
				}
				$doc += array(
					'created'      => $item['created'],
					'updated'      => $item['updated'],
					'version'      => (int) $item['current_version'],
					'content_md'   => $v['content_md'],
					'content_html' => $v['content_html'],
					'content_hash' => $v['content_hash'],
					'media'        => Ledger::unjson( $v['media'] ),
				);
				if ( null !== $v['transclusions'] ) {
					$doc['transclusions'] = Ledger::unjson( $v['transclusions'] );
				}
				if ( null !== $v['generated'] ) {
					$doc['generated'] = Ledger::unjson( $v['generated'] );
				}
				$doc['changelog'] = $changelog;

				// No Last-Modified. Two events can share a second, a withdrawal among them, and a
				// client that revalidates by date alone would be told nothing had changed.
				return array( Ledger::json( $doc ), 0 ) + ( $hidden ? array( 2 => true ) : array() );
			},
			(bool) $hidden
		);
	}

	/**
	 * A pinned version (spec §8). Null unless exactly that version is pinned. Every value comes
	 * from that version's own row, and there is no media array: a pinned document references its
	 * media from content_html.
	 *
	 * @param Origin $origin  The origin.
	 * @param string $id      Item id.
	 * @param int    $version Version number.
	 * @return array|null [ body, modified ]
	 */
	public function pinned( Origin $origin, string $id, int $version ) {
		return $this->cached(
			$origin,
			'pin:' . $id . ':' . $version,
			function () use ( $origin, $id, $version ) {
				$item = $this->ledger->item_by_id( $id );
				if ( ! $item || $item['origin_key'] !== $origin->key ) {
					return null;
				}
				$v = $this->ledger->version_row( $id, $version );
				if ( ! $v || ! (int) $v['pinned'] || 'withdrawn' === $v['kind'] ) {
					return null;
				}

				$doc    = array(
					'blyg'    => self::PROTOCOL,
					'id'      => $id,
					'kind'    => $v['kind'],
					'version' => $version,
					'at'      => $v['at'],
					'note'    => $v['note'],
					'pinned'  => true,
					'origin'  => $origin->url(),
				);
				$author = Ledger::unjson( $v['author'] );
				if ( null !== $author ) {
					$doc['author'] = $author;
				}
				$doc += array(
					'content_md'   => $v['content_md'],
					'content_html' => $v['content_html'],
					'content_hash' => $v['content_hash'],
				);
				if ( null !== $v['transclusions'] ) {
					$doc['transclusions'] = Ledger::unjson( $v['transclusions'] );
				}
				if ( null !== $v['generated'] ) {
					$doc['generated'] = Ledger::unjson( $v['generated'] );
				}
				return array( Ledger::json( $doc ), self::time( $v['pinned_at'] ? $v['pinned_at'] : $v['at'] ) );
			}
		);
	}

	/**
	 * The feed (spec §7): RSS 2.0, one entry per publish event, newest first, a window of 50.
	 *
	 * Each entry keeps its own version, time and guid, and shows the item's latest title, link,
	 * kind and HTML. Only the version belongs to the event; serving an older event's own content
	 * would leak history the item document withholds. A withdrawn item shows only its endcap, and
	 * the filter that removes its earlier events runs in SQL, before the limit, so it cannot push
	 * live items out of the window.
	 *
	 * @param Origin $origin The origin.
	 * @return array [ body, modified ], with a third element, true, when items were held back
	 *               and the response must not be stored.
	 */
	public function feed( Origin $origin ): array {
		$hidden = $this->enforce( $origin, null );
		return $this->cached(
			$origin,
			'feed',
			function () use ( $origin, $hidden ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- cached by the caller.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT v.blyg_id, v.version, v.at, i.created,
						        cur.kind AS cur_kind, cur.title, cur.permalink, cur.content_html, cur.author
						 FROM %i v
						 JOIN %i i ON i.blyg_id = v.blyg_id
						 JOIN %i cur ON cur.blyg_id = i.blyg_id AND cur.version = i.current_version
						 WHERE i.origin_key = %s AND ( cur.kind <> 'withdrawn' OR v.version = i.current_version )
						 ORDER BY v.at DESC, v.version DESC, v.blyg_id ASC
						 LIMIT %d",
						Schema::table( 'versions' ),
						Schema::table( 'items' ),
						Schema::table( 'versions' ),
						$origin->key,
						self::FEED_WINDOW
					),
					ARRAY_A
				);

				$base    = $origin->url();
				$updated = $this->origin_updated( $origin );
				// Escaping is not enough for XML: some code points may not appear in it in any form.
				$x = static function ( string $s ): string {
					return htmlspecialchars( ContentRenderer::xml_safe( $s ), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
				};

				$out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
				$out .= '<rss version="2.0" xmlns:blyg="' . self::NS_BLYG . '" xmlns:dc="' . self::NS_DC . '">' . "\n";
				$out .= "<channel>\n";
				$out .= '<title>' . $x( $origin->title ) . "</title>\n";
				$out .= '<link>' . $x( $base ) . "</link>\n";
				$out .= '<description>' . $x( $origin->description ) . "</description>\n";
				// From the ledger, not the clock: a build date of "now" would change the ETag on every request.
				$out .= '<lastBuildDate>' . self::rfc822( $updated ) . "</lastBuildDate>\n";
				$out .= '<blyg:level>' . self::LEVEL . "</blyg:level>\n";
				$out .= '<blyg:manifest>' . $x( $this->locations->manifest( $origin ) ) . "</blyg:manifest>\n";

				foreach ( (array) $rows as $r ) {
					if ( 'withdrawn' !== $r['cur_kind'] && self::held( $r['blyg_id'], $hidden ) ) {
						continue; // Owed a withdrawal that is not written yet, or could not be checked: left out.
					}
					$withdrawn = 'withdrawn' === $r['cur_kind'];
					$author    = json_decode( $r['author'], true );
					// The link is the item's page, or the origin for an item with none. A withdrawn
					// item's link is the origin too (SPEC §7.4): its address can say what it was about.
					$link = ! $withdrawn && '' !== $r['permalink'] ? $r['permalink'] : $base;
					$out .= "<item>\n";
					$out .= '<title>' . ( $withdrawn ? 'withdrawn' : $x( $r['title'] ) ) . "</title>\n";
					$out .= '<link>' . $x( $link ) . "</link>\n";
					$out .= '<guid isPermaLink="false">blyg:' . $r['blyg_id'] . ':v' . (int) $r['version'] . "</guid>\n";
					$out .= $withdrawn
						? "<description></description>\n"
						: '<description><![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', ContentRenderer::xml_safe( $r['content_html'] ) ) . "]]></description>\n";
					$out .= '<pubDate>' . self::rfc822( $r['at'] ) . "</pubDate>\n";
					if ( ! $withdrawn && is_array( $author ) && isset( $author['name'] ) && is_string( $author['name'] ) && '' !== $author['name'] ) {
						$out .= '<dc:creator>' . $x( $author['name'] ) . "</dc:creator>\n";
					}
					$out .= '<blyg:id>' . $r['blyg_id'] . "</blyg:id>\n";
					$out .= '<blyg:kind>' . $x( $r['cur_kind'] ) . "</blyg:kind>\n";
					$out .= '<blyg:version>' . (int) $r['version'] . "</blyg:version>\n";
					$out .= '<blyg:created>' . $x( $r['created'] ) . "</blyg:created>\n";
					$out .= '<blyg:item>' . $x( $this->locations->item( $origin, $r['blyg_id'] ) ) . "</blyg:item>\n";
					$out .= "</item>\n";
				}
				$out .= "</channel>\n</rss>\n";

				return array( $out, 0 ) + ( $hidden ? array( 2 => true ) : array() ); // No Last-Modified, as for the manifest.
			},
			(bool) $hidden
		);
	}
}
