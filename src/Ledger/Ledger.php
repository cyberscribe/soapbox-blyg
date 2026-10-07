<?php
/**
 * The ledger: every publish event of every item.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Ledger;

use InvalidArgumentException;
use RuntimeException;
use SoapboxBlyg\Origins\Registry;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Owns every protocol decision about identity and versions. A source hands it a snapshot, or null
 * for "not public", and the ledger decides by comparison whether that is a publish event. That is
 * what gives every source the same publish semantics without any of them holding version logic.
 *
 * Everything written here is permanent. Ids are never reused, version rows are never updated apart
 * from the one-way pin flag, and nothing is ever deleted.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- the ledger is this plugin's own tables; reads
 * that are served to the public are cached one level up, in Surface\Documents.
 */
class Ledger {

	const ISO_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

	const EVENTS = array( 'created', 'bumped', 'withdrawn', 'returned', 'none' );

	/**
	 * Why pin() refused, as the code of the exception it throws. The messages are written for a
	 * log or a command line, in English; a screen shows its own words for each code.
	 */
	const PIN_NO_VERSION = 1;
	const PIN_ENDCAP     = 2;
	const PIN_WITHDRAWN  = 3;
	const PIN_NOT_PUBLIC = 4;
	const PIN_CHANGED    = 5;
	const PIN_ALREADY    = 6;
	const PIN_FAILED     = 7;

	/**
	 * The origin registry, for checking an origin exists and recording its first item.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param Registry $registry Origin registry.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * The current time in the one timestamp format the protocol uses.
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Encodes a value the way every stored and served document is encoded.
	 *
	 * @param mixed $value Value.
	 */
	public static function json( $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() cannot throw, and a silently truncated document must never be stored or served.
		return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
	}

	/**
	 * Decodes a stored JSON column as objects, not arrays, so an empty object stays {} when it is
	 * encoded again. Author objects go back out exactly as they were written (spec §5.5).
	 *
	 * @param string|null $json Stored JSON.
	 * @return mixed
	 */
	public static function unjson( $json ) {
		return null === $json ? null : json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * Runs a read-modify-write of one item under a MySQL named lock and a transaction.
	 *
	 * The block editor saves a post and then its meta boxes in two requests, close together.
	 * Without the lock both can read vN and both try to write vN+1; the primary key stops a
	 * duplicate, but the second event would be lost.
	 *
	 * @param string   $name What is being locked.
	 * @param callable $work The work.
	 * @param int      $wait How many seconds to wait for the lock. A save waits; a reader's
	 *                       request, which has a document to serve either way, barely does.
	 * @return mixed Whatever $fn returns.
	 * @throws RuntimeException When the lock cannot be taken.
	 * @throws Throwable        Whatever $fn throws, after rolling back.
	 */
	private function with_lock( string $name, callable $work, int $wait = 15 ) {
		global $wpdb;
		$lock = 'soapbox_blyg:' . md5( $wpdb->prefix . $name );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, $wait ) ) ) {
			throw new RuntimeException( 'soapbox-blyg: could not take the ledger lock' );
		}
		try {
			// A transaction that did not start, or did not commit, is not a transaction: the writes
			// inside it must not be reported as done.
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'soapbox-blyg: could not start a ledger transaction' );
			}
			$result = $work();
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'soapbox-blyg: could not commit a ledger transaction' );
			}
			return $result;
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			// An option written inside the transaction has gone with it; its cached copy has not.
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( Registry::LOCKS_OPTION, 'options' );
			throw $e;
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Turns a failed write into an exception, so the transaction rolls back.
	 *
	 * @param mixed $result A $wpdb write result.
	 * @throws RuntimeException When the write failed.
	 */
	private function must( $result ): void {
		if ( false === $result ) {
			throw new RuntimeException( 'soapbox-blyg: a ledger write failed' );
		}
	}

	/*
	 * Reading.
	 */

	/**
	 * An item row by its source.
	 *
	 * @param string $origin     Origin key.
	 * @param string $source_key The source's own stable key.
	 * @return array|null
	 */
	public function item_by_source( string $origin, string $source_key ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE origin_key = %s AND source_key = %s', Schema::table( 'items' ), $origin, $source_key ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * An item row by id, in any origin.
	 *
	 * @param string $id Item id.
	 * @return array|null
	 */
	public function item_by_id( string $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE blyg_id = %s', Schema::table( 'items' ), $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * One version row, content included.
	 *
	 * @param string $id      Item id.
	 * @param int    $version Version number.
	 * @return array|null
	 */
	public function version_row( string $id, int $version ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE blyg_id = %s AND version = %d', Schema::table( 'versions' ), $id, $version ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Changelog metadata for every version of an item, oldest first. No content.
	 *
	 * @param string $id Item id.
	 * @return array[]
	 */
	public function changelog_rows( string $id ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT version, at, kind, note, pinned, pinned_at, pinned_by FROM %i WHERE blyg_id = %s ORDER BY version ASC',
				Schema::table( 'versions' ),
				$id
			),
			ARRAY_A
		);
	}

	/**
	 * The live items of an origin after a given id, for walking them in batches.
	 *
	 * @param string $origin Origin key.
	 * @param string $after  The last id of the previous batch, or '' to start.
	 * @param int    $limit  Batch size.
	 * @return array[] { blyg_id, source_key }
	 */
	public function live_items_after( string $origin, string $after, int $limit ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.blyg_id, i.source_key FROM %i i JOIN %i v ON v.blyg_id = i.blyg_id AND v.version = i.current_version
				 WHERE i.origin_key = %s AND v.kind <> 'withdrawn' AND i.blyg_id > %s ORDER BY i.blyg_id ASC LIMIT %d",
				Schema::table( 'items' ),
				Schema::table( 'versions' ),
				$origin,
				$after,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * How many items an origin has ever published. An origin with any is locked to its mount.
	 *
	 * @param string $origin Origin key.
	 */
	public function count_items( string $origin ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE origin_key = %s', Schema::table( 'items' ), $origin ) );
	}

	/**
	 * The latest `updated` across an origin's items, or null when it has none.
	 *
	 * @param string $origin Origin key.
	 * @return string|null
	 */
	public function latest_updated( string $origin ) {
		global $wpdb;
		$updated = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(updated) FROM %i WHERE origin_key = %s', Schema::table( 'items' ), $origin ) );
		return $updated ? $updated : null;
	}

	/**
	 * The item whose page is one of the given URLs, in one origin or any.
	 *
	 * A page is matched against every version, not only the latest: a link or a mention made
	 * before a slug changed still names the item (spec §5.8).
	 *
	 * Nothing requires a source to give each item a page of its own, and one that publishes
	 * everything under a single address has pages that name no item in particular. A page that
	 * more than one item has used is ambiguous and matches nothing: such an item can still be
	 * addressed by its document URL.
	 *
	 * @param string[]    $urls   Candidate spellings of one URL.
	 * @param string|null $origin Restrict to this origin.
	 * @return array|null The item row, or null when no item, or more than one, has had this page.
	 */
	public function item_by_page( array $urls, $origin = null ) {
		global $wpdb;
		$urls = array_values( array_unique( array_filter( $urls, 'strlen' ) ) );
		if ( ! $urls ) {
			return null;
		}
		// One URL has at most three spellings (as given, with and without a trailing slash), so the
		// query is written for three and the list is padded with its first entry.
		$urls = array_slice( array_pad( $urls, 3, $urls[0] ), 0, 3 );

		// Two rows are enough to know the page is shared.
		if ( null === $origin ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT DISTINCT i.* FROM %i i JOIN %i v ON v.blyg_id = i.blyg_id WHERE v.permalink IN (%s, %s, %s) LIMIT 2',
					Schema::table( 'items' ),
					Schema::table( 'versions' ),
					$urls[0],
					$urls[1],
					$urls[2]
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT DISTINCT i.* FROM %i i JOIN %i v ON v.blyg_id = i.blyg_id WHERE v.permalink IN (%s, %s, %s) AND i.origin_key = %s LIMIT 2',
					Schema::table( 'items' ),
					Schema::table( 'versions' ),
					$urls[0],
					$urls[1],
					$urls[2],
					$origin
				),
				ARRAY_A
			);
		}
		return 1 === count( $rows ) ? $rows[0] : null;
	}

	/*
	 * Writing.
	 */

	/**
	 * Records a publish event if, and only if, the snapshot differs from what is live.
	 *
	 * | Ledger state | Snapshot       | Result                              |
	 * | no item      | snapshot       | new item, v1, created               |
	 * | no item      | null           | nothing: drafts are invisible       |
	 * | live         | same hash      | none                                |
	 * | live         | different hash | vN+1, bumped                        |
	 * | live         | null           | vN+1 withdrawal endcap, withdrawn   |
	 * | withdrawn    | snapshot       | vN+1, returned, under the same id   |
	 * | withdrawn    | null           | none                                |
	 *
	 * @param string     $origin     Origin key.
	 * @param string     $source_key The source's stable key for this item.
	 * @param array|null $snapshot   null means "not public".
	 * @param array      $opts       at: ISO time of the event (default now). created: ISO time for a
	 *                               new item's `created` (default the event time). note: changelog
	 *                               note. fingerprint: the source's fingerprint when the
	 *                               snapshot was taken; inside the lock the origin's source_state
	 *                               is asked again, and if the fingerprint has moved nothing is
	 *                               written. still_current: a callable asked the same question,
	 *                               for a source with no source_state. Unknown keys are ignored,
	 *                               so the array can grow.
	 * @return array { id: ?string, version: ?int, event: string }
	 * @throws InvalidArgumentException For an unknown origin, a bad key, option or snapshot.
	 */
	public function publish( string $origin, string $source_key, $snapshot, array $opts = array() ): array {
		$this->registry->get( $origin );
		if ( '' === $source_key || strlen( $source_key ) > 191 ) {
			throw new InvalidArgumentException( 'soapbox-blyg: source_key must be 1-191 characters' );
		}
		foreach ( array( 'at', 'created' ) as $k ) {
			if ( isset( $opts[ $k ] ) && ! preg_match( self::ISO_PATTERN, (string) $opts[ $k ] ) ) {
				throw new InvalidArgumentException( sprintf( 'soapbox-blyg: opts[%s] must be ISO 8601 UTC (YYYY-MM-DDTHH:MM:SSZ)', esc_html( $k ) ) );
			}
		}

		if ( null !== $snapshot ) {
			/**
			 * Filters a snapshot before it is validated and compared with the ledger.
			 *
			 * @param array  $snapshot   Snapshot in array form.
			 * @param string $origin     Origin key.
			 * @param string $source_key Source key.
			 */
			$snapshot = Snapshot::from_array( (array) apply_filters( 'soapbox_blyg_snapshot', $snapshot, $origin, $source_key ) );
		}

		$result = $this->with_lock(
			"{$origin}\n{$source_key}",
			function () use ( $origin, $source_key, $snapshot, $opts ) {
				return $this->apply( $origin, $source_key, $snapshot, $opts );
			}
		);

		if ( 'none' !== $result['event'] ) {
			self::touch( $origin );
			/**
			 * Fires after every ledger event.
			 *
			 * @param string $event   created, bumped, withdrawn or returned.
			 * @param string $id      Item id.
			 * @param int    $version The version the event produced.
			 * @param string $origin  Origin key.
			 */
			do_action( 'soapbox_blyg_event', $result['event'], $result['id'], $result['version'], $origin );
		}
		return $result;
	}

	/**
	 * The event table, inside the lock.
	 *
	 * @param string        $origin     Origin key.
	 * @param string        $source_key Source key.
	 * @param Snapshot|null $snapshot   Snapshot, or null for "not public".
	 * @param array         $opts       See publish().
	 */
	private function apply( string $origin, string $source_key, $snapshot, array $opts ): array {
		global $wpdb;

		$at   = isset( $opts['at'] ) ? $opts['at'] : self::now();
		$note = isset( $opts['note'] ) ? (string) $opts['note'] : null;
		$item = $this->item_by_source( $origin, $source_key );

		// A snapshot is rendered before the lock is taken, and rendering is slow. If the source has
		// been saved again in the meantime, this snapshot is of something that no longer exists,
		// and the save that replaced it will publish its own. The source is asked what it holds
		// now, here, where no other event for this item can be written. A timestamp would not do:
		// two saves can share a second.
		$fingerprint = isset( $opts['fingerprint'] ) ? (string) $opts['fingerprint'] : null;
		$holds       = true;
		if ( null !== $fingerprint ) {
			$state = $this->source_state( $origin, $source_key );
			$holds = null === $state || $state['fingerprint'] === $fingerprint;
		}
		if ( $holds && isset( $opts['still_current'] ) && is_callable( $opts['still_current'] ) ) {
			$holds = (bool) call_user_func( $opts['still_current'] );
		}
		// The source is also asked whether it is public, by the same test that decides what is
		// served. A snapshot of something that is not public is not published, whoever hands it
		// over and whenever it was made: it is treated as the withdrawal it ought to have been.
		if ( $holds && null !== $snapshot ) {
			$state = isset( $state ) ? $state : $this->source_state( $origin, $source_key );
			if ( null !== $state && ! $state['public'] ) {
				$snapshot = null;
			}
		}
		if ( ! $holds ) {
			return array(
				'id'      => $item ? $item['blyg_id'] : null,
				'version' => $item ? (int) $item['current_version'] : null,
				'event'   => 'none',
			);
		}

		if ( ! $item ) {
			if ( null === $snapshot ) {
				return array(
					'id'      => null,
					'version' => null,
					'event'   => 'none',
				);
			}
			do {
				$id = Id::mint();
			} while ( $this->item_by_id( $id ) ); // 2^-128, checked because ids are forever.

			// ISO strings in one format sort as times.
			$created = min( isset( $opts['created'] ) ? $opts['created'] : $at, $at );
			// Where the origin is mounted is part of what its first item promises, so it is written
			// with the item: both happen or neither does.
			$mounted = $this->registry->get( $origin );
			$this->must(
				$wpdb->insert(
					Schema::table( 'items' ),
					array(
						'blyg_id'         => $id,
						'origin_key'      => $origin,
						'source_key'      => $source_key,
						'created'         => $created,
						'current_version' => 1,
						'updated'         => $at,
						'source_fp'       => $fingerprint,
						'mount'           => $mounted->path,
						'layout'          => $mounted->layout,
					)
				)
			);
			$this->insert_version( $id, 1, $at, $note, $snapshot );
			$this->registry->lock( $origin );
			return array(
				'id'      => $id,
				'version' => 1,
				'event'   => 'created',
			);
		}

		$id      = $item['blyg_id'];
		$n       = (int) $item['current_version'];
		$current = $this->version_row( $id, $n );
		$live    = 'withdrawn' !== $current['kind'];
		$same    = array(
			'id'      => $id,
			'version' => $n,
			'event'   => 'none',
		);
		// Whatever comes of this, the ledger now agrees with the source as it was fingerprinted.
		if ( null !== $fingerprint && $fingerprint !== $item['source_fp'] ) {
			$this->must( $wpdb->update( Schema::table( 'items' ), array( 'source_fp' => $fingerprint ), array( 'blyg_id' => $id ) ) );
		}

		if ( null === $snapshot ) {
			if ( ! $live ) {
				return $same;
			}
			$event = 'withdrawn';
		} elseif ( $live && $snapshot->fingerprint() === ( ! empty( $current['snapshot_fp'] ) ? $current['snapshot_fp'] : Snapshot::fingerprint_of( $current ) ) ) {
			// The same in every published field, not only in its Markdown.
			return $same;
		} else {
			$event = $live ? 'bumped' : 'returned';
		}

		// Changelog times must not run backwards: readers order by them. A backdated `at` or a clock
		// step is clamped to the previous event's time.
		$at = max( $at, $current['at'] );

		if ( 'withdrawn' === $event ) {
			$this->insert_endcap( $id, $n + 1, $at, $note, $current );
		} else {
			$this->insert_version( $id, $n + 1, $at, $note, $snapshot );
		}
		$this->must(
			$wpdb->update(
				Schema::table( 'items' ),
				array(
					'current_version' => $n + 1,
					'updated'         => $at,
				),
				array( 'blyg_id' => $id )
			)
		);
		return array(
			'id'      => $id,
			'version' => $n + 1,
			'event'   => $event,
		);
	}

	/**
	 * Stores one version.
	 *
	 * @param string      $id       Item id.
	 * @param int         $version  Version number.
	 * @param string      $at       Event time.
	 * @param string|null $note     Changelog note.
	 * @param Snapshot    $snapshot What was published.
	 */
	private function insert_version( string $id, int $version, string $at, $note, Snapshot $snapshot ): void {
		global $wpdb;
		$this->must(
			$wpdb->insert(
				Schema::table( 'versions' ),
				array(
					'blyg_id' => $id,
					'version' => $version,
					'at'      => $at,
					'note'    => $note,
					'pinned'  => 0,
				) + $snapshot->columns() + array(
					'content_hash' => $snapshot->hash(),
					'snapshot_fp'  => $snapshot->fingerprint(),
				)
			)
		);
	}

	/**
	 * Stores a withdrawal endcap (spec §9): empty content and media, transclusions [] if the item
	 * was a thread and absent for a fragment, never a generated array.
	 *
	 * It keeps the page and the author assertion of the version it ends. Where an item lives is not
	 * what it said, a mention aimed at a withdrawn item still needs a target (spec §5.8), and the
	 * item document still reports an author.
	 *
	 * @param string      $id       Item id.
	 * @param int         $version  Version number.
	 * @param string      $at       Event time.
	 * @param string|null $note     Changelog note.
	 * @param array       $previous The version row being ended.
	 */
	private function insert_endcap( string $id, int $version, string $at, $note, array $previous ): void {
		global $wpdb;
		$this->must(
			$wpdb->insert(
				Schema::table( 'versions' ),
				array(
					'blyg_id'       => $id,
					'version'       => $version,
					'at'            => $at,
					'kind'          => 'withdrawn',
					'note'          => $note,
					'pinned'        => 0,
					'title'         => '',
					'permalink'     => $previous['permalink'],
					'content_md'    => '',
					'content_html'  => '',
					'content_hash'  => Snapshot::hash_of( '' ),
					'media'         => '[]',
					'transclusions' => 'thread' === $previous['kind'] ? '[]' : null,
					'generated'     => null,
					'author'        => $previous['author'],
				)
			)
		);
	}

	/**
	 * What an origin's source holds for one key, read afresh, or null when the origin cannot be
	 * asked or does not know the key.
	 *
	 * @param string $origin     Origin key.
	 * @param string $source_key Source key.
	 * @param bool   $thorough   Whether to count what is true of the source's whole kind and not
	 *                           of the item (for a post, that its type still has pages). Asked
	 *                           only by a reconcile someone called for.
	 * @return array|null { public: bool, fingerprint: string }
	 */
	private function source_state( string $origin, string $source_key, bool $thorough = false ) {
		$ask = $this->registry->has( $origin ) ? $this->registry->get( $origin )->source_state : null;
		if ( ! $ask ) {
			return null;
		}
		$state = call_user_func( $ask, $source_key, $thorough );
		return is_array( $state ) && isset( $state['public'], $state['fingerprint'] ) ? $state : null;
	}

	/**
	 * Withdraws an item whose source is no longer public, deciding inside the item's lock.
	 *
	 * This is how the ledger is brought back into step when a save that should have withdrawn an
	 * item was never seen, or failed: before serving, during a reconcile, and from the repair
	 * queue. The source is read under the lock, so a post that is published again while this
	 * waits is left alone.
	 *
	 * @param string $origin     Origin key.
	 * @param string $source_key Source key.
	 * @param bool   $thorough   See source_state().
	 * @param int    $wait       Seconds to wait for the item's lock.
	 * @return array { id, version, event } as publish() returns.
	 */
	public function withdraw_if_stale( string $origin, string $source_key, bool $thorough = false, int $wait = 15 ): array {
		$this->registry->get( $origin );
		$result = $this->with_lock(
			"{$origin}\n{$source_key}",
			function () use ( $origin, $source_key, $thorough ) {
				$state = $this->source_state( $origin, $source_key, $thorough );
				if ( null !== $state && $state['public'] ) {
					$item = $this->item_by_source( $origin, $source_key );
					return array(
						'id'      => $item ? $item['blyg_id'] : null,
						'version' => $item ? (int) $item['current_version'] : null,
						'event'   => 'none',
					);
				}
				return $this->apply( $origin, $source_key, null, array() );
			},
			$wait
		);
		if ( 'none' !== $result['event'] ) {
			self::touch( $origin );
			/** This action is documented in src/Ledger/Ledger.php */
			do_action( 'soapbox_blyg_event', $result['event'], $result['id'], $result['version'], $origin );
		}
		return $result;
	}

	/**
	 * For bulk sources: publishes every snapshot, then withdraws every live item of the origin that
	 * the set no longer contains.
	 *
	 * @param string $origin    Origin key.
	 * @param array  $snapshots source_key => snapshot array, or null.
	 * @param array  $opts      Passed to publish().
	 * @return array Counts per event.
	 */
	public function reconcile( string $origin, array $snapshots, array $opts = array() ): array {
		global $wpdb;
		$this->registry->get( $origin );

		$counts = array_fill_keys( self::EVENTS, 0 );
		foreach ( $snapshots as $source_key => $snapshot ) {
			$r = $this->publish( $origin, (string) $source_key, $snapshot, $opts );
			++$counts[ $r['event'] ];
		}

		$live = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT i.source_key FROM %i i JOIN %i v ON v.blyg_id = i.blyg_id AND v.version = i.current_version
				 WHERE i.origin_key = %s AND v.kind <> 'withdrawn'",
				Schema::table( 'items' ),
				Schema::table( 'versions' ),
				$origin
			)
		);
		foreach ( $live as $source_key ) {
			if ( ! array_key_exists( $source_key, $snapshots ) ) {
				$r = $this->publish( $origin, $source_key, null, $opts );
				++$counts[ $r['event'] ];
			}
		}
		return $counts;
	}

	/**
	 * Pins a version: an irrevocable promise to serve exactly that content forever (spec §8).
	 *
	 * @param string $id      Item id.
	 * @param int    $version Version to pin.
	 * @throws RuntimeException Saying which, for a missing version, an endcap or an existing pin.
	 */
	public function pin( string $id, int $version ): void {
		$item = $this->item_by_id( $id );
		if ( ! $item ) {
			throw new RuntimeException( sprintf( 'soapbox-blyg: %s has no version %d', esc_html( $id ), (int) $version ), self::PIN_NO_VERSION ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the code is an integer constant.
		}
		// The same lock a publish event for this item takes, so a pin and a withdrawal cannot cross.
		$this->with_lock(
			"{$item['origin_key']}\n{$item['source_key']}",
			function () use ( $id, $version ) {
				global $wpdb;
				$row = $this->version_row( $id, $version );
				if ( ! $row ) {
					throw new RuntimeException( sprintf( 'soapbox-blyg: %s has no version %d', esc_html( $id ), (int) $version ), self::PIN_NO_VERSION ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the code is an integer constant.
				}
				if ( 'withdrawn' === $row['kind'] ) {
					throw new RuntimeException( sprintf( 'soapbox-blyg: v%d of %s is a withdrawal endcap, which cannot be pinned (spec 8)', (int) $version, esc_html( $id ) ), self::PIN_ENDCAP ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the code is an integer constant.
				}
				// Withdrawing takes an item's content out of publication. Pinning an earlier version
				// afterwards would put it back, permanently, and with a year's caching. Pins made
				// while the item was live survive its withdrawal; no new one can be made after it.
				// The item is read here, inside the lock, so a withdrawal cannot slip in between.
				$current = $this->item_by_id( $id );
				$latest  = $current ? $this->version_row( $id, (int) $current['current_version'] ) : null;
				if ( ! $latest || 'withdrawn' === $latest['kind'] ) {
					throw new RuntimeException( sprintf( 'soapbox-blyg: %s is withdrawn, so no version of it can be pinned now', esc_html( $id ) ), self::PIN_WITHDRAWN ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the code is an integer constant.
				}
				// The ledger can be behind its source: WordPress saves a post before this plugin
				// hears of it, and the write that follows can fail. So the source itself is asked,
				// here, under the lock. It must be public now, and the ledger must hold what it
				// holds: otherwise a pin would fix in public something its author has already
				// changed or taken down.
				$state = $this->source_state( $current['origin_key'], $current['source_key'] );
				if ( null !== $state && ! $state['public'] ) {
					throw new RuntimeException( sprintf( 'soapbox-blyg: the source of %s is not public now, so no version of it can be pinned', esc_html( $id ) ), self::PIN_NOT_PUBLIC ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the code is an integer constant.
				}
				if ( null !== $state && null !== $current['source_fp'] && $state['fingerprint'] !== $current['source_fp'] ) {
					throw new RuntimeException( sprintf( 'soapbox-blyg: the source of %s has changed since the blyg last took it in, so what would be pinned is not what it says now. Save it again and then pin. If its type no longer publishes, its later changes are not on the blyg and it cannot be pinned', esc_html( $id ) ), self::PIN_CHANGED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the code is an integer constant.
				}
				if ( (int) $row['pinned'] ) {
					throw new RuntimeException( sprintf( 'soapbox-blyg: v%d of %s is already pinned', (int) $version, esc_html( $id ) ), self::PIN_ALREADY ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the code is an integer constant.
				}
				// The WHERE repeats the checks, so this UPDATE can only ever move pinned from 0 to 1.
				$done = $wpdb->query(
					$wpdb->prepare(
						"UPDATE %i SET pinned = 1, pinned_at = %s, pinned_by = NULLIF( %d, 0 ) WHERE blyg_id = %s AND version = %d AND pinned = 0 AND kind <> 'withdrawn'",
						Schema::table( 'versions' ),
						self::now(),
						// Who did it, since it cannot be undone. Nobody, when it was done from the command line.
						get_current_user_id(),
						$id,
						$version
					)
				);
				if ( 1 !== $done ) {
					throw new RuntimeException( sprintf( 'soapbox-blyg: pinning v%d of %s did not take', (int) $version, esc_html( $id ) ), self::PIN_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the code is an integer constant.
				}
			}
		);

		self::touch( $item['origin_key'] );
		/**
		 * Fires after a version is pinned.
		 *
		 * @param string $id      Item id.
		 * @param int    $version The pinned version.
		 * @param string $origin  Origin key.
		 */
		do_action( 'soapbox_blyg_pinned', $id, $version, $item['origin_key'] );
	}

	/**
	 * What the ledger holds for one source.
	 *
	 * @param string $origin     Origin key.
	 * @param string $source_key Source key.
	 * @return array|null null if the source has never been public. Otherwise origin, source_key,
	 *                    id, version, kind and pinned (a list of version numbers).
	 */
	public function status( string $origin, string $source_key ) {
		$item = $this->item_by_source( $origin, $source_key );
		if ( ! $item ) {
			return null;
		}
		$pinned = array();
		$kind   = null;
		foreach ( $this->changelog_rows( $item['blyg_id'] ) as $row ) {
			if ( (int) $row['pinned'] ) {
				$pinned[] = (int) $row['version'];
			}
			$kind = $row['kind'];
		}
		return array(
			'origin'     => $origin,
			'source_key' => $source_key,
			'id'         => $item['blyg_id'],
			'version'    => (int) $item['current_version'],
			'kind'       => $kind,
			'pinned'     => $pinned,
		);
	}

	/*
	 * Cache.
	 */

	/**
	 * Marks an origin's served documents stale. Documents are cached under a key that includes this
	 * counter, so one increment retires all of them without knowing their names.
	 *
	 * @param string $origin Origin key.
	 */
	public static function touch( string $origin ): void {
		wp_cache_set( 'generation:' . $origin, microtime(), 'soapbox_blyg' );
	}

	/**
	 * The current cache generation of an origin.
	 *
	 * @param string $origin Origin key.
	 */
	public static function generation( string $origin ): string {
		$generation = wp_cache_get( 'generation:' . $origin, 'soapbox_blyg' );
		if ( false === $generation ) {
			$generation = microtime();
			wp_cache_set( 'generation:' . $origin, $generation, 'soapbox_blyg' );
		}
		return (string) $generation;
	}
}
