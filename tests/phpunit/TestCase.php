<?php
/**
 * Base test case.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Tests;

use SoapboxBlyg\Ledger\Schema;
use SoapboxBlyg\Origins\Registry;
use SoapboxBlyg\Plugin;
use SoapboxBlyg\Settings;
use SoapboxBlyg\Surface\Router;
use WP_UnitTestCase;

/**
 * Gives each test an empty ledger and a known pair of origins.
 *
 * The ledger's tables are created outside the test transaction, so they are emptied by hand. The
 * ledger takes its own named lock and transaction on every publish; the test suite's wrapping
 * transaction would hide those writes from nothing here, because everything runs on one
 * connection.
 */
abstract class TestCase extends WP_UnitTestCase {

	public static function set_up_before_class() {
		parent::set_up_before_class();
		Schema::install();
	}

	public function set_up() {
		parent::set_up();
		global $wpdb;
		// A test that alters a table commits whatever came before it, and the suite's rollback then
		// undoes what came after: the schema's own record of itself can be left half-way.
		if ( '' !== Schema::blocked() || get_option( Schema::VERSION_OPTION ) !== Schema::VERSION ) {
			update_option( Schema::PROBLEM_OPTION, '' );
			Schema::install();
		}
		foreach ( Schema::tables() as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore
		}
		// The ledger commits its own transactions, which also commits the test suite's wrapping one,
		// so nothing is rolled back between tests. Start each from a known state instead.
		_delete_all_posts();
		delete_option( Registry::LOCKS_OPTION );
		delete_option( Registry::REGISTERED_OPTION );
		delete_option( Settings::OPTION );
		// A site that has done its first-run setup and switched publishing on, in the templated
		// layout. Tests of the setup itself delete the option again.
		update_option( Settings::OPTION, array( 'enabled' => true, 'mount' => '/blyg/', 'layout' => 'templated' ) );
		delete_option( Router::RULES_OPTION );
		delete_transient( \SoapboxBlyg\Origins\PostTypeSource::STALE_TRANSIENT );
		remove_all_filters( 'soapbox_blyg_is_public' );
		wp_cache_flush();
		$this->reset_registry();
		$this->set_permalink_structure( '/%year%/%monthnum%/%postname%/' );
	}

	/**
	 * Empties the registry and registers the posts origin afresh, as `init` would.
	 */
	protected function reset_registry(): void {
		$registry = Plugin::instance()->registry;
		$property = new \ReflectionProperty( Registry::class, 'origins' );
		$property->setAccessible( true );
		$property->setValue( $registry, array() );
		$conflicts = new \ReflectionProperty( Registry::class, 'conflicts' );
		$conflicts->setAccessible( true );
		$conflicts->setValue( $registry, array() );

		// Only the posts source registers here. wp-env defines the fixtures constant for both of its
		// sites, and the fixture origin belongs to the black-box scenario, not to these tests.
		remove_all_actions( 'soapbox_blyg_register_origins' );
		add_action( 'soapbox_blyg_register_origins', array( Plugin::instance()->posts, 'register_origin' ) );
		Plugin::instance()->register_origins();
	}

	/**
	 * Makes every queued repair due now. A repair waits a minute, then two, then four, before it
	 * is tried again; tests do not.
	 */
	protected function repairs_are_due(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET next_try = %s', \SoapboxBlyg\Ledger\Schema::table( 'repairs' ), '' ) ); // phpcs:ignore
	}

	/**
	 * Registers a second, non-WordPress origin.
	 *
	 * @param array $args Overrides.
	 */
	protected function register_notes( array $args = array() ): void {
		soapbox_blyg_register_origin(
			'notes',
			$args + array(
				'path'   => '/notes/',
				'title'  => 'Notes',
				'author' => array( 'name' => 'Tester' ),
				'layout' => 'fixed',
			)
		);
	}

	/**
	 * A fragment snapshot.
	 *
	 * @param string $body  Body text.
	 * @param array  $extra Overrides.
	 */
	protected function fragment( string $body = 'One.', array $extra = array() ): array {
		return $extra + array(
			'kind'         => 'fragment',
			'title'        => 'A note',
			'permalink'    => home_url( '/notes/' ),
			'content_md'   => "# A note\n\n{$body}\n",
			'content_html' => '<h1>A note</h1><p>' . esc_html( $body ) . '</p>',
			'media'        => array(),
			'author'       => array( 'name' => 'Tester' ),
		);
	}

	/**
	 * A thread snapshot.
	 *
	 * @param string $body  Body text.
	 * @param array  $extra Overrides.
	 */
	protected function thread( string $body = 'One.', array $extra = array() ): array {
		return $extra + array( 'kind' => 'thread', 'transclusions' => array() ) + $this->fragment( $body );
	}
}
