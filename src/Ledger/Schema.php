<?php
/**
 * The ledger's tables.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's custom tables.
 *
 * Custom tables rather than a post type with revisions, because an item must answer 200 forever,
 * including after its source post is deleted, and revisions are prunable and die with their
 * parent. The tables use the site's own prefix, so every site of a network keeps its own ledger.
 *
 * Nothing here drops or truncates a table. The ledger is the only record of promises already made
 * to readers; removing it is uninstall.php's job, and only when the site owner opted in.
 */
class Schema {

	/**
	 * Bump when a CREATE TABLE statement changes. dbDelta only ever adds columns and indexes, so a
	 * bump is safe on a ledger that already holds data.
	 */
	const VERSION = '5';

	const VERSION_OPTION = 'soapbox_blyg_db_version';

	/**
	 * "{version}:{problem}:{first failed}:{last tried}" (Unix times) for an install that failed,
	 * or '' for none. Kept as an
	 * empty autoloaded option when there is nothing to say, so that asking costs no query.
	 */
	const PROBLEM_OPTION = 'soapbox_blyg_schema_problem';

	/**
	 * Columns added since the first schema, which an upgrade has to have made.
	 */
	const REQUIRED_COLUMNS = array(
		'items'    => array( 'source_fp', 'mount', 'layout' ),
		'versions' => array( 'pinned_by', 'snapshot_fp' ),
		'mentions' => array( 'client', 'lease', 'next_try', 'deferrals' ),
		'repairs'  => array( 'next_try' ),
	);

	/**
	 * Why the last install() failed: '' (it did not), 'tables' (they could not be created) or
	 * 'engine' (a table is not InnoDB).
	 *
	 * @var string
	 */
	public static $problem = '';

	/**
	 * A table's full name on the current site.
	 *
	 * @param string $which items, versions, mentions, repairs or counters.
	 */
	public static function table( string $which ): string {
		global $wpdb;
		return $wpdb->prefix . str_replace( '-', '_', SOAPBOX_BLYG_SLUG ) . '_' . $which;
	}

	/**
	 * Every table this plugin owns on the current site.
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		return array( self::table( 'items' ), self::table( 'versions' ), self::table( 'mentions' ), self::table( 'repairs' ), self::table( 'counters' ) );
	}

	/**
	 * Installs or upgrades the tables when the stored schema version is behind. Costs one
	 * autoloaded option read when it is current.
	 */
	public static function maybe_install(): void {
		global $wpdb;
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}
		// An install that failed is not tried again on every request. A wrong storage engine will
		// not cure itself, so that waits for the plugin to be activated again. Tables or columns
		// that could not be made might be a passing fault, so that is tried again, at most every ten
		// minutes whoever is asking: an admin screen that re-ran the install on every page load
		// would be no kinder to the database than a visitor doing it. The command line, and
		// activating the plugin, are someone asking for it to be tried now.
		$problem = self::blocked();
		if ( 'engine' === $problem ) {
			return;
		}
		$asked = defined( 'WP_CLI' ) && WP_CLI;
		if ( 'tables' === $problem && ! $asked && time() - self::problem_time() < 10 * MINUTE_IN_SECONDS ) {
			return;
		}
		// One request at a time. Another that arrives meanwhile carries on with the old schema
		// for this request and finds the new one on its next.
		$mutex = 'soapbox_blyg:install:' . md5( $wpdb->prefix );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a named lock, not waited for.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $mutex ) ) ) {
			return;
		}
		try {
			self::install();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- releasing the lock taken above.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $mutex ) );
		}
	}

	/**
	 * When the recorded problem was recorded, as a Unix time; 0 if none is.
	 */
	private static function problem_time(): int {
		$parts = explode( ':', (string) get_option( self::PROBLEM_OPTION, '' ) );
		return isset( $parts[3] ) ? (int) $parts[3] : ( isset( $parts[2] ) ? (int) $parts[2] : 0 );
	}

	/**
	 * Records that this version could not be installed, and why. The time it first failed is
	 * kept; the time of this attempt is what the next one waits on.
	 *
	 * @param string $problem engine or tables.
	 */
	private static function record_problem( string $problem ): void {
		self::$problem = $problem;
		$parts         = explode( ':', (string) get_option( self::PROBLEM_OPTION, '' ) );
		$first         = isset( $parts[2] ) && self::problem_key() === self::VERSION . ':' . $problem ? (int) $parts[2] : time();
		update_option( self::PROBLEM_OPTION, self::VERSION . ':' . $problem . ':' . $first . ':' . time(), true );
	}

	/**
	 * What is wrong, without when: "{version}:{problem}", or ''. What a person dismisses is this,
	 * so a notice they have dismissed does not come back each time the install is tried again.
	 */
	public static function problem_key(): string {
		$parts = explode( ':', (string) get_option( self::PROBLEM_OPTION, '' ) );
		return isset( $parts[1] ) ? $parts[0] . ':' . $parts[1] : '';
	}

	/**
	 * Why this version of the schema could not be installed on this site, or '' if nothing is
	 * known against it. While it says anything the plugin registers no origins: a ledger that
	 * cannot be trusted to write atomically is not written to at all.
	 *
	 * @return string '', 'engine' (a table is not InnoDB) or 'tables' (a table or a column could
	 *                not be made).
	 */
	public static function blocked(): string {
		$parts = explode( ':', (string) get_option( self::PROBLEM_OPTION, '' ) );
		return isset( $parts[1] ) && self::VERSION === $parts[0] ? (string) $parts[1] : '';
	}

	/**
	 * Runs dbDelta for all three tables, and records the version only if they all exist afterwards,
	 * so a failed install is retried rather than remembered as done.
	 */
	public static function install(): bool {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$items    = self::table( 'items' );
		$versions = self::table( 'versions' );
		$mentions = self::table( 'mentions' );
		$repairs  = self::table( 'repairs' );
		$counters = self::table( 'counters' );

		/*
		 * dbDelta is particular: one column per line, two spaces after PRIMARY KEY, KEY not INDEX.
		 *
		 * `generated` is backticked because it is a reserved word in MySQL 8. The column is named
		 * after the protocol field, so the name stays and the quoting follows it everywhere.
		 *
		 * versions is append-only. The one change ever made to a row is pinned 0 -> 1 together with
		 * pinned_at, which records when the promise was made and gives the item document an honest
		 * Last-Modified: a pin changes the document without a publish event.
		 *
		 * versions.permalink is the item's page as it stood at that version (spec §5.8). It is
		 * indexed because a Webmention names its target by page, and a withdrawn item's page is
		 * found by URL.
		 *
		 * mentions holds pointers only: no content from the source is ever stored (spec §15.4).
		 *
		 * items.source_fp is the source's fingerprint when the ledger last agreed with it, and
		 * items.mount and items.layout are where the origin was mounted when the item was made: the
		 * origin's lock can be rebuilt from them. versions.snapshot_fp covers everything a version
		 * publishes; content_hash, which goes on the wire, covers the Markdown alone.
		 *
		 * repairs is work that failed and is owed: a publish or a withdrawal that could not be
		 * written. counters holds rate-limit counts, one row per scope, key and hour, so that an
		 * increment is one atomic statement.
		 */
		dbDelta(
			"CREATE TABLE {$items} (
  blyg_id char(26) NOT NULL,
  origin_key varchar(32) NOT NULL,
  source_key varchar(191) NOT NULL,
  created char(20) NOT NULL,
  current_version int(10) unsigned NOT NULL,
  updated char(20) NOT NULL,
  source_fp char(64) NULL,
  mount varchar(191) NULL,
  layout varchar(16) NULL,
  PRIMARY KEY  (blyg_id),
  UNIQUE KEY origin_source (origin_key,source_key),
  KEY origin_updated (origin_key,updated)
) ENGINE=InnoDB {$charset};
CREATE TABLE {$versions} (
  blyg_id char(26) NOT NULL,
  version int(10) unsigned NOT NULL,
  at char(20) NOT NULL,
  kind varchar(16) NOT NULL,
  note text NULL,
  pinned tinyint(1) NOT NULL DEFAULT 0,
  pinned_at char(20) NULL,
  pinned_by bigint(20) unsigned NULL,
  title text NOT NULL,
  permalink text NOT NULL,
  content_md longtext NOT NULL,
  content_html longtext NOT NULL,
  content_hash char(71) NOT NULL,
  media longtext NOT NULL,
  transclusions longtext NULL,
  `generated` longtext NULL,
  author longtext NOT NULL,
  snapshot_fp char(64) NULL,
  PRIMARY KEY  (blyg_id,version),
  KEY at (at),
  KEY permalink (permalink(191))
) ENGINE=InnoDB {$charset};
CREATE TABLE {$mentions} (
  mention_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  origin_key varchar(32) NOT NULL,
  target_id char(26) NOT NULL,
  target_url text NOT NULL,
  source_url text NOT NULL,
  pair_hash char(64) NOT NULL,
  source_host varchar(191) NOT NULL,
  client char(32) NOT NULL DEFAULT '',
  status varchar(16) NOT NULL,
  relation varchar(16) NULL,
  source_origin text NULL,
  source_id char(26) NULL,
  source_version int(10) unsigned NULL,
  source_kind varchar(16) NULL,
  source_author longtext NULL,
  source_page text NULL,
  received_at char(20) NOT NULL,
  verified_at char(20) NULL,
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  reason varchar(64) NULL,
  lease char(20) NULL,
  next_try char(20) NULL,
  deferrals tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (mention_id),
  UNIQUE KEY pair_hash (pair_hash),
  KEY received (received_at),
  KEY source_host (source_host,received_at),
  KEY client (client,received_at),
  KEY target (target_id,status),
  KEY status (status,received_at)
) ENGINE=InnoDB {$charset};
CREATE TABLE {$repairs} (
  origin_key varchar(32) NOT NULL,
  source_key varchar(191) NOT NULL,
  at char(20) NOT NULL,
  tries int(10) unsigned NOT NULL DEFAULT 1,
  next_try char(20) NOT NULL DEFAULT '',
  message text NOT NULL,
  PRIMARY KEY  (origin_key,source_key)
) ENGINE=InnoDB {$charset};
CREATE TABLE {$counters} (
  scope varchar(16) NOT NULL,
  k varchar(64) NOT NULL,
  bucket int(10) unsigned NOT NULL,
  n int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (scope,k,bucket),
  KEY bucket (bucket)
) ENGINE=InnoDB {$charset};"
		);

		// dbDelta adds and never removes. A column from an earlier version that nothing reads any
		// more (the source's network, once used for a limit that is now counted another way) is
		// taken out here, so the table is what this file says it is.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- schema change on the plugin's own table.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $mentions, 'source_group' ) ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN source_group', $mentions ) );
		}
		// phpcs:enable

		self::$problem = '';
		foreach ( self::tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check on our own tables.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				self::record_problem( 'tables' );
				return false;
			}
			// Every write to the ledger is a transaction: an item and its version, or a version and
			// the item's pointer to it, happen together or not at all. On a storage engine without
			// transactions (MyISAM) they would not, and nothing would say so.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check on our own tables.
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $status && isset( $status->Engine ) && 'innodb' !== strtolower( (string) $status->Engine ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL's column name.
				self::record_problem( 'engine' );
				return false;
			}
		}
		// dbDelta reports nothing when an ALTER fails. The columns this version added are looked
		// for by name before the version is recorded as installed.
		foreach ( self::REQUIRED_COLUMNS as $which => $columns ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check on our own tables.
			$have = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', self::table( $which ) ) );
			if ( array_diff( $columns, $have ) ) {
				self::record_problem( 'tables' );
				return false;
			}
		}
		// Version 5 changed what a post's fingerprint is made from. One stored by an earlier version
		// would never match again, and a pin would be refused for every existing item until its
		// post was saved. Cleared, an item is simply one whose source has not been fingerprinted
		// yet, which the next save or backfill puts right.
		if ( (int) get_option( self::VERSION_OPTION, 0 ) < 5 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a one-time change to the plugin's own table.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET source_fp = NULL WHERE source_fp IS NOT NULL', $items ) );
		}
		update_option( self::PROBLEM_OPTION, '', true );
		update_option( self::VERSION_OPTION, self::VERSION, true );
		return true;
	}

	/**
	 * A deleted site's tables go with it: the site, and so every URL it promised, is gone.
	 *
	 * @param string[] $tables Tables WordPress will drop for the site.
	 * @return string[]
	 */
	public static function drop_with_site( $tables ) {
		return array_merge( (array) $tables, self::tables() );
	}
}
