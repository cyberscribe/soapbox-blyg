<?php
/**
 * The event table, endcaps, pins and reconcile.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Tests;

use InvalidArgumentException;
use RuntimeException;
use SoapboxBlyg\Ledger\Id;
use SoapboxBlyg\Ledger\Snapshot;
use SoapboxBlyg\Ledger\Verifier;
use SoapboxBlyg\Plugin;

class LedgerTest extends TestCase {

	public function set_up() {
		parent::set_up();
		$this->register_notes();
	}

	/** No item + snapshot: a new item at v1. */
	public function test_first_snapshot_creates_v1() {
		$r = soapbox_blyg_publish( 'notes', 'a', $this->fragment() );
		$this->assertSame( 'created', $r['event'] );
		$this->assertSame( 1, $r['version'] );
		$this->assertTrue( Id::is_valid( $r['id'] ) );
		$this->assertContains( $r['id'][0], str_split( '01234567' ), 'the 130-bit padding makes the first character 0-7' );
	}

	/** No item + null: nothing. Drafts are invisible. */
	public function test_null_for_an_unknown_source_writes_nothing() {
		$r = soapbox_blyg_publish( 'notes', 'a', null );
		$this->assertSame( array( 'id' => null, 'version' => null, 'event' => 'none' ), $r );
		$this->assertSame( 0, Plugin::instance()->ledger->count_items( 'notes' ) );
		$this->assertNull( soapbox_blyg_get_status( 'notes', 'a' ) );
	}

	/** Live + same hash: none, whatever else about the snapshot changed. */
	public function test_the_same_snapshot_is_not_an_event() {
		$first = soapbox_blyg_publish( 'notes', 'a', $this->fragment() );
		$again = soapbox_blyg_publish( 'notes', 'a', $this->fragment() );
		$this->assertSame( 'none', $again['event'] );
		$this->assertSame( 1, $again['version'] );
		$this->assertSame( $first['id'], $again['id'] );

		// The same Markdown under a new title and at a new address is a different publication:
		// "same" means every published field, not the content hash.
		$moved = soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'One.', array( 'title' => 'Renamed', 'permalink' => home_url( '/elsewhere/' ) ) ) );
		$this->assertSame( array( 'bumped', 2 ), array( $moved['event'], $moved['version'] ) );
	}

	/** Live + different hash: vN+1, bumped. */
	public function test_changed_content_bumps() {
		$first = soapbox_blyg_publish( 'notes', 'a', $this->fragment() );
		$next  = soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'Two.' ) );
		$this->assertSame( 'bumped', $next['event'] );
		$this->assertSame( 2, $next['version'] );
		$this->assertSame( $first['id'], $next['id'] );
	}

	/** Live + null: a withdrawal endcap. */
	public function test_null_withdraws_a_live_item_with_an_empty_endcap() {
		$first = soapbox_blyg_publish( 'notes', 'a', $this->fragment() );
		$gone  = soapbox_blyg_publish( 'notes', 'a', null );
		$this->assertSame( 'withdrawn', $gone['event'] );
		$this->assertSame( 2, $gone['version'] );

		$endcap = Plugin::instance()->ledger->version_row( $first['id'], 2 );
		$this->assertSame( 'withdrawn', $endcap['kind'] );
		$this->assertSame( '', $endcap['content_md'] );
		$this->assertSame( '', $endcap['content_html'] );
		$this->assertSame( '[]', $endcap['media'] );
		$this->assertSame( Snapshot::hash_of( '' ), $endcap['content_hash'] );
		$this->assertNull( $endcap['generated'] );
		$this->assertNull( $endcap['transclusions'], 'a fragment endcap has no transclusions key' );
		$this->assertSame( home_url( '/notes/' ), $endcap['permalink'], 'the endcap keeps the page (spec 5.8, 9)' );
		$this->assertSame( '{"name":"Tester"}', $endcap['author'], 'the endcap carries the author of the version it ends' );
	}

	public function test_a_thread_endcap_carries_empty_transclusions() {
		$first = soapbox_blyg_publish( 'notes', 't', $this->thread() );
		soapbox_blyg_publish( 'notes', 't', null );
		$this->assertSame( '[]', Plugin::instance()->ledger->version_row( $first['id'], 2 )['transclusions'] );
	}

	/** Withdrawn + snapshot: returns under the same id, even with the content it had before. */
	public function test_a_withdrawn_item_returns_under_the_same_id() {
		$first = soapbox_blyg_publish( 'notes', 'a', $this->fragment() );
		soapbox_blyg_publish( 'notes', 'a', null );
		$back = soapbox_blyg_publish( 'notes', 'a', $this->fragment() );
		$this->assertSame( 'returned', $back['event'] );
		$this->assertSame( 3, $back['version'] );
		$this->assertSame( $first['id'], $back['id'] );
		$this->assertSame( 'fragment', soapbox_blyg_get_status( 'notes', 'a' )['kind'] );
	}

	/** Withdrawn + null: none. */
	public function test_withdrawing_twice_is_not_an_event() {
		soapbox_blyg_publish( 'notes', 'a', $this->fragment() );
		soapbox_blyg_publish( 'notes', 'a', null );
		$again = soapbox_blyg_publish( 'notes', 'a', null );
		$this->assertSame( 'none', $again['event'] );
		$this->assertSame( 2, $again['version'] );
	}

	public function test_the_hash_is_computed_never_accepted() {
		$r   = soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'One.', array( 'content_hash' => 'sha256:forged' ) ) );
		$row = Plugin::instance()->ledger->version_row( $r['id'], 1 );
		$this->assertSame( 'sha256:' . hash( 'sha256', "# A note\n\nOne.\n" ), $row['content_hash'] );
	}

	public function test_created_is_never_later_than_the_first_event_and_times_never_run_backwards() {
		$r = soapbox_blyg_publish( 'notes', 'a', $this->fragment(), array( 'at' => '2026-01-02T00:00:00Z', 'created' => '2027-01-01T00:00:00Z' ) );
		$this->assertSame( '2026-01-02T00:00:00Z', Plugin::instance()->ledger->item_by_id( $r['id'] )['created'] );

		soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'Two.' ), array( 'at' => '2025-01-01T00:00:00Z' ) );
		$this->assertSame( '2026-01-02T00:00:00Z', Plugin::instance()->ledger->version_row( $r['id'], 2 )['at'], 'a backdated event is clamped' );
	}

	public function test_no_author_is_stored_as_no_assertion() {
		$snapshot = $this->fragment();
		unset( $snapshot['author'] );
		$r = soapbox_blyg_publish( 'notes', 'a', $snapshot );
		$this->assertSame( 'null', Plugin::instance()->ledger->version_row( $r['id'], 1 )['author'] );
	}

	public function test_an_unknown_origin_throws() {
		$this->expectException( InvalidArgumentException::class );
		soapbox_blyg_publish( 'nowhere', 'a', $this->fragment() );
	}

	/**
	 * @dataProvider bad_snapshots
	 */
	public function test_an_invalid_snapshot_throws_and_writes_nothing( array $change ) {
		$snapshot = array_merge( $this->fragment(), $change );
		foreach ( $change as $k => $v ) {
			if ( '__unset' === $v ) {
				unset( $snapshot[ $k ] );
			}
		}
		try {
			soapbox_blyg_publish( 'notes', 'a', $snapshot );
			$this->fail( 'accepted' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 0, Plugin::instance()->ledger->count_items( 'notes' ) );
		}
	}

	public function bad_snapshots(): array {
		return array(
			'unknown kind'                 => array( array( 'kind' => 'note' ) ),
			'transclusions on a fragment'  => array( array( 'transclusions' => array() ) ),
			'thread without transclusions' => array( array( 'kind' => 'thread' ) ),
			'missing content_md'           => array( array( 'content_md' => '__unset' ) ),
			'relative page'                => array( array( 'permalink' => '/notes/' ) ),
			'media without mime'           => array( array( 'media' => array( array( 'url' => 'https://example.org/a.png' ) ) ) ),
			'author not an object'         => array( array( 'author' => 'Tester' ) ),
			'reserved directive'           => array( array( 'content_md' => "# A\n\n![[7c9wk2mhq0v3xj8tn5rzfd41bg@v2]]\n" ) ),
			'invalid utf-8'                => array( array( 'content_md' => "# A\n\n\xC3\x28\n" ) ),
		);
	}

	public function test_pin_is_one_way_and_refuses_endcaps_missing_versions_and_repeats() {
		$r = soapbox_blyg_publish( 'notes', 'a', $this->fragment() );
		soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'Two.' ) );
		soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'Three.' ) );

		soapbox_blyg_pin( $r['id'], 1 ); // Retroactive: an older version, while the item is live.
		$this->assertSame( array( 1 ), soapbox_blyg_get_status( 'notes', 'a' )['pinned'] );
		$this->assertNotEmpty( Plugin::instance()->ledger->version_row( $r['id'], 1 )['pinned_at'] );

		foreach ( array( 1 => 'already pinned', 9 => 'no version' ) as $version => $why ) {
			try {
				soapbox_blyg_pin( $r['id'], $version );
				$this->fail( "pinned: {$why}" );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( $why, $e->getMessage() );
			}
		}

		// Once the item is withdrawn nothing more of it can be pinned: not the endcap, and not an
		// earlier version either, which would put back in public what the author took down.
		soapbox_blyg_publish( 'notes', 'a', null );
		foreach ( array( 2, 3 ) as $version ) {
			try {
				soapbox_blyg_pin( $r['id'], $version );
				$this->fail( "pinned version {$version} of a withdrawn item" );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'withdrawn', $e->getMessage() );
			}
		}
		$this->assertSame( array( 1 ), soapbox_blyg_get_status( 'notes', 'a' )['pinned'], 'what was pinned before stays pinned' );

		// It comes back, and can be pinned again.
		soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'Four.' ) );
		soapbox_blyg_pin( $r['id'], 2 );
		$this->assertSame( array( 1, 2 ), soapbox_blyg_get_status( 'notes', 'a' )['pinned'] );

		foreach ( array( 4 => 'endcap' ) as $version => $why ) {
			try {
				soapbox_blyg_pin( $r['id'], $version );
				$this->fail( "pinned: {$why}" );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( $why, $e->getMessage() );
			}
		}
	}

	public function test_reconcile_publishes_the_set_and_withdraws_what_is_missing() {
		soapbox_blyg_publish( 'notes', 'keep', $this->fragment( 'Keep.' ) );
		soapbox_blyg_publish( 'notes', 'drop', $this->fragment( 'Drop.' ) );
		$counts = soapbox_blyg_reconcile(
			'notes',
			array(
				'keep' => $this->fragment( 'Keep.' ),
				'new'  => $this->fragment( 'New.' ),
			)
		);
		$this->assertSame( array( 'created' => 1, 'bumped' => 0, 'withdrawn' => 1, 'returned' => 0, 'none' => 1 ), $counts );
		$this->assertSame( 'withdrawn', soapbox_blyg_get_status( 'notes', 'drop' )['kind'] );
	}

	public function test_events_fire_the_action_and_none_does_not() {
		$seen = array();
		add_action(
			'soapbox_blyg_event',
			static function ( $event, $id, $version, $origin ) use ( &$seen ) {
				$seen[] = "{$event}:{$version}:{$origin}";
			},
			10,
			4
		);
		soapbox_blyg_publish( 'notes', 'a', $this->fragment() );
		soapbox_blyg_publish( 'notes', 'a', $this->fragment() );
		soapbox_blyg_publish( 'notes', 'a', null );
		$this->assertSame( array( 'created:1:notes', 'withdrawn:2:notes' ), $seen );
	}

	public function test_the_snapshot_filter_runs_before_validation() {
		add_filter(
			'soapbox_blyg_snapshot',
			static function ( $snapshot ) {
				$snapshot['content_md'] = "# Filtered\n";
				return $snapshot;
			}
		);
		$r = soapbox_blyg_publish( 'notes', 'a', $this->fragment() );
		$this->assertSame( "# Filtered\n", Plugin::instance()->ledger->version_row( $r['id'], 1 )['content_md'] );
	}

	public function test_origins_do_not_share_source_keys_or_items() {
		soapbox_blyg_register_origin( 'other', array( 'path' => '/other/', 'layout' => 'fixed' ) );
		$a = soapbox_blyg_publish( 'notes', 'same-key', $this->fragment() );
		$b = soapbox_blyg_publish( 'other', 'same-key', $this->fragment() );
		$this->assertSame( 'created', $b['event'] );
		$this->assertNotSame( $a['id'], $b['id'] );
	}

	public function test_verify_passes_a_clean_ledger_and_catches_a_tampered_row() {
		global $wpdb;
		$r = soapbox_blyg_publish( 'notes', 'a', $this->fragment() );
		soapbox_blyg_publish( 'notes', 'a', null );

		$verifier = new Verifier();
		$this->assertTrue( $verifier->run() );

		$wpdb->update( \SoapboxBlyg\Ledger\Schema::table( 'versions' ), array( 'content_md' => 'edited behind the ledger' ), array( 'blyg_id' => $r['id'], 'version' => 1 ) );
		$verifier = new Verifier();
		$this->assertFalse( $verifier->run() );
		$this->assertStringContainsString( 'content_hash does not match', implode( "\n", $verifier->problems ) );
	}
}
