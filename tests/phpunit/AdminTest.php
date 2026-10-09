<?php
/**
 * Capabilities, the settings sanitiser, the admin REST routes and uninstall.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Tests;

use SoapboxBlyg\Admin\Capabilities;
use SoapboxBlyg\Admin\EditorPanel;
use SoapboxBlyg\Admin\SettingsPage;
use SoapboxBlyg\Admin\SiteHealth;
use SoapboxBlyg\Ledger\Schema;
use SoapboxBlyg\Origins\PostTypeSource;
use SoapboxBlyg\Origins\Registry;
use SoapboxBlyg\Plugin;
use SoapboxBlyg\Settings;
use SoapboxBlyg\Surface\Locations;
use WP_REST_Request;

class AdminTest extends TestCase {

	private function user( string $role ): int {
		return self::factory()->user->create( array( 'role' => $role ) );
	}

	private function rest( string $method, string $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/' . Locations::rest_namespace() . '/admin' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/** N3: the capability map. */
	public function test_manage_maps_to_manage_options() {
		$this->assertTrue( user_can( $this->user( 'administrator' ), Capabilities::MANAGE ) );
		foreach ( array( 'editor', 'author', 'contributor', 'subscriber' ) as $role ) {
			$this->assertFalse( user_can( $this->user( $role ), Capabilities::MANAGE ), $role );
		}
		$this->assertFalse( user_can( 0, Capabilities::MANAGE ) );
	}

	public function test_pinning_needs_edit_and_publish_on_that_post() {
		$author      = $this->user( 'author' );
		$other       = $this->user( 'author' );
		$contributor = $this->user( 'contributor' );
		$editor      = $this->user( 'editor' );
		$own         = self::factory()->post->create( array( 'post_status' => 'publish', 'post_author' => $author ) );
		$draft       = self::factory()->post->create( array( 'post_status' => 'draft', 'post_author' => $contributor ) );

		$this->assertTrue( user_can( $author, Capabilities::PIN, $own ), 'an author may pin their own post' );
		$this->assertFalse( user_can( $other, Capabilities::PIN, $own ), 'not someone else\'s' );
		$this->assertTrue( user_can( $editor, Capabilities::PIN, $own ), 'an editor may pin anyone\'s' );
		$this->assertTrue( user_can( $contributor, 'edit_post', $draft ) );
		$this->assertFalse( user_can( $contributor, Capabilities::PIN, $draft ), 'editing is not enough: pinning is a permanent public promise' );
		$this->assertFalse( user_can( $this->user( 'subscriber' ), Capabilities::PIN, $own ) );

		// An item with no post behind it is a management matter.
		$this->assertFalse( user_can( $editor, Capabilities::PIN ) );
		$this->assertTrue( user_can( $this->user( 'administrator' ), Capabilities::PIN ) );

		// A post type that does not publish cannot be pinned through this capability by an editor.
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$this->assertFalse( user_can( $editor, Capabilities::PIN, $page ) );
	}

	public function test_the_mapping_is_filterable_like_any_meta_capability() {
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap ) {
				return Capabilities::MANAGE === $cap ? array( 'edit_others_posts' ) : $caps;
			},
			20,
			2
		);
		$this->assertTrue( user_can( $this->user( 'editor' ), Capabilities::MANAGE ) );
	}

	public function test_every_admin_route_refuses_the_wrong_user() {
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_author' => $this->user( 'author' ) ) );

		wp_set_current_user( 0 );
		foreach ( array( array( 'GET', '/item', array( 'post' => $post ) ), array( 'POST', '/pin', array( 'post' => $post, 'version' => 1 ) ), array( 'POST', '/backfill', array() ), array( 'POST', '/verify', array() ), array( 'GET', '/health/reachable', array() ), array( 'GET', '/health/edge-cache', array() ) ) as $call ) {
			$this->assertContains( $this->rest( ...$call )->get_status(), array( 401, 403 ), "anonymous {$call[0]} {$call[1]}" );
		}

		wp_set_current_user( $this->user( 'contributor' ) );
		$this->assertSame( 403, $this->rest( 'GET', '/item', array( 'post' => $post ) )->get_status(), 'cannot edit that post' );
		$this->assertSame( 403, $this->rest( 'POST', '/pin', array( 'post' => $post, 'version' => 1 ) )->get_status() );

		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertSame( 200, $this->rest( 'GET', '/item', array( 'post' => $post ) )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', '/backfill' )->get_status(), 'an editor does not manage the blyg' );
		$this->assertSame( 403, $this->rest( 'POST', '/verify' )->get_status() );

		// Every route in the namespace has a permission callback that is not a blanket yes, bar the
		// public documents.
		foreach ( rest_get_server()->get_routes( Locations::rest_namespace() ) as $route => $handlers ) {
			if ( '/' . Locations::rest_namespace() === $route ) {
				continue; // The namespace's own index, which core registers.
			}
			foreach ( $handlers as $handler ) {
				$this->assertIsCallable( $handler['permission_callback'], $route );
				if ( false !== strpos( $route, '/admin/' ) ) {
					$this->assertNotSame( '__return_true', $handler['permission_callback'], $route );
				}
			}
		}
	}

	public function test_the_item_route_describes_the_post_and_pin_pins_once() {
		$admin = $this->user( 'administrator' );
		wp_set_current_user( $admin );
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>One.</p>' ) );
		wp_update_post( array( 'ID' => $post, 'post_content' => '<p>Two.</p>' ) );

		$data = $this->rest( 'GET', '/item', array( 'post' => $post ) )->get_data();
		$this->assertTrue( $data['publishes'] );
		$this->assertTrue( $data['can_pin'] );
		$this->assertSame( 2, $data['item']['version'] );
		$this->assertSame( array( true, true ), array_column( $data['item']['changelog'], 'pinnable' ) );
		$this->assertNull( $data['item']['mentions'], 'no count while the receiver is off' );

		$pinned = $this->rest( 'POST', '/pin', array( 'post' => $post, 'version' => 1 ) );
		$this->assertSame( 200, $pinned->get_status() );
		$this->assertTrue( $pinned->get_data()['item']['changelog'][0]['pinned'] );
		$this->assertStringEndsWith( '/v/1', $pinned->get_data()['item']['changelog'][0]['pin_url'] );
		$this->assertSame( get_userdata( $admin )->display_name, $pinned->get_data()['item']['changelog'][0]['pinned_by'], 'a pin cannot be undone, so who made it is kept' );
		$this->assertNull( $pinned->get_data()['item']['changelog'][1]['pinned_by'] );
		$this->assertSame( (string) $admin, (string) Plugin::instance()->ledger->version_row( $pinned->get_data()['item']['id'], 1 )['pinned_by'] );
		$this->assertSame( 409, $this->rest( 'POST', '/pin', array( 'post' => $post, 'version' => 1 ) )->get_status(), 'already pinned' );

		// Withdrawn: nothing is offered for pinning and nothing can be pinned, the unpinned earlier
		// version included. Pinning it would publish again what was just taken down.
		wp_trash_post( $post );
		$data = $this->rest( 'GET', '/item', array( 'post' => $post ) )->get_data();
		$this->assertSame( 'withdrawn', $data['item']['kind'] );
		$this->assertSame( array( false, false, false ), array_column( $data['item']['changelog'], 'pinnable' ), 'nothing of a withdrawn item is pinnable' );
		$this->assertSame( 409, $this->rest( 'POST', '/pin', array( 'post' => $post, 'version' => 2 ) )->get_status(), 'an earlier version of a withdrawn item' );
		$this->assertSame( 409, $this->rest( 'POST', '/pin', array( 'post' => $post, 'version' => 3 ) )->get_status(), 'an endcap' );
		$this->assertSame( 404, Plugin::instance()->router->respond( Plugin::instance()->registry->get( 'posts' ), 'items/' . $data['item']['id'] . '/v2.json' )['status'] );

		$never = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->assertSame( 404, $this->rest( 'POST', '/pin', array( 'post' => $never, 'version' => 1 ) )->get_status() );
		$this->assertNull( $this->rest( 'GET', '/item', array( 'post' => $never ) )->get_data()['item'] );
	}

	public function test_backfill_runs_in_batches_from_rest_and_a_dry_run_writes_nothing() {
		wp_set_current_user( $this->user( 'administrator' ) );
		$source = Plugin::instance()->posts;
		remove_action( 'wp_after_insert_post', array( $source, 'on_save' ), 10 );
		$ids = self::factory()->post->create_many( 45, array( 'post_status' => 'publish', 'post_content' => '<p>Old post.</p>' ) );
		add_action( 'wp_after_insert_post', array( $source, 'on_save' ), 10, 2 );

		// A dry run steps through with an offset and leaves the ledger empty.
		$offset = 0;
		$rows   = 0;
		do {
			$batch  = $this->rest( 'POST', '/backfill', array( 'dry_run' => true, 'offset' => $offset ) )->get_data();
			$rows  += count( $batch['rows'] );
			$offset = $batch['offset'];
		} while ( $batch['remaining'] > 0 );
		$this->assertSame( 45, $rows );
		$this->assertSame( 45, $batch['total'] );
		$this->assertArrayHasKey( 'md', $batch['rows'][0] );
		$this->assertSame( 0, Plugin::instance()->ledger->count_items( 'posts' ) );

		// A real run consumes its queue.
		$calls = 0;
		do {
			$batch = $this->rest( 'POST', '/backfill', array( 'dry_run' => false ) )->get_data();
			++$calls;
		} while ( $batch['remaining'] > 0 && $calls < 10 );
		$this->assertSame( 3, $calls, '45 posts in batches of 20' );
		$this->assertSame( 45, Plugin::instance()->ledger->count_items( 'posts' ) );
		$this->assertSame( 0, $this->rest( 'POST', '/backfill', array() )->get_data()['total'], 'a second run finds nothing' );
		$this->assertCount( 45, $ids );
	}

	public function test_a_backfill_that_cannot_make_progress_stops() {
		wp_set_current_user( $this->user( 'administrator' ) );
		$source = Plugin::instance()->posts;
		remove_action( 'wp_after_insert_post', array( $source, 'on_save' ), 10 );
		self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );
		add_action( 'wp_after_insert_post', array( $source, 'on_save' ), 10, 2 );
		add_filter(
			'soapbox_blyg_snapshot',
			static function ( $snapshot ) {
				$snapshot['kind'] = 'nonsense';
				return $snapshot;
			}
		);
		$batch = $this->rest( 'POST', '/backfill', array() )->get_data();
		$this->assertSame( 0, $batch['remaining'], 'the screen must not ask again forever' );
		$this->assertSame( 3, $batch['stuck'] );
		$this->assertCount( 3, $batch['failed'] );
	}

	public function test_verify_from_rest_reports_and_stores_the_result() {
		wp_set_current_user( $this->user( 'administrator' ) );
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$result = $this->rest( 'POST', '/verify', array( 'render' => true ) )->get_data();
		$this->assertTrue( $result['passed'] );
		$this->assertSame( array(), $result['problems'] );
		$this->assertStringContainsString( '1 public post(s), 1 live item(s), 0 would change on resave', implode( "\n", $result['log'] ) );
		$this->assertSame( 'good', SiteHealth::test_ledger()['status'] );
	}

	/** E8: the mount lock, as the settings screen meets it. */
	public function test_settings_refuse_to_move_a_published_blyg_and_say_why() {
		global $wp_settings_errors;
		update_option( Settings::OPTION, array( 'mount' => '/writing/', 'layout' => 'fixed' ) );
		$this->assertSame( '/writing/', Settings::get( 'mount' ), 'free to change before anything is published' );
		$this->reset_registry();
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$wp_settings_errors = array();
		update_option( Settings::OPTION, array( 'mount' => '/elsewhere/', 'layout' => 'templated', 'title' => 'New title' ) );
		$this->assertSame( '/writing/', Settings::get( 'mount' ) );
		$this->assertSame( 'fixed', Settings::get( 'layout' ) );
		$this->assertSame( 'New title', Settings::get( 'title' ), 'everything else still saves' );
		$this->assertSame( 'locked', get_settings_errors( Settings::OPTION )[0]['code'] );
	}

	public function test_a_mount_that_collides_with_a_page_or_a_reserved_path_is_refused() {
		global $wp_settings_errors;
		self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'about', 'post_title' => 'About us' ) );

		foreach ( array( '/about/', '/wp-admin/', '/', 'not a path' ) as $bad ) {
			$wp_settings_errors = array();
			update_option( Settings::OPTION, array( 'mount' => $bad ) );
			$this->assertSame( '/blyg/', Settings::get( 'mount' ), $bad );
			$this->assertSame( 'mount', get_settings_errors( Settings::OPTION )[0]['code'], $bad );
		}
		$this->assertStringContainsString( 'About us', (string) SettingsPage::mount_problem( '/about/' ) );
		$this->assertNull( SettingsPage::mount_problem( '/notes/' ) );

		update_option( Settings::OPTION, array( 'mount' => 'Journal' ) );
		$this->assertSame( '/journal/', Settings::get( 'mount' ), 'a bare word is normalised to a mount' );
	}

	public function test_a_mount_error_cannot_carry_markup_from_a_post_title() {
		global $wp_settings_errors;
		wp_set_current_user( $this->user( 'administrator' ) );
		self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'taken', 'post_title' => 'Taken <img src=x onerror=alert(1)> & <b>bold</b>' ) );
		$message = (string) SettingsPage::mount_problem( '/taken/' );
		$this->assertStringNotContainsString( '<img', $message );
		$this->assertStringNotContainsString( '<b>', $message );
		$this->assertStringContainsString( 'Taken', $message );

		$wp_settings_errors = array();
		update_option( Settings::OPTION, array( 'mount' => '/taken/' ) );
		$this->assertStringNotContainsString( '<img', get_settings_errors( Settings::OPTION )[0]['message'] );
	}

	public function test_the_editor_panel_script_and_everything_it_depends_on_are_registered() {
		// The built file lists its dependencies by handle. A handle this version of WordPress does
		// not register makes the script silently not load, and the panel never appears.
		wp_set_current_user( $this->user( 'administrator' ) );
		set_current_screen( 'post' );
		get_current_screen()->post_type = 'post';
		EditorPanel::block_editor_assets();
		set_current_screen( 'front' );

		$scripts = wp_scripts();
		foreach ( array( 'soapbox-blyg-editor-panel', 'soapbox-blyg-tools' ) as $handle ) {
			if ( 'soapbox-blyg-tools' === $handle ) {
				\SoapboxBlyg\Admin\ToolsPage::enqueue( 'tools' );
			}
			$this->assertTrue( wp_script_is( $handle, 'enqueued' ), $handle );
			$this->assertNotEmpty( $scripts->registered[ $handle ]->deps );
			foreach ( $scripts->registered[ $handle ]->deps as $dependency ) {
				$this->assertTrue( wp_script_is( $dependency, 'registered' ), "{$handle} depends on {$dependency}, which this WordPress does not register" );
			}
			// And core agrees it can be printed: every dependency resolves, to any depth.
			$scripts->to_do = array();
			$this->assertTrue( $scripts->all_deps( array( $handle ) ), $handle );
			$this->assertContains( $handle, $scripts->to_do );
			wp_dequeue_script( $handle );
		}
		$this->assertNotContains( 'wp-edit-post', $scripts->registered['soapbox-blyg-editor-panel']->deps, 'the panel comes from wp-editor, as it has since 6.6' );
	}

	public function test_settings_are_sanitised_by_type() {
		update_option(
			Settings::OPTION,
			array(
				'_screen'      => 1,
				'enabled'      => '1',
				'title'        => " My <b>blyg</b>\n",
				'author_bio'   => "Line one\n<script>x</script>Line two",
				'author_links' => "Home | https://example.org/\nBad | javascript:alert(1)\nNo separator\n Mastodon |https://social.example/@me ",
				'post_types'   => array( 'post', 'page', 'attachment', 'nonexistent' ),
				'layout'       => 'sideways',
			)
		);
		$s = Settings::all();
		$this->assertTrue( $s['enabled'] );
		$this->assertFalse( $s['featured_image'], 'on the settings screen an unticked box is off' );
		$this->assertFalse( $s['webmention'] );
		$this->assertSame( 'My blyg', $s['title'] );
		$this->assertStringNotContainsString( '<script>', $s['author_bio'] );
		$this->assertSame(
			array(
				array( 'label' => 'Home', 'url' => 'https://example.org/' ),
				array( 'label' => 'Mastodon', 'url' => 'https://social.example/@me' ),
			),
			$s['author_links']
		);
		$this->assertSame( array( 'post', 'page' ), $s['post_types'] );
		$this->assertSame( 'templated', $s['layout'], 'an unknown layout is ignored' );
	}

	public function test_a_partial_update_from_a_script_does_not_switch_anything_off() {
		update_option( Settings::OPTION, array( 'title' => 'Only this' ) );
		$this->assertTrue( Settings::get( 'enabled' ) );
		$this->assertTrue( Settings::get( 'featured_image' ) );
		$this->assertSame( 'Only this', Settings::get( 'title' ) );
	}

	public function test_site_health_reports_a_moved_origin_plain_permalinks_and_a_failed_save() {
		$this->assertSame( 'good', SiteHealth::test_mount()['status'] );
		$this->assertSame( 'good', SiteHealth::test_permalinks()['status'] );
		$this->assertSame( 'recommended', SiteHealth::test_ledger()['status'], 'never verified' );

		$this->register_notes();
		soapbox_blyg_publish( 'notes', 'a', $this->fragment() );
		$this->reset_registry();
		$this->register_notes( array( 'path' => '/moved/' ) );
		$moved = SiteHealth::test_mount();
		$this->assertSame( 'critical', $moved['status'] );
		$this->assertStringContainsString( '/moved/', $moved['description'] );

		$this->set_permalink_structure( '' );
		$this->assertSame( 'critical', SiteHealth::test_permalinks()['status'] );

		// A save whose publish fails is on the repair queue, and Site Health reads the queue.
		$breaks = static function () {
			throw new \RuntimeException( 'boom' );
		};
		add_filter( 'soapbox_blyg_snapshot', $breaks );
		$owed = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		remove_filter( 'soapbox_blyg_snapshot', $breaks );
		$this->assertStringContainsString( "Post {$owed}", SiteHealth::test_ledger()['description'] );
		$this->assertStringContainsString( 'boom', SiteHealth::test_ledger()['description'] );
		$this->repairs_are_due();
		Plugin::instance()->posts->repair();
		$this->assertStringNotContainsString( "Post {$owed}", SiteHealth::test_ledger()['description'], 'repaired, so no longer reported' );
	}

	/**
	 * Answers Site Health's loopback requests from a table of URL-suffix => response.
	 */
	private function loopback( array $answers ): void {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$answers ) {
				foreach ( $answers as $suffix => $queue ) {
					if ( substr( $url, -strlen( $suffix ) ) === $suffix ) {
						$answer = count( $queue ) > 1 ? array_shift( $answers[ $suffix ] ) : $queue[0];
						return array(
							'response' => array( 'code' => $answer[0], 'message' => '' ),
							'body'     => isset( $answer[2] ) ? $answer[2] : '',
							'headers'  => $answer[1],
							'cookies'  => array(),
						);
					}
				}
				return new \WP_Error( 'http_request_failed', 'unexpected ' . $url );
			},
			10,
			3
		);
	}

	/** E10: a shared cache in front of the site is noticed. */
	public function test_site_health_warns_when_a_shared_cache_holds_the_blyg() {
		$fresh = array( 200, array( 'cache-control' => 'public, max-age=300' ) );

		$this->loopback( array( '/blyg.json' => array( $fresh ), '/feed' => array( $fresh ) ) );
		$this->assertSame( 'good', SiteHealth::test_edge_cache()['status'] );
		remove_all_filters( 'pre_http_request' );

		// The host rewrote the header: thirty days at the edge.
		$this->loopback( array( '/blyg.json' => array( array( 200, array( 'cache-control' => 'public, max-age=300, s-maxage=2592000' ) ) ), '/feed' => array( $fresh ) ) );
		$held = SiteHealth::test_edge_cache();
		$this->assertSame( 'recommended', $held['status'] );
		$this->assertStringContainsString( '/blyg/', $held['description'] );
		remove_all_filters( 'pre_http_request' );

		// A shared cache holding the documents for the five minutes it was given is the plan (B12):
		// Age grows, and stays under 300.
		$ours = 'public, max-age=300, s-maxage=300';
		$this->loopback(
			array(
				'/blyg.json' => array( array( 200, array( 'cache-control' => $ours ) ) ),
				'/feed'      => array( array( 200, array( 'cache-control' => $ours, 'age' => '40' ) ), array( 200, array( 'cache-control' => $ours, 'age' => '42' ) ) ),
			)
		);
		$this->assertSame( 'good', SiteHealth::test_edge_cache()['status'] );
		remove_all_filters( 'pre_http_request' );

		// A copy older than that means the cache did not keep to the lifetime, whatever the header says.
		$this->loopback(
			array(
				'/blyg.json' => array( array( 200, array( 'cache-control' => $ours ) ) ),
				'/feed'      => array( array( 200, array( 'cache-control' => $ours ) ), array( 200, array( 'cache-control' => $ours, 'age' => '86400' ) ) ),
			)
		);
		$this->assertSame( 'recommended', SiteHealth::test_edge_cache()['status'] );
		remove_all_filters( 'pre_http_request' );

		// The host appended a second header after ours (Breeze's Browser Cache on Cloudways). Ours
		// comes first, and the first is what a cache reads (RFC 9111 4.2.1): this is what rc4
		// looks like on that host, and it is good.
		foreach ( array( $ours . ', s-maxage=2592000', array( $ours, 's-maxage=2592000' ), array( $ours, 'max-age=0, s-maxage=2592000' ) ) as $appended ) {
			$this->loopback( array( '/blyg.json' => array( array( 200, array( 'cache-control' => $appended ) ) ), '/feed' => array( array( 200, array( 'cache-control' => $ours ) ) ) ) );
			$this->assertSame( 'good', SiteHealth::test_edge_cache()['status'], wp_json_encode( $appended ) );
			remove_all_filters( 'pre_http_request' );
		}

		// Ours is not there, or not first: the host's is what a cache follows. The first is what
		// that host sends when a filter has dropped the plugin's s-maxage.
		foreach ( array( array( 'public, max-age=300', 's-maxage=2592000' ), array( 's-maxage=2592000', $ours ) ) as $theirs ) {
			$this->loopback( array( '/blyg.json' => array( array( 200, array( 'cache-control' => $theirs ) ) ), '/feed' => array( $fresh ) ) );
			$added = SiteHealth::test_edge_cache();
			$this->assertSame( 'recommended', $added['status'] );
			$this->assertStringContainsString( 's-maxage=2592000', $added['description'] );
			$this->assertStringContainsString( 'ask your host', $added['description'] );
			$this->assertStringContainsString( 'soapbox_blyg_cache_control', $added['description'] );
			remove_all_filters( 'pre_http_request' );
		}
	}

	public function test_site_health_follows_the_manifest_and_names_a_locked_rest_api() {
		$manifest = Plugin::instance()->documents->manifest( Plugin::instance()->registry->get( 'posts' ) )[0];
		$ok       = array( 200, array(), '' );

		$this->loopback( array( '/blyg.json' => array( array( 200, array(), $manifest ) ), '/feed' => array( $ok ), '/items' => array( $ok ) ) );
		$this->assertSame( 'good', SiteHealth::test_reachable()['status'] );
		remove_all_filters( 'pre_http_request' );

		$this->loopback( array( '/blyg.json' => array( array( 200, array(), $manifest ) ), '/feed' => array( array( 401, array(), '' ) ), '/items' => array( $ok ) ) );
		$locked = SiteHealth::test_reachable();
		$this->assertSame( 'critical', $locked['status'] );
		$this->assertStringContainsString( 'security plugin', $locked['description'] );
		remove_all_filters( 'pre_http_request' );

		$this->loopback( array( '/blyg.json' => array( array( 404, array(), 'Not found' ) ) ) );
		$this->assertSame( 'critical', SiteHealth::test_reachable()['status'] );
		remove_all_filters( 'pre_http_request' );

		// The server cannot reach itself: that is not evidence the blyg is down.
		$this->loopback( array() );
		$this->assertSame( 'recommended', SiteHealth::test_reachable()['status'] );
	}

	/** E7: deleting the plugin keeps the data unless the owner opted in. */
	public function test_uninstall_keeps_everything_by_default_and_removes_everything_on_opt_in() {
		global $wpdb;
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$tables_exist = static function () use ( $wpdb ) {
			$found = 0;
			foreach ( Schema::tables() as $table ) {
				$found += $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ? 1 : 0; // phpcs:ignore
			}
			return $found;
		};
		$uninstall = static function () {
			if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
				define( 'WP_UNINSTALL_PLUGIN', 'soapbox-for-blygger/soapbox-for-blygger.php' );
			}
			if ( function_exists( 'soapbox_blyg_uninstall_site' ) ) {
				soapbox_blyg_uninstall_site();
			} else {
				include dirname( __DIR__, 2 ) . '/uninstall.php';
			}
		};

		// The test suite rewrites DROP TABLE and CREATE TABLE to act on temporary tables. These are
		// real ones, so that rewriting is switched off for this test.
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );

		$uninstall();
		$this->assertSame( 5, $tables_exist(), 'the default keeps the ledger' );
		$this->assertNotEmpty( get_option( Registry::LOCKS_OPTION ) );
		$this->assertSame( 1, Plugin::instance()->ledger->count_items( 'posts' ) );

		update_option( Settings::OPTION, array( 'delete_data' => true ) );
		$uninstall();
		$this->assertSame( 0, $tables_exist(), 'the owner asked for it to go' );
		foreach ( array( Settings::OPTION, Registry::LOCKS_OPTION, Registry::REGISTERED_OPTION, Schema::VERSION_OPTION ) as $option ) {
			$this->assertSame( 'gone', get_option( $option, 'gone' ), $option );
		}

		Schema::install(); // Put the tables back for the tests that follow.
		$this->assertSame( 5, $tables_exist() );
	}
}
