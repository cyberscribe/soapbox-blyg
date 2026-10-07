<?php
/**
 * Ledger integrity checks.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Ledger;

use SoapboxBlyg\Origins\PostTypeSource;
use SoapboxBlyg\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Checks that the ledger still says what it promised: every stored hash recomputed, versions
 * running 1..N with no gaps, `updated` equal to the latest version's time, endcaps empty and
 * unpinned, no version row without an item. An id is the items table's primary key, so it cannot
 * sit under two origins; the orphan check covers the only way the tables could disagree.
 *
 * With render drift, it also compares the posts origin with the site, in both directions. That is
 * the safety net for a save that could not be published at the time, and the check to run after a
 * WordPress or plugin update.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads the plugin's own tables, uncached on purpose: this is the check that the stored rows are intact.
 */
class Verifier {

	/**
	 * The stored result of the last full run, for Site Health.
	 */
	const RESULT_OPTION = 'soapbox_blyg_last_verify';

	/**
	 * Failures found.
	 *
	 * @var string[]
	 */
	public $problems = array();

	/**
	 * Things worth knowing that are not failures.
	 *
	 * @var string[]
	 */
	public $warnings = array();

	/**
	 * Progress lines, one per origin and per stage.
	 *
	 * @var string[]
	 */
	public $log = array();

	/**
	 * Called with each line as it is produced, for the command line.
	 *
	 * @var callable|null
	 */
	public $on_line = null;

	/**
	 * Records a line.
	 *
	 * @param string $kind log, problem or warning.
	 * @param string $text The line.
	 */
	private function say( string $kind, string $text ): void {
		if ( 'problem' === $kind ) {
			$this->problems[] = $text;
		} elseif ( 'warning' === $kind ) {
			$this->warnings[] = $text;
		} else {
			$this->log[] = $text;
		}
		if ( is_callable( $this->on_line ) ) {
			call_user_func( $this->on_line, $kind, $text );
		}
	}

	/**
	 * Runs the checks.
	 *
	 * @param string|null    $origin Check one origin; null for every origin with rows in the ledger,
	 *                               registered or not.
	 * @param PostTypeSource $posts  Pass the posts source to also check render drift.
	 * @return bool Whether everything passed.
	 */
	public function run( $origin = null, $posts = null ): bool {
		global $wpdb;
		$items    = Schema::table( 'items' );
		$versions = Schema::table( 'versions' );

		$origins = null !== $origin
			? array( $origin )
			: $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT origin_key FROM %i ORDER BY origin_key', $items ) );

		foreach ( $origins as $key ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE origin_key = %s', $items, $key ) );
			$this->say( 'log', "[{$key}] {$count} item(s)" );

			// Versions 1..N with no gaps, and N is the item's current version.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT i.blyg_id, i.current_version, i.updated, i.created,
					        COUNT(v.version) AS n, MIN(v.version) AS lo, MAX(v.version) AS hi,
					        MAX(CASE WHEN v.version = i.current_version THEN v.at END) AS latest_at
					 FROM %i i LEFT JOIN %i v ON v.blyg_id = i.blyg_id
					 WHERE i.origin_key = %s GROUP BY i.blyg_id',
					$items,
					$versions,
					$key
				),
				ARRAY_A
			);
			foreach ( $rows as $r ) {
				$id = $r['blyg_id'];
				if ( ! Id::is_valid( $id ) ) {
					$this->say( 'problem', "{$id}: not a valid id" );
				}
				if ( (int) $r['n'] !== (int) $r['current_version'] || 1 !== (int) $r['lo'] || (int) $r['hi'] !== (int) $r['current_version'] ) {
					$this->say( 'problem', "{$id}: versions are not 1..{$r['current_version']} (have {$r['n']}, {$r['lo']}..{$r['hi']})" );
				}
				if ( $r['updated'] !== $r['latest_at'] ) {
					$this->say( 'problem', "{$id}: updated {$r['updated']} != v{$r['current_version']} at {$r['latest_at']}" );
				}
				foreach ( array( 'created', 'updated' ) as $field ) {
					if ( ! preg_match( Ledger::ISO_PATTERN, (string) $r[ $field ] ) ) {
						$this->say( 'problem', "{$id}: {$field} is not ISO 8601 UTC" );
					}
				}
			}

			// Every stored hash, recomputed, in batches so content never all sits in memory.
			$checked = 0;
			$last    = array( '', 0 );
			do {
				$batch = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT v.blyg_id, v.version, v.kind, v.pinned, v.at, v.content_md, v.content_html, v.content_hash, v.media, v.transclusions, v.`generated`
						 FROM %i v JOIN %i i ON i.blyg_id = v.blyg_id
						 WHERE i.origin_key = %s AND (v.blyg_id > %s OR (v.blyg_id = %s AND v.version > %d))
						 ORDER BY v.blyg_id, v.version LIMIT 200',
						$versions,
						$items,
						$key,
						$last[0],
						$last[0],
						$last[1]
					),
					ARRAY_A
				);
				foreach ( $batch as $v ) {
					$this->check_version( $v );
					++$checked;
					$last = array( $v['blyg_id'], (int) $v['version'] );
				}
				$more = 200 === count( $batch );
			} while ( $more );
			$this->say( 'log', "  {$checked} version(s) rehashed" );
		}

		$orphans = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i v LEFT JOIN %i i ON i.blyg_id = v.blyg_id WHERE i.blyg_id IS NULL', $versions, $items ) );
		if ( $orphans ) {
			$this->say( 'problem', "{$orphans} version row(s) belong to no item" );
		}

		if ( $posts && ( null === $origin || PostTypeSource::ORIGIN === $origin ) ) {
			$this->render_drift( $posts );
		}

		$passed = ! $this->problems;
		if ( null === $origin ) {
			update_option(
				self::RESULT_OPTION,
				array(
					'at'       => Ledger::now(),
					'passed'   => $passed,
					'problems' => count( $this->problems ),
					'warnings' => count( $this->warnings ),
				),
				false
			);
		}
		return $passed;
	}

	/**
	 * Checks one stored version row.
	 *
	 * @param array $v The row.
	 */
	private function check_version( array $v ): void {
		$label = "{$v['blyg_id']} v{$v['version']}";
		if ( Snapshot::hash_of( $v['content_md'] ) !== $v['content_hash'] ) {
			$this->say( 'problem', "{$label}: content_hash does not match content_md" );
		}
		if ( ! preg_match( Ledger::ISO_PATTERN, $v['at'] ) ) {
			$this->say( 'problem', "{$label}: at is not ISO 8601 UTC" );
		}
		if ( 'withdrawn' === $v['kind'] ) {
			if ( '' !== $v['content_md'] || '' !== $v['content_html'] || '[]' !== $v['media'] || null !== $v['generated'] ) {
				$this->say( 'problem', "{$label}: withdrawal endcap is not empty" );
			}
			if ( (int) $v['pinned'] ) {
				$this->say( 'problem', "{$label}: withdrawal endcap is pinned" );
			}
		} elseif ( 'thread' === $v['kind'] && null === $v['transclusions'] ) {
			$this->say( 'problem', "{$label}: thread without transclusions" );
		} elseif ( 'fragment' === $v['kind'] && null !== $v['transclusions'] ) {
			$this->say( 'problem', "{$label}: fragment with transclusions" );
		} elseif ( ! in_array( $v['kind'], array( 'thread', 'fragment' ), true ) ) {
			$this->say( 'problem', "{$label}: unknown kind {$v['kind']}" );
		}
	}

	/**
	 * The site and the posts ledger, compared in both directions:
	 *
	 * (a) a live item whose post renders to a different hash now: a warning, since its next save
	 *     will publish a version nobody wrote;
	 * (b) a public post with no item, or whose item is withdrawn: a failure;
	 * (c) a live item whose post is gone or no longer public: a failure.
	 *
	 * @param PostTypeSource $posts The posts source.
	 */
	private function render_drift( PostTypeSource $posts ): void {
		global $wpdb;
		$this->say( 'log', '[posts --render]' );

		$ledger = array();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT i.source_key, i.blyg_id, i.current_version, v.kind, v.snapshot_fp FROM %i i
				 JOIN %i v ON v.blyg_id = i.blyg_id AND v.version = i.current_version WHERE i.origin_key = %s',
				Schema::table( 'items' ),
				Schema::table( 'versions' ),
				PostTypeSource::ORIGIN
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$ledger[ $row['source_key'] ] = $row;
		}

		$public = array();
		$types  = get_post_types_by_support( PostTypeSource::SUPPORT );
		if ( $types ) {
			$ids = get_posts(
				array(
					'post_type'      => $types,
					'post_status'    => 'publish',
					'has_password'   => false,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			foreach ( $ids as $id ) {
				if ( PostTypeSource::is_public( get_post( $id ) ) ) {
					$public[ (int) $id ] = true;
				}
			}
		}

		foreach ( array_keys( $public ) as $id ) {
			$row = isset( $ledger[ PostTypeSource::key( $id ) ] ) ? $ledger[ PostTypeSource::key( $id ) ] : null;
			if ( ! $row ) {
				$this->say( 'problem', "post {$id} is public but has no blyg item (run the backfill, or save the post)" );
			} elseif ( 'withdrawn' === $row['kind'] ) {
				$this->say( 'problem', "post {$id} is public but its item {$row['blyg_id']} is withdrawn (save the post to return it)" );
			}
		}

		$live  = 0;
		$drift = 0;
		foreach ( $ledger as $key => $row ) {
			if ( 'withdrawn' === $row['kind'] || ! preg_match( '/^post:(\d+)$/', $key, $m ) ) {
				continue;
			}
			++$live;
			$id = (int) $m[1];
			if ( ! isset( $public[ $id ] ) ) {
				// A post whose type was unticked after it was published stays on the blyg while it
				// stays public; it simply gets no new versions. That is not drift.
				$post = get_post( $id );
				if ( $post && PostTypeSource::is_public( $post, false ) ) {
					continue;
				}
				$this->say( 'problem', "item {$row['blyg_id']} (post {$id}) is live but the post is not public (save or delete the post to withdraw it)" );
				continue;
			}
			// Compared as the ledger compares: the snapshot as it would be handed over, through the
			// same filter, and every published field of it, not the Markdown hash alone. One post
			// that cannot be rendered is reported and the rest are still checked.
			try {
				$snapshot = $posts->snapshot( get_post( $id ) );
				if ( $snapshot ) {
					/** This filter is documented in src/Ledger/Ledger.php */
					$snapshot = Snapshot::from_array( (array) apply_filters( 'soapbox_blyg_snapshot', $snapshot, PostTypeSource::ORIGIN, $key ) );
				}
			} catch ( \Throwable $e ) {
				$this->say( 'warning', "post {$id} ({$row['blyg_id']}) could not be rendered: " . $e->getMessage() );
				continue;
			}
			// The stored fingerprint, or for a version from before there was one, the row's own.
			$stored = ! empty( $row['snapshot_fp'] ) ? $row['snapshot_fp'] : Snapshot::fingerprint_of( (array) Plugin::instance()->ledger->version_row( $row['blyg_id'], (int) $row['current_version'] ) );
			if ( $snapshot && $snapshot->fingerprint() !== $stored ) {
				++$drift;
				$next = (int) $row['current_version'] + 1;
				$this->say( 'warning', "post {$id} ({$row['blyg_id']} v{$row['current_version']}) renders differently now; its next save will create v{$next}" );
			}
		}
		$this->say( 'log', sprintf( '  %d public post(s), %d live item(s), %d would change on resave', count( $public ), $live, $drift ) );
	}
}
