<?php
/**
 * Stored mentions.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Mentions;

use SoapboxBlyg\Ledger\Ledger;
use SoapboxBlyg\Ledger\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The mentions table: one row per (source, target) pair ever accepted.
 *
 * A row is a pointer. No content from the source is ever stored (spec §15.4): a verified mention
 * records which item at which origin referenced which of ours, and how. The row is kept when a
 * mention stops verifying, with its status changed, so that a responder who withdraws and later
 * republishes is recognised rather than treated as new.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own table; counts must be current, since they are the rate limit.
 */
class Store {

	/**
	 * How long, in seconds, a claim waits for the lock that keeps two from being recorded at once.
	 */
	const LOCK_WAIT = 5;

	/**
	 * How many days a mention that never verified is kept before it is pruned.
	 */
	const FAILED_KEPT_DAYS = 30;

	const PENDING  = 'pending';
	const VERIFIED = 'verified';
	const FAILED   = 'failed';
	const GONE     = 'gone';

	/**
	 * The key that makes a re-sent pair the same row.
	 *
	 * @param string $source Source URL.
	 * @param string $target Target URL.
	 */
	public static function pair_hash( string $source, string $target ): string {
		return hash( 'sha256', $source . "\n" . $target );
	}

	/**
	 * Runs the check-then-insert that accepts a claim under a named lock, so that two requests
	 * arriving together cannot both see room under a cap and both take it.
	 *
	 * @param callable $work The check and the insert.
	 * @return mixed Whatever $work returns, or null if the lock could not be taken.
	 */
	public function with_lock( callable $work ) {
		global $wpdb;
		$lock = 'soapbox_blyg:mentions:' . md5( $wpdb->prefix );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_WAIT ) ) ) {
			return null;
		}
		try {
			return $work();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Takes one from a limit, if there is one left: a count per scope, key and hour, raised by a
	 * single statement that only raises it while it is under the limit. Two requests asking at
	 * once cannot both be given the last one, and what is counted is each asking, not each row
	 * that happens to exist afterwards.
	 *
	 * @param string $scope What is being limited (client, host, origin, group, outbound).
	 * @param string $key   Who or what it is counted against.
	 * @param int    $limit How many an hour.
	 * @return bool Whether this one is within the limit.
	 */
	public function take( string $scope, string $key, int $limit ): bool {
		global $wpdb;
		if ( $limit <= 0 ) {
			return false;
		}
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i ( scope, k, bucket, n ) VALUES ( %s, %s, %d, 1 ) ON DUPLICATE KEY UPDATE n = IF( n < %d, n + 1, n )',
				Schema::table( 'counters' ),
				$scope,
				md5( $key ),
				(int) floor( time() / HOUR_IN_SECONDS ),
				$limit
			)
		);
		// One row for an insert, two for a count that was raised, none for one left as it was.
		return $wpdb->rows_affected > 0;
	}

	/**
	 * Claims waiting to be verified: all of them, and those one client sent.
	 *
	 * @param string $client Receiver::client(), or ''.
	 * @return array { pending, client_pending }
	 */
	public function waiting( string $client = '' ): array {
		global $wpdb;
		$table = Schema::table( 'mentions' );
		// A claim that has been put off because its destination is busy is not waiting on this
		// site: it would otherwise hold a place for hours that somebody else's claim could use.
		$now = Ledger::now();
		return array(
			'pending'        => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s AND ( deferrals = 0 OR next_try IS NULL OR next_try <= %s )', $table, self::PENDING, $now ) ),
			'client_pending' => '' === $client ? 0 : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE client = %s AND status = %s AND ( deferrals = 0 OR next_try IS NULL OR next_try <= %s )', $table, $client, self::PENDING, $now ) ),
		);
	}

	/**
	 * Takes a pending mention for one worker: a lease, and an attempt counted, in one statement
	 * that succeeds for one worker only. A lease that has run out (its worker died) can be taken
	 * again, while there are attempts left.
	 *
	 * @param int $id           Mention id.
	 * @param int $max_attempts How many attempts a claim gets.
	 * @param int $seconds      How long the lease lasts.
	 * @return array|null { lease: to be given back with the outcome, attempts: the count this
	 *                    claim brought it to }; null if the job is not this worker's, or not yet due.
	 */
	public function claim( int $id, int $max_attempts, int $seconds ) {
		global $wpdb;
		$now   = Ledger::now();
		$lease = gmdate( 'Y-m-d\TH:i:s\Z', time() + $seconds );
		$taken = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET lease = %s, attempts = attempts + 1 WHERE mention_id = %d AND status = %s AND attempts < %d AND ( lease IS NULL OR lease < %s ) AND ( next_try IS NULL OR next_try <= %s )',
				Schema::table( 'mentions' ),
				$lease,
				$id,
				self::PENDING,
				$max_attempts,
				$now,
				$now
			)
		);
		if ( 1 !== $taken ) {
			return null;
		}
		// Read back, not worked out from what was read before the claim: another worker's attempt
		// may have come between.
		return array(
			'lease'    => $lease,
			'attempts' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT attempts FROM %i WHERE mention_id = %d', Schema::table( 'mentions' ), $id ) ),
		);
	}

	/**
	 * Fails a pending mention that has used its attempts and that no worker holds: one whose
	 * workers died mid-fetch.
	 *
	 * @param int $id           Mention id.
	 * @param int $max_attempts How many attempts a claim gets.
	 */
	public function give_up( int $id, int $max_attempts ): bool {
		global $wpdb;
		return 1 === $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, reason = %s, lease = NULL WHERE mention_id = %d AND status = %s AND attempts >= %d AND ( lease IS NULL OR lease < %s )',
				Schema::table( 'mentions' ),
				self::FAILED,
				'gave-up',
				$id,
				self::PENDING,
				$max_attempts,
				Ledger::now()
			)
		);
	}

	/**
	 * Records a worker's outcome and gives up its lease, if the lease is still its own. A worker
	 * that was overtaken (its lease ran out, or the claim was sent again and starts afresh) writes
	 * nothing.
	 *
	 * @param int    $id     Mention id.
	 * @param string $lease  What claim() returned.
	 * @param array  $fields Column => value.
	 * @return bool Whether the outcome was recorded.
	 */
	public function finish( int $id, string $lease, array $fields ): bool {
		global $wpdb;
		return 1 === $wpdb->update(
			Schema::table( 'mentions' ),
			array( 'lease' => null ) + $fields,
			array(
				'mention_id' => $id,
				'lease'      => $lease,
			)
		);
	}

	/**
	 * Housekeeping: counts from hours gone by, and claims that failed long ago and never once
	 * verified. A mention that did verify, or was later taken back, is a record and stays.
	 *
	 * @param int $failed_days How long a never-verified failure is kept.
	 */
	public function prune( int $failed_days = self::FAILED_KEPT_DAYS ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE bucket < %d', Schema::table( 'counters' ), (int) floor( time() / HOUR_IN_SECONDS ) - 2 ) );
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE status = %s AND verified_at IS NULL AND received_at < %s',
				Schema::table( 'mentions' ),
				self::FAILED,
				gmdate( 'Y-m-d\TH:i:s\Z', time() - $failed_days * DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * A mention by its pair.
	 *
	 * @param string $source Source URL.
	 * @param string $target Target URL.
	 * @return array|null
	 */
	public function by_pair( string $source, string $target ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE pair_hash = %s', Schema::table( 'mentions' ), self::pair_hash( $source, $target ) ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * A mention by id.
	 *
	 * @param int $id Mention id.
	 * @return array|null
	 */
	public function get( int $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE mention_id = %d', Schema::table( 'mentions' ), $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Records a claim as pending. A pair seen before goes back to pending and keeps what it
	 * recorded, including when it last verified. The pair's hash is unique in the table, and the
	 * insert ignores a duplicate, so two requests for one new pair still make one row.
	 *
	 * @param string $origin    Origin key.
	 * @param string $target_id The item the target names.
	 * @param string $source    Source URL.
	 * @param string $target    Target URL.
	 * @param string $client    Who sent the request, for rate limiting, or ''.
	 * @return int The mention id.
	 */
	public function accept( string $origin, string $target_id, string $source, string $target, string $client = '' ): int {
		global $wpdb;
		$table = Schema::table( 'mentions' );
		$host  = Address::normalize_host( (string) wp_parse_url( $source, PHP_URL_HOST ) );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i ( origin_key, target_id, target_url, source_url, pair_hash, source_host, client, status, received_at )
				 VALUES ( %s, %s, %s, %s, %s, %s, %s, %s, %s )',
				$table,
				$origin,
				$target_id,
				$target,
				$source,
				self::pair_hash( $source, $target ),
				substr( $host, 0, 191 ),
				$client,
				self::PENDING,
				Ledger::now()
			)
		);
		$row = $this->by_pair( $source, $target );
		if ( ! $row ) {
			return 0;
		}
		if ( self::PENDING !== $row['status'] ) {
			$wpdb->update(
				$table,
				array(
					'status'      => self::PENDING,
					'received_at' => Ledger::now(),
					'client'      => $client,
					'attempts'    => 0,
					'reason'      => null,
					// Any worker still on the old claim finds its lease gone and records nothing.
					'lease'       => null,
					'next_try'    => null,
					'deferrals'   => 0,
				),
				array( 'mention_id' => (int) $row['mention_id'] )
			);
		}
		return (int) $row['mention_id'];
	}

	/**
	 * Writes fields back to a mention.
	 *
	 * @param int   $id     Mention id.
	 * @param array $fields Column => value.
	 */
	public function update( int $id, array $fields ): void {
		global $wpdb;
		$wpdb->update( Schema::table( 'mentions' ), $fields, array( 'mention_id' => $id ) );
	}

	/**
	 * Pending mentions received before a moment, oldest first: the ones a stalled queue left behind.
	 *
	 * @param string $before ISO 8601 UTC.
	 * @param int    $limit  Maximum to return.
	 * @return int[] Mention ids.
	 */
	public function pending_before( string $before, int $limit ): array {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT mention_id FROM %i WHERE status = %s AND received_at < %s AND ( lease IS NULL OR lease < %s ) AND ( next_try IS NULL OR next_try <= %s ) ORDER BY deferrals DESC, received_at ASC LIMIT %d',
					Schema::table( 'mentions' ),
					self::PENDING,
					$before,
					Ledger::now(),
					Ledger::now(),
					$limit
				)
			)
		);
	}

	/**
	 * Gives up on pending mentions received before a moment: they become failed, reason expired.
	 *
	 * @param string $before ISO 8601 UTC.
	 * @return int How many expired.
	 */
	public function expire_pending( string $before ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, reason = %s WHERE status = %s AND received_at < %s',
				Schema::table( 'mentions' ),
				self::FAILED,
				'expired',
				self::PENDING,
				$before
			)
		);
	}

	/**
	 * Mentions for the admin list, newest first.
	 *
	 * @param array $args status (string, optional), target_id (string, optional), limit, offset.
	 * @return array[]
	 */
	public function query( array $args = array() ): array {
		global $wpdb;
		$status = isset( $args['status'] ) ? (string) $args['status'] : '';
		$target = isset( $args['target_id'] ) ? (string) $args['target_id'] : '';
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				// An empty filter matches everything, which keeps this one fixed statement.
				"SELECT * FROM %i WHERE ( %s = '' OR status = %s ) AND ( %s = '' OR target_id = %s )
				 ORDER BY received_at DESC, mention_id DESC LIMIT %d OFFSET %d",
				Schema::table( 'mentions' ),
				$status,
				$status,
				$target,
				$target,
				isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 50,
				isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0
			),
			ARRAY_A
		);
	}

	/**
	 * How many verified mentions an item has.
	 *
	 * @param string $target_id Item id.
	 */
	public function verified_count( string $target_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE target_id = %s AND status = %s', Schema::table( 'mentions' ), $target_id, self::VERIFIED ) );
	}
}
