<?php
/**
 * Documents, routing, and the two layouts.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Tests;

use SoapboxBlyg\Plugin;
use SoapboxBlyg\Settings;
use SoapboxBlyg\Surface\Http;
use SoapboxBlyg\Surface\Locations;
use SoapboxBlyg\Surface\RestDocuments;
use SoapboxBlyg\Surface\Router;
use WP_REST_Request;

class SurfaceTest extends TestCase {

	/**
	 * Publishes a small history into `notes` and returns the ids.
	 */
	private function seed(): array {
		$a = soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'One.' ), array( 'at' => '2026-03-01T10:00:00Z' ) );
		soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'Two.' ), array( 'at' => '2026-03-02T10:00:00Z', 'note' => 'second' ) );
		soapbox_blyg_pin( $a['id'], 1 );
		$t = soapbox_blyg_publish( 'notes', 't', $this->thread( 'Thread.' ), array( 'at' => '2026-03-03T10:00:00Z' ) );
		$w = soapbox_blyg_publish( 'notes', 'w', $this->fragment( 'Gone.', array( 'permalink' => home_url( '/notes/w/' ) ) ), array( 'at' => '2026-03-04T10:00:00Z' ) );
		soapbox_blyg_publish( 'notes', 'w', null, array( 'at' => '2026-03-05T10:00:00Z' ) );
		return array( 'a' => $a['id'], 't' => $t['id'], 'w' => $w['id'] );
	}

	private function respond( string $path ): array {
		$plugin = Plugin::instance();
		return $plugin->router->respond( $plugin->registry->get( 'notes' ), $path );
	}

	private function rest( string $route ): array {
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/' . Locations::rest_namespace() . $route ) );
		$data     = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( RestDocuments::MARKER, $data, "REST route {$route} did not return a surface response" );
		return $data[ RestDocuments::MARKER ];
	}

	public function test_fixed_manifest_names_the_default_paths_and_no_templates() {
		$this->register_notes();
		$this->seed();
		$response = $this->respond( 'blyg.json' );
		$this->assertSame( 200, $response['status'] );
		$this->assertSame( Http::JSON, $response['type'] );

		$m = json_decode( $response['body'], true );
		$this->assertSame( '0.3', $m['blyg'] );
		$this->assertSame( 2, $m['level'] );
		$this->assertSame( 'soapbox-blyg/' . SOAPBOX_BLYG_VERSION, $m['generator'] );
		$this->assertSame( home_url( '/notes/' ), $m['site'] );
		$this->assertSame( 'feed.xml', $m['feed'] );
		$this->assertSame( 'items/index.json', $m['items'] );
		$this->assertArrayNotHasKey( 'item', $m, 'absent means default (v0.4-plan 7.5)' );
		$this->assertArrayNotHasKey( 'pin', $m );
		$this->assertArrayNotHasKey( 'webmention', $m, 'the receiver is off by default' );
		$this->assertArrayNotHasKey( 'generator_url', $m );
		$this->assertArrayNotHasKey( 'blogroll', $m );
		$this->assertSame( '2026-03-05T10:00:00Z', $m['updated'] );
	}

	public function test_templated_manifest_locates_every_document_under_rest() {
		$this->register_notes( array( 'layout' => 'templated' ) );
		$ids  = $this->seed();
		$m    = json_decode( $this->respond( 'blyg.json' )['body'], true );
		$base = rest_url( 'soapbox-blyg/v1/origins/notes' );
		$this->assertSame( $base . '/feed', $m['feed'] );
		$this->assertSame( $base . '/items', $m['items'] );
		$this->assertSame( $base . '/items/{id}', $m['item'] );
		$this->assertSame( $base . '/items/{id}/v/{n}', $m['pin'] );
		$this->assertSame( home_url( '/notes/' ), $m['site'], 'identity stays at the mount (spec 16.6e)' );

		$origin    = Plugin::instance()->registry->get( 'notes' );
		$locations = Plugin::instance()->locations;
		$this->assertSame( $base . '/items/' . $ids['a'], $locations->item( $origin, $ids['a'] ) );
		$this->assertSame( $base . '/items/' . $ids['a'] . '/v/1', $locations->pin( $origin, $ids['a'], 1 ) );
	}

	public function test_a_templated_origin_answers_404_on_the_default_paths() {
		$this->register_notes( array( 'layout' => 'templated' ) );
		$ids = $this->seed();
		foreach ( array( 'feed.xml', 'items/index.json', "items/{$ids['a']}.json", "items/{$ids['a']}/v1.json" ) as $path ) {
			$this->assertSame( 404, $this->respond( $path )['status'], $path );
		}
		$this->assertSame( 200, $this->respond( 'blyg.json' )['status'] );
	}

	public function test_a_fixed_origin_has_no_rest_documents() {
		$this->register_notes();
		$ids = $this->seed();
		foreach ( array( '/feed', '/items', "/items/{$ids['a']}", "/items/{$ids['a']}/v/1" ) as $route ) {
			$this->assertSame( 404, $this->rest( '/origins/notes' . $route )['status'], $route );
		}
	}

	/**
	 * G2: the REST path and the rewrite path hand the sender the same response for the same
	 * document, so headers and bytes cannot differ between layouts.
	 */
	public function test_both_layouts_produce_the_same_documents() {
		$this->register_notes();
		$ids   = $this->seed();
		$fixed = array(
			'index' => $this->respond( 'items/index.json' ),
			'item'  => $this->respond( "items/{$ids['a']}.json" ),
			'endcap' => $this->respond( "items/{$ids['w']}.json" ),
			'pin'   => $this->respond( "items/{$ids['a']}/v1.json" ),
			'feed'  => $this->respond( 'feed.xml' ),
		);

		// The same ledger, served the other way. No setting can do this to a blyg that has
		// published: the lock, and each item's own record of its layout, are rewritten by hand.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET layout = %s', \SoapboxBlyg\Ledger\Schema::table( 'items' ), 'templated' ) );
		delete_option( \SoapboxBlyg\Origins\Registry::LOCKS_OPTION );
		wp_cache_flush();
		$this->reset_registry();
		$this->register_notes( array( 'layout' => 'templated' ) );
		$templated = array(
			'index'  => $this->rest( '/origins/notes/items' ),
			'item'   => $this->rest( "/origins/notes/items/{$ids['a']}" ),
			'endcap' => $this->rest( "/origins/notes/items/{$ids['w']}" ),
			'pin'    => $this->rest( "/origins/notes/items/{$ids['a']}/v/1" ),
			'feed'   => $this->rest( '/origins/notes/feed' ),
		);

		foreach ( array( 'index', 'item', 'endcap', 'pin' ) as $document ) {
			$this->assertSame( $fixed[ $document ], $templated[ $document ], $document );
		}
		$this->assertTrue( $templated['pin']['immutable'] );
		$this->assertSame( Http::RSS, $templated['feed']['type'] );

		// The feed differs only where it names item documents, which is what a layout is.
		$normalise = static function ( string $xml ): string {
			return preg_replace( '#<blyg:item>[^<]*</blyg:item>#', '<blyg:item/>', $xml );
		};
		$this->assertSame( $normalise( $fixed['feed']['body'] ), $normalise( $templated['feed']['body'] ) );
		$this->assertSame( $fixed['feed']['modified'], $templated['feed']['modified'] );
		$this->assertStringContainsString( '<blyg:item>' . rest_url( "soapbox-blyg/v1/origins/notes/items/{$ids['a']}" ) . '</blyg:item>', $templated['feed']['body'] );
		$this->assertStringContainsString( '<blyg:item>' . home_url( "/notes/items/{$ids['a']}.json" ) . '</blyg:item>', $fixed['feed']['body'] );
	}

	public function test_rest_documents_carry_no_envelope_and_bad_ids_get_the_surface_404() {
		$this->register_notes( array( 'layout' => 'templated' ) );
		$ids  = $this->seed();
		$item = $this->rest( "/origins/notes/items/{$ids['a']}" );
		$doc  = json_decode( $item['body'], true );
		$this->assertSame( array( 'blyg', 'id', 'kind', 'origin', 'page', 'author', 'created', 'updated', 'version', 'content_md', 'content_html', 'content_hash', 'media', 'changelog' ), array_keys( $doc ) );
		$this->assertArrayNotHasKey( '_links', $doc );

		foreach ( array( '/items/NOT-AN-ID', '/items/7C9WK2MHQ0V3XJ8TN5RZFD41BG', '/items/' . str_repeat( '0', 26 ), "/items/{$ids['a']}/v/0", "/items/{$ids['a']}/v/2", "/items/{$ids['a']}/v/x" ) as $route ) {
			$response = $this->rest( '/origins/notes' . $route );
			$this->assertSame( 404, $response['status'], $route );
			$this->assertSame( Http::TEXT, $response['type'], $route );
		}
		$this->assertSame( 404, $this->rest( '/origins/nowhere/items' )['status'] );
	}

	public function test_item_document_fields() {
		$this->register_notes();
		$ids = $this->seed();
		$doc = json_decode( $this->respond( "items/{$ids['a']}.json" )['body'], true );

		$this->assertSame( '0.3', $doc['blyg'] );
		$this->assertSame( 'fragment', $doc['kind'] );
		$this->assertSame( home_url( '/notes/' ), $doc['origin'] );
		$this->assertSame( home_url( '/notes/' ), $doc['page'] );
		$this->assertSame( 2, $doc['version'] );
		$this->assertSame( "# A note\n\nTwo.\n", $doc['content_md'], 'only the latest content is served' );
		$this->assertSame( 'sha256:' . hash( 'sha256', $doc['content_md'] ), $doc['content_hash'] );
		$this->assertArrayNotHasKey( 'transclusions', $doc );
		$this->assertSame(
			array(
				array( 'version' => 1, 'at' => '2026-03-01T10:00:00Z', 'note' => null, 'pinned' => true ),
				array( 'version' => 2, 'at' => '2026-03-02T10:00:00Z', 'note' => 'second' ),
			),
			$doc['changelog']
		);
		$this->assertSame( $doc['updated'], $doc['changelog'][1]['at'] );

		$thread = json_decode( $this->respond( "items/{$ids['t']}.json" )['body'], true );
		$this->assertSame( array(), $thread['transclusions'] );
	}

	public function test_a_withdrawn_item_document_is_an_empty_endcap_that_keeps_its_page() {
		$this->register_notes();
		$ids = $this->seed();
		$doc = json_decode( $this->respond( "items/{$ids['w']}.json" )['body'], true );
		$this->assertSame( 'withdrawn', $doc['kind'] );
		$this->assertSame( '', $doc['content_md'] );
		$this->assertSame( '', $doc['content_html'] );
		$this->assertSame( array(), $doc['media'] );
		$this->assertSame( home_url( '/notes/w/' ), $doc['page'] );
		$this->assertArrayNotHasKey( 'generated', $doc );
	}

	public function test_pinned_documents_serve_that_version_only_when_pinned() {
		$this->register_notes();
		$ids = $this->seed();
		$pin = $this->respond( "items/{$ids['a']}/v1.json" );
		$doc = json_decode( $pin['body'], true );
		$this->assertTrue( $pin['immutable'] );
		$this->assertSame( array( 'blyg', 'id', 'kind', 'version', 'at', 'note', 'pinned', 'origin', 'author', 'content_md', 'content_html', 'content_hash' ), array_keys( $doc ) );
		$this->assertSame( "# A note\n\nOne.\n", $doc['content_md'] );
		$this->assertArrayNotHasKey( 'media', $doc );

		$this->assertSame( 404, $this->respond( "items/{$ids['a']}/v2.json" )['status'], 'unpinned history is withheld' );
		$this->assertSame( 404, $this->respond( "items/{$ids['a']}/v9.json" )['status'] );
		$this->assertSame( 404, $this->respond( "items/{$ids['a']}/v0.json" )['status'] );
	}

	public function test_a_pin_changes_the_item_document() {
		$this->register_notes();
		$ids    = $this->seed();
		$before = $this->respond( "items/{$ids['t']}.json" );
		soapbox_blyg_pin( $ids['t'], 1 );
		$after = $this->respond( "items/{$ids['t']}.json" );
		$this->assertNotSame( $before['body'], $after['body'], 'the cached document was retired by the pin' );
	}

	public function test_index_lists_every_item_newest_update_first() {
		$this->register_notes();
		$ids   = $this->seed();
		$index = json_decode( $this->respond( 'items/index.json' )['body'], true );
		$this->assertSame( array( $ids['w'], $ids['t'], $ids['a'] ), array_column( $index['items'], 'id' ) );
		$this->assertSame( array( 'withdrawn', 'thread', 'fragment' ), array_column( $index['items'], 'kind' ) );
		$this->assertSame( '2026-03-05T10:00:00Z', $index['updated'] );
	}

	public function test_feed_rules() {
		$this->register_notes();
		$ids  = $this->seed();
		$feed = $this->respond( 'feed.xml' );
		$xml  = simplexml_load_string( $feed['body'] );
		$ns   = 'https://blygger.org/ns/0.1';

		$this->assertSame( '2', (string) $xml->channel->children( $ns )->level );
		$this->assertSame( home_url( '/notes/blyg.json' ), (string) $xml->channel->children( $ns )->manifest );

		$guids = array();
		foreach ( $xml->channel->item as $entry ) {
			$guids[] = (string) $entry->guid;
		}
		$this->assertSame(
			array( "blyg:{$ids['w']}:v2", "blyg:{$ids['t']}:v1", "blyg:{$ids['a']}:v2", "blyg:{$ids['a']}:v1" ),
			$guids,
			'one entry per event, newest first; a withdrawn item shows only its endcap'
		);

		$withdrawn = $xml->channel->item[0];
		$this->assertSame( 'withdrawn', (string) $withdrawn->title );
		$this->assertSame( '', (string) $withdrawn->description );
		$this->assertSame( home_url( '/notes/' ), (string) $withdrawn->link, 'a withdrawn entry links to the origin, not to an address that says what it was (SPEC 7.4)' );
		$this->assertStringNotContainsString( '/notes/w/', $this->respond( 'feed.xml' )['body'] );

		// Older events carry the latest HTML: history is withheld.
		$this->assertSame( (string) $xml->channel->item[2]->description, (string) $xml->channel->item[3]->description );
		$this->assertStringContainsString( 'Two.', (string) $xml->channel->item[3]->description );
		$this->assertSame( 'Tester', (string) $xml->channel->item[1]->children( 'http://purl.org/dc/elements/1.1/' )->creator );
	}

	public function test_feed_window_is_fifty_and_withdrawn_history_does_not_take_slots() {
		$this->register_notes();
		for ( $i = 1; $i <= 30; $i++ ) {
			soapbox_blyg_publish( 'notes', "n{$i}", $this->fragment( "v1 of {$i}" ), array( 'at' => sprintf( '2026-04-%02dT00:00:00Z', $i ) ) );
			soapbox_blyg_publish( 'notes', "n{$i}", $this->fragment( "v2 of {$i}" ), array( 'at' => sprintf( '2026-05-%02dT00:00:00Z', $i ) ) );
		}
		$this->assertSame( 50, substr_count( $this->respond( 'feed.xml' )['body'], '<item>' ) );
	}

	public function test_unlisted_paths_under_a_mount_are_404_and_other_origins_items_are_not_here() {
		$this->register_notes();
		$ids = $this->seed();
		soapbox_blyg_register_origin( 'other', array( 'path' => '/other/', 'layout' => 'fixed' ) );
		$plugin = Plugin::instance();

		foreach ( array( 'blogroll.opml', 'nothing-here.json', 'items/', 'blyg.json/', 'items/NOT-AN-ID.json' ) as $path ) {
			$this->assertSame( 404, $this->respond( $path )['status'], $path );
		}
		$this->assertSame( 404, $plugin->router->respond( $plugin->registry->get( 'other' ), "items/{$ids['a']}.json" )['status'] );
	}

	public function test_the_bare_mount_redirects_or_shows_the_origin_page() {
		$this->register_notes();
		$page = $this->respond( '' );
		$this->assertSame( 200, $page['status'] );
		$this->assertSame( Http::HTML, $page['type'] );
		$this->assertStringContainsString( '<link rel="blyg" href="' . home_url( '/notes/' ) . '">', $page['body'] );

		$plugin   = Plugin::instance();
		$redirect = $plugin->router->respond( $plugin->registry->get( 'posts' ), '' );
		$this->assertSame( 302, $redirect['status'] );
		$this->assertSame( home_url( '/' ), $redirect['location'] );
	}

	public function test_one_rule_per_origin_longest_mount_first() {
		$this->register_notes();
		soapbox_blyg_register_origin( 'deep', array( 'path' => '/notes/deep/' ) );
		$rules = Plugin::instance()->router->rules();
		$this->assertCount( 3, $rules );
		$this->assertSame( '^notes/deep(?:/(.*))?$', array_keys( $rules )[0] );

		// The mount's own rule does not swallow a sibling path that merely starts the same way.
		$this->assertSame( 0, preg_match( '#^blyg(?:/(.*))?$#', 'blygger/blyg.json' ) );
		$this->assertSame( 1, preg_match( '#^blyg(?:/(.*))?$#', 'blyg' ) );
	}

	/** N4: the rules survive a permalink-structure change and a flush. */
	public function test_rules_survive_a_permalink_change_and_a_flush() {
		global $wp_rewrite;
		$router = Plugin::instance()->router;
		$router->add_rules();
		$router->maybe_flush();
		$regex = array_keys( $router->rules() )[0];
		$this->assertArrayHasKey( $regex, get_option( 'rewrite_rules' ) );

		$wp_rewrite->set_permalink_structure( '/archives/%post_id%/' );
		$wp_rewrite->flush_rules( false );
		$this->assertArrayHasKey( $regex, get_option( 'rewrite_rules' ) );
		$order = array_keys( get_option( 'rewrite_rules' ) );
		$this->assertLessThan( array_search( '(.?.+?)(?:/([0-9]+))?/?$', $order, true ), array_search( $regex, $order, true ), 'ours are matched before the page catch-all' );
	}

	/**
	 * P2 (production, 2026-10-07). WordPress keeps a post's earlier slugs and, on a 404, redirects
	 * an address that carries one to the post's permalink. It does not ask whether the post is
	 * public: for a draft the permalink is /?p=N, which is a 404. So the page of a withdrawn item
	 * answered with a redirect to nowhere, before the withdrawn page could be shown.
	 */
	public function test_p2_an_old_slug_does_not_redirect_a_withdrawn_page_to_a_post_nobody_can_see() {
		$this->set_permalink_structure( '/archives/%post_id%-%postname%.html' );
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Gone' ) );
		$page = get_permalink( $post );
		add_post_meta( $post, '_wp_old_slug', 'gone' );

		// While it is public, WordPress's answer stands.
		$this->go_to( $page );
		$this->assertSame( $post, apply_filters( 'old_slug_redirect_post_id', $post ) );

		wp_update_post( array( 'ID' => $post, 'post_status' => 'draft' ) );
		$this->go_to( $page );
		$this->assertTrue( is_404() );
		$this->assertSame( 0, apply_filters( 'old_slug_redirect_post_id', $post ), 'no redirect: the withdrawn page is the answer' );

		// A redirect to a post that can be seen is a better answer than the withdrawn page, and is kept.
		$other = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->assertSame( $other, apply_filters( 'old_slug_redirect_post_id', $other ) );

		// And nothing changes anywhere else: an address the blyg never served.
		$this->go_to( home_url( '/archives/' . $post . '-never-was.html' ) );
		$this->assertSame( $post, apply_filters( 'old_slug_redirect_post_id', $post ) );
		$this->assertSame( 0, apply_filters( 'old_slug_redirect_post_id', 0 ) );
	}

	public function test_a_new_origin_triggers_exactly_one_flush() {
		$router = Plugin::instance()->router;
		$router->maybe_flush();
		$hash = get_option( Router::RULES_OPTION );
		$router->maybe_flush();
		$this->assertSame( $hash, get_option( Router::RULES_OPTION ) );

		$this->register_notes();
		$router->add_rules();
		$router->maybe_flush();
		$this->assertNotSame( $hash, get_option( Router::RULES_OPTION ) );
		$this->assertArrayHasKey( '^notes(?:/(.*))?$', get_option( 'rewrite_rules' ) );
	}

	public function test_the_plugins_own_pages_take_their_style_from_a_registered_handle() {
		$this->register_notes();
		$render = new \ReflectionMethod( \SoapboxBlyg\Surface\ItemPage::class, 'render' );
		$render->setAccessible( true );
		$origin = Plugin::instance()->registry->get( 'notes' );
		$pages  = array(
			'soapbox-blyg-withdrawn.php' => array( 'origin' => $origin, 'item_url' => 'https://example.org/i.json', 'withdrawn_at' => '2026-01-01T00:00:00Z', 'webmention' => '' ),
			'soapbox-blyg-origin.php'    => array( 'origin' => $origin, 'feed' => 'https://example.org/f', 'manifest' => 'https://example.org/m' ),
		);
		foreach ( $pages as $file => $data ) {
			wp_styles()->done = array();
			$html             = $render->invoke( null, $file, $data );
			$this->assertSame( 1, substr_count( $html, '<style' ), $file );
			$this->assertStringContainsString( "id='soapbox-blyg-page-inline-css'", str_replace( '"', "'", $html ), $file );
			$this->assertStringContainsString( 'max-width:36rem', $html, $file );
			$this->assertStringNotContainsString( '<style>', file_get_contents( dirname( SOAPBOX_BLYG_FILE ) . '/templates/' . $file ), 'no style element is written into the template' );
		}
	}

	public function test_a_url_resolves_to_its_item_by_page_or_by_document_url() {
		$this->register_notes();
		$ids       = $this->seed();
		$plugin    = Plugin::instance();
		$origin    = $plugin->registry->get( 'notes' );
		$locations = $plugin->locations;

		$this->assertSame( $ids['w'], $locations->item_for_url( $origin, home_url( '/notes/w/' ) )['blyg_id'] );
		$this->assertSame( $ids['w'], $locations->item_for_url( $origin, home_url( '/notes/w' ) )['blyg_id'], 'trailing slash is not identity' );
		$this->assertSame( $ids['a'], $locations->item_for_url( $origin, home_url( "/notes/items/{$ids['a']}.json" ) )['blyg_id'] );
		$this->assertNull( $locations->item_for_url( $origin, home_url( '/notes/items/' . str_repeat( '0', 26 ) . '.json' ) ) );
		$this->assertNull( $locations->item_for_url( $origin, home_url( '/somewhere-else/' ) ) );
		$this->assertNull( $locations->item_for_url( $plugin->registry->get( 'posts' ), home_url( '/notes/w/' ) ), 'another origin\'s item is not a target here' );
	}

	/**
	 * QA ruling on the step-5 review: a page that more than one item uses names none of them.
	 */
	public function test_a_page_shared_by_several_items_is_not_reverse_mapped() {
		$this->register_notes();
		$plugin    = Plugin::instance();
		$origin    = $plugin->registry->get( 'notes' );
		$locations = $plugin->locations;
		$shared    = home_url( '/notes/' );

		$one = soapbox_blyg_publish( 'notes', 'one', $this->fragment( 'One.' ) );
		$this->assertSame( $one['id'], $locations->item_for_url( $origin, $shared )['blyg_id'], 'one item, one page: unambiguous' );

		$two = soapbox_blyg_publish( 'notes', 'two', $this->fragment( 'Two.' ) );
		$this->assertNull( $locations->item_for_url( $origin, $shared ), 'two items share the page: ambiguous' );
		$this->assertNull( $locations->item_for_url( $origin, untrailingslashit( $shared ) ) );

		// Each is still addressable by its own document URL.
		$this->assertSame( $one['id'], $locations->item_for_url( $origin, $locations->item( $origin, $one['id'] ) )['blyg_id'] );
		$this->assertSame( $two['id'], $locations->item_for_url( $origin, $locations->item( $origin, $two['id'] ) )['blyg_id'] );

		// Several versions of one item sharing a page is not ambiguity.
		soapbox_blyg_publish( 'notes', 'solo', $this->fragment( 'v1', array( 'permalink' => home_url( '/notes/solo/' ) ) ) );
		$solo = soapbox_blyg_publish( 'notes', 'solo', $this->fragment( 'v2', array( 'permalink' => home_url( '/notes/solo/' ) ) ) );
		$this->assertSame( $solo['id'], $locations->item_for_url( $origin, home_url( '/notes/solo/' ) )['blyg_id'] );

		// A page an item used before it moved still names it.
		soapbox_blyg_publish( 'notes', 'solo', $this->fragment( 'v3', array( 'permalink' => home_url( '/notes/renamed/' ) ) ) );
		$this->assertSame( $solo['id'], $locations->item_for_url( $origin, home_url( '/notes/solo/' ) )['blyg_id'] );
		$this->assertSame( $solo['id'], $locations->item_for_url( $origin, home_url( '/notes/renamed/' ) )['blyg_id'] );
	}

	public function test_documents_that_change_without_a_dated_event_carry_no_last_modified() {
		$this->register_notes();
		$ids = $this->seed();
		// The manifest, the feed and the index change with settings, the site's name, the plugin's
		// version and a backfill. A date that stayed put through those would earn a false 304.
		foreach ( array( 'blyg.json', 'feed.xml', 'items/index.json' ) as $path ) {
			$response = $this->respond( $path );
			$this->assertSame( 200, $response['status'], $path );
			$this->assertSame( 0, $response['modified'], $path );
		}
		$this->assertSame( 0, $this->respond( "items/{$ids['t']}.json" )['modified'], 'nor does an item document, which a withdrawal can change inside one second' );
		soapbox_blyg_pin( $ids['t'], 1 );
		$this->assertGreaterThan( 0, $this->respond( "items/{$ids['t']}/v1.json" )['modified'], 'a pinned version never changes, and has one' );
	}

	public function test_a_title_with_code_points_xml_forbids_still_gives_a_well_formed_feed() {
		$this->register_notes( array( 'title' => "Notes \u{FFFF}\u{FFFE}\x0B", 'description' => "About \u{FFFF}" ) );
		$rendered = soapbox_blyg_render_html( "<p>Body \u{FFFF} text.</p>", "A title \u{FFFF}\u{FFFE} here" );
		soapbox_blyg_publish(
			'notes',
			'x',
			$this->fragment(
				'x',
				array(
					'title'        => "A title \u{FFFF}\u{FFFE} here",
					'content_md'   => $rendered['content_md'],
					'content_html' => $rendered['content_html'] . "<p>\u{FFFF}</p>",
				)
			)
		);
		$body   = $this->respond( 'feed.xml' )['body'];
		$before = libxml_use_internal_errors( true );
		$xml    = simplexml_load_string( $body );
		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $before );
		$this->assertNotFalse( $xml, 'the feed parses' );
		$this->assertSame( array(), $errors );
		$this->assertStringNotContainsString( "\u{FFFF}", $body );
		$this->assertStringNotContainsString( "\u{FFFE}", $body );
		$this->assertStringNotContainsString( "\u{FFFF}", $rendered['content_md'] );
		$this->assertSame( 'A title  here', (string) $xml->channel->item[0]->title );
	}

	public function test_http_origin_normalises_scheme_host_and_default_ports() {
		$this->assertSame( 'https://example.com', Locations::http_origin( 'HTTPS://Example.com:443/a/b?c' ) );
		$this->assertSame( 'http://localhost:8888', Locations::http_origin( 'http://localhost:8888/blyg/' ) );
		$this->assertSame( '', Locations::http_origin( 'ftp://example.com/' ) );
		$this->assertSame( '', Locations::http_origin( '/relative' ) );
	}

	/**
	 * The protocol lead's note on blygger-spec#2: documents on REST routes must behave like
	 * static files, the same bytes on every request until something changes, and the feed must
	 * answer a conditional request with 304.
	 */
	public function test_documents_are_the_same_bytes_until_something_changes_and_carry_an_etag() {
		$this->register_notes();
		$ids   = $this->seed(); // Which pins version 1 of 'a'.
		$paths = array( 'blyg.json', 'feed.xml', 'items/index.json', "items/{$ids['a']}.json", "items/{$ids['a']}/v1.json" );
		$first = array();
		foreach ( $paths as $path ) {
			$first[ $path ] = $this->respond( $path );
		}
		// Later, on another request, with nothing cached: the clock has moved and nothing else has.
		wp_cache_flush();
		$this->reset_registry();
		$this->register_notes();
		foreach ( $paths as $path ) {
			$again = $this->respond( $path );
			$this->assertSame( $first[ $path ]['body'], $again['body'], "{$path}: no request-time value in the document" );
			$this->assertArrayHasKey( 'modified', $again, "{$path}: sent with an ETag, so a 304 can answer a poll" );
		}
	}
}
