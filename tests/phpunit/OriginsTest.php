<?php
/**
 * Origin registration and the mount lock.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Tests;

use InvalidArgumentException;
use SoapboxBlyg\Origins\Registry;
use SoapboxBlyg\Plugin;
use SoapboxBlyg\Settings;

class OriginsTest extends TestCase {

	public function test_the_posts_origin_is_registered_from_settings_with_site_defaults() {
		$origin = Plugin::instance()->registry->get( 'posts' );
		$this->assertSame( '/blyg/', $origin->path );
		$this->assertSame( 'templated', $origin->layout, 'new installs are templated' );
		$this->assertSame( home_url( '/blyg/' ), $origin->url() );
		$this->assertSame( get_bloginfo( 'name' ), $origin->title );
		$this->assertArrayNotHasKey( 'bio', $origin->author, 'an empty bio is left out' );
	}

	public function test_a_site_with_publishing_off_registers_nothing() {
		update_option( Settings::OPTION, array( 'enabled' => false ) );
		$fired = 0;
		add_action(
			'soapbox_blyg_register_origins',
			static function () use ( &$fired ) {
				++$fired;
			}
		);
		$this->reset_registry();
		$this->assertSame( array(), Plugin::instance()->registry->all() );
		$this->assertSame( 0, $fired, 'other sources are not asked either' );
	}

	/**
	 * @dataProvider bad_registrations
	 */
	public function test_bad_registrations_throw( string $key, string $path ) {
		$this->expectException( InvalidArgumentException::class );
		soapbox_blyg_register_origin( $key, array( 'path' => $path ) );
	}

	public function bad_registrations(): array {
		return array(
			'uppercase key'     => array( 'Notes', '/notes/' ),
			'no trailing slash' => array( 'notes', '/notes' ),
			'site root'         => array( 'notes', '/' ),
			'wp-admin'          => array( 'notes', '/wp-admin/blyg/' ),
			'rest prefix'       => array( 'notes', '/wp-json/' ),
			'core feed'         => array( 'notes', '/feed/' ),
			'taken mount'       => array( 'notes', '/blyg/' ),
		);
	}

	public function test_a_nested_mount_is_allowed() {
		soapbox_blyg_register_origin( 'deep', array( 'path' => '/blyg/deep/' ) );
		$this->assertTrue( Plugin::instance()->registry->has( 'deep' ) );
	}

	public function test_discovery_order_is_priority_then_registration() {
		soapbox_blyg_register_origin( 'b', array( 'path' => '/b/', 'priority' => 5 ) );
		soapbox_blyg_register_origin( 'a', array( 'path' => '/a/', 'priority' => 5 ) );
		$this->assertSame( array( 'b', 'a', 'posts' ), array_keys( Plugin::instance()->registry->all() ) );
	}

	public function test_an_origin_is_unlocked_until_its_first_item() {
		$this->register_notes();
		$registry = Plugin::instance()->registry;
		$this->assertFalse( $registry->is_locked( 'notes' ) );

		soapbox_blyg_publish( 'notes', 'draft', null );
		$this->assertFalse( $registry->is_locked( 'notes' ), 'a draft is not an item' );

		soapbox_blyg_publish( 'notes', 'a', $this->fragment() );
		$this->assertTrue( $registry->is_locked( 'notes' ) );
		$this->assertSame( '/notes/', $registry->locks()['notes']['path'] );
		$this->assertSame( 'fixed', $registry->locks()['notes']['layout'] );
	}

	public function test_a_locked_mount_and_layout_overrule_a_later_registration() {
		$this->register_notes();
		soapbox_blyg_publish( 'notes', 'a', $this->fragment() );

		// Another plugin, a filter or an edited setting now asks for something else.
		$this->reset_registry();
		$this->register_notes( array( 'path' => '/moved/', 'layout' => 'templated' ) );

		$origin = Plugin::instance()->registry->get( 'notes' );
		$this->assertSame( '/notes/', $origin->path, 'published URLs do not move' );
		$this->assertSame( 'fixed', $origin->layout );
		$this->assertSame( array( 'notes' => '/moved/' ), Plugin::instance()->registry->conflicts() );
	}

	public function test_a_lock_survives_withdrawal_of_every_item() {
		$this->register_notes();
		soapbox_blyg_publish( 'notes', 'a', $this->fragment() );
		soapbox_blyg_publish( 'notes', 'a', null );
		$this->assertTrue( Plugin::instance()->registry->is_locked( 'notes' ), 'a withdrawn item is still served, forever' );
	}

	public function test_the_posts_mount_follows_settings_until_locked_and_not_after() {
		update_option( Settings::OPTION, array( 'mount' => '/writing/', 'layout' => 'fixed' ) );
		$this->reset_registry();
		$this->assertSame( '/writing/', Plugin::instance()->registry->get( 'posts' )->path );

		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->assertNotNull( soapbox_blyg_get_status( 'posts', 'post:' . $post ) );

		update_option( Settings::OPTION, array( 'mount' => '/elsewhere/', 'layout' => 'templated' ) );
		$this->reset_registry();
		$origin = Plugin::instance()->registry->get( 'posts' );
		$this->assertSame( '/writing/', $origin->path );
		$this->assertSame( 'fixed', $origin->layout );
	}

	public function test_the_origin_args_filter_applies() {
		add_filter(
			'soapbox_blyg_origin_args',
			static function ( $args, $key ) {
				if ( 'notes' === $key ) {
					$args['title'] = 'Filtered';
				}
				return $args;
			},
			10,
			2
		);
		$this->register_notes();
		$this->assertSame( 'Filtered', Plugin::instance()->registry->get( 'notes' )->title );
	}

	public function test_path_error_names_the_problem() {
		$this->assertNull( Registry::path_error( '/blyg/' ) );
		$this->assertNull( Registry::path_error( '/ai/corpus/' ) );
		$this->assertNotNull( Registry::path_error( 'blyg/' ) );
		$this->assertNotNull( Registry::path_error( '/a b/' ) );
		$this->assertNotNull( Registry::path_error( '/wp-content/x/' ) );
		// Paths that would take an address away from WordPress, or never be reached.
		foreach ( array( '/2024/', '/2024/05/', '/1/', '/robots.txt/', '/sitemap.xml/', '/wp-sitemap.xml/', '/sitemap-posts.xml/', '/../', '/blyg/../', '/./', '/.../x/', '/feed/', '/category/', '/tag/', '/index.php/', '/xmlrpc.php/', '/.well-known/', '/favicon.ico/' ) as $path ) {
			$this->assertNotNull( Registry::path_error( $path ), $path );
		}
		foreach ( array( '/blyg2024/', '/notes/2024/', '/v1.2/', '/my.blyg/' ) as $path ) {
			$this->assertNull( Registry::path_error( $path ), $path );
		}
	}

	public function test_a_lost_lock_is_put_back_from_the_origins_first_item() {
		$this->register_notes( array( 'path' => '/notes/', 'layout' => 'fixed' ) );
		soapbox_blyg_publish( 'notes', 'a', $this->fragment() );
		$this->assertTrue( Plugin::instance()->registry->is_locked( 'notes' ) );

		// The option write was lost: another origin's first item overwrote the map, or the process
		// died after the item was committed.
		delete_option( Registry::LOCKS_OPTION );
		wp_cache_flush();
		$this->assertFalse( Plugin::instance()->registry->is_locked( 'notes' ) );

		// On the next request the origin is registered somewhere else. Its item says where it was.
		$this->reset_registry();
		$this->register_notes( array( 'path' => '/moved/', 'layout' => 'templated' ) );
		$origin = Plugin::instance()->registry->get( 'notes' );
		$this->assertSame( array( '/notes/', 'fixed' ), array( $origin->path, $origin->layout ) );
		$this->assertTrue( Plugin::instance()->registry->is_locked( 'notes' ) );
		$this->assertArrayHasKey( 'notes', Plugin::instance()->registry->conflicts() );
	}

	public function test_a_failed_first_item_leaves_no_lock_behind() {
		global $wpdb;
		$this->register_notes();
		$table  = \SoapboxBlyg\Ledger\Schema::table( 'versions' );
		$breaks = static function ( $query ) use ( $table ) {
			return preg_match( '/^\s*INSERT\b/i', $query ) && false !== strpos( $query, $table ) ? 'INSERT INTO soapbox_blyg_no_such_table VALUES (1)' : $query;
		};
		add_filter( 'query', $breaks );
		$wpdb->suppress_errors( true );
		try {
			soapbox_blyg_publish( 'notes', 'a', $this->fragment() );
			$this->fail( 'the publish should have failed' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'ledger write failed', $e->getMessage() );
		}
		remove_filter( 'query', $breaks );
		$wpdb->suppress_errors( false );
		$this->assertSame( 0, Plugin::instance()->ledger->count_items( 'notes' ), 'the item row went with the transaction' );
		$this->assertFalse( Plugin::instance()->registry->is_locked( 'notes' ) );
	}
}
