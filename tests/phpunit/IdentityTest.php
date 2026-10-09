<?php
/**
 * The plugin's two names, and what happens where the plugin under its earlier name is still there.
 *
 * It was first released as "Soapbox Blyg", in the folder soapbox-blyg, and is now "Soapbox for
 * Blygger", in soapbox-for-blygger. WordPress takes those for two plugins. They share one ledger,
 * one set of options and one set of published addresses, all of which keep the first name.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Tests;

use SoapboxBlyg\Admin\SettingsPage;
use SoapboxBlyg\Admin\ToolsPage;
use SoapboxBlyg\Ledger\Schema;
use SoapboxBlyg\Plugin;
use SoapboxBlyg\Settings;
use SoapboxBlyg\Surface\Documents;
use SoapboxBlyg\Surface\Locations;

class IdentityTest extends TestCase {

	/** Where the plugin's main file was under its earlier name. */
	private static function old_copy(): string {
		return WP_PLUGIN_DIR . '/soapbox-blyg/soapbox-blyg.php';
	}

	/** On a network, plugins are the network administrator's. */
	private function someone_who_manages_plugins(): int {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user );
		}
		return $user;
	}

	/** Runs $during with the earlier plugin's main file in place, and takes it away again. */
	private function with_the_old_copy_installed( callable $during ): void {
		$this->assertFileDoesNotExist( self::old_copy(), 'this checkout is not the earlier plugin' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a stand-in file, in the test site's plugins directory.
		$this->assertNotFalse( file_put_contents( self::old_copy(), "<?php\n// A stand-in for Soapbox Blyg 0.1.1, written by IdentityTest.\n" ) );
		try {
			$during();
		} finally {
			unlink( self::old_copy() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * These are in the database and in every address the blyg has served. A site that published
	 * under the first name must find its tables, its settings and its documents where they were.
	 */
	public function test_the_names_that_are_data_are_the_first_ones_for_good() {
		global $wpdb;
		$this->assertSame( 'soapbox-blyg', SOAPBOX_BLYG_SLUG );
		$this->assertSame( 'soapbox-blyg/v1', Locations::rest_namespace() );
		$this->assertSame( $wpdb->prefix . 'soapbox_blyg_items', Schema::table( 'items' ) );
		$this->assertSame( $wpdb->prefix . 'soapbox_blyg_versions', Schema::table( 'versions' ) );
		$this->assertSame( 'soapbox_blyg_settings', Settings::OPTION );
		// In bookmarks, and in the links Site Health and the notices give.
		$this->assertSame( 'soapbox-blyg', SettingsPage::PAGE );
		$this->assertSame( 'soapbox-blyg-tools', ToolsPage::PAGE );
	}

	public function test_the_names_people_and_wordpress_org_see_are_the_new_ones() {
		$this->assertSame( 'soapbox-for-blygger', SOAPBOX_BLYG_TEXT_DOMAIN );
		$this->assertSame( 'soapbox-for-blygger.php', basename( SOAPBOX_BLYG_FILE ) );
		$this->assertSame( 'soapbox-for-blygger/' . SOAPBOX_BLYG_VERSION, Documents::generator() );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$header = get_plugin_data( SOAPBOX_BLYG_FILE, false, false );
		$this->assertSame( 'Soapbox for Blygger', $header['Name'] );
		$this->assertSame( 'soapbox-for-blygger', $header['TextDomain'] );
		$this->assertSame( SOAPBOX_BLYG_VERSION, $header['Version'] );
	}

	/**
	 * No translation ships with the plugin. WordPress loads a language pack by itself, from its
	 * own languages directory, and nothing here points it anywhere else.
	 */
	public function test_translations_are_left_to_wordpress() {
		$this->assertFalse( has_action( 'init', array( Plugin::class, 'load_translations' ) ) );
		$this->assertFalse( is_dir( dirname( SOAPBOX_BLYG_FILE ) . '/languages' ) && (bool) glob( dirname( SOAPBOX_BLYG_FILE ) . '/languages/*.mo' ), 'no compiled translation is in the plugin' );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->assertSame( '', get_plugin_data( SOAPBOX_BLYG_FILE, false, false )['DomainPath'] );

		ToolsPage::enqueue( 'tools' );
		$script = wp_scripts()->registered['soapbox-blyg-tools'];
		$this->assertSame( 'soapbox-for-blygger', $script->textdomain );
		$this->assertEmpty( $script->translations_path );
	}

	/**
	 * Both plugins active at once: the second to load finds the first's constants and stands
	 * down. It defines nothing, hooks nothing, and says what to do.
	 */
	public function test_a_second_copy_stands_down_and_says_so() {
		global $wp_filter;
		$hooked = static function () use ( $wp_filter ) {
			$count = 0;
			foreach ( array( 'init', 'plugins_loaded', 'soapbox_blyg_event', 'wp_after_insert_post', 'parse_request', 'shutdown' ) as $hook ) {
				foreach ( isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array() as $callbacks ) {
					$count += count( $callbacks );
				}
			}
			return $count;
		};
		$before  = $hooked();
		$notices = has_action( 'admin_notices' ) ? count( $wp_filter['admin_notices']->callbacks, COUNT_RECURSIVE ) : 0;

		include SOAPBOX_BLYG_FILE;

		$this->assertSame( $before, $hooked(), 'the second copy hooked nothing' );
		$this->assertGreaterThan( $notices, count( $wp_filter['admin_notices']->callbacks, COUNT_RECURSIVE ), 'and it left a notice' );

		wp_set_current_user( $this->someone_who_manages_plugins() );
		ob_start();
		do_action( 'admin_notices' );
		$said = ob_get_clean();
		$this->assertStringContainsString( 'Soapbox Blyg', $said );
		$this->assertStringContainsString( 'Soapbox for Blygger', $said );
	}

	/**
	 * On a network the new plugin can load first, and the earlier one, which has no such guard,
	 * then boots the same classes again.
	 */
	public function test_booting_twice_is_booting_once() {
		global $wp_filter;
		$hooked = static function () use ( $wp_filter ) {
			$count = 0;
			foreach ( $wp_filter as $hook ) {
				foreach ( $hook->callbacks as $callbacks ) {
					$count += count( $callbacks );
				}
			}
			return $count;
		};
		$before = $hooked();
		Plugin::boot();
		$this->assertSame( $before, $hooked() );
	}

	/**
	 * Deleting the earlier plugin runs the earlier plugin's uninstall, which reads the same
	 * setting and would drop the tables this one is using. While it is installed the setting is
	 * held off, and cannot be switched on.
	 */
	public function test_delete_all_data_is_held_off_while_the_earlier_plugin_is_installed() {
		$on = array( 'delete_data' => true ) + Settings::all();
		update_option( Settings::OPTION, $on );
		Plugin::keep_data_beside_the_earlier_plugin();
		$this->assertTrue( Settings::get( 'delete_data' ), 'with no earlier plugin there, the owner\'s choice stands' );

		$this->with_the_old_copy_installed(
			function () use ( $on ) {
				Plugin::keep_data_beside_the_earlier_plugin();
				$this->assertFalse( Settings::get( 'delete_data' ), 'switched off' );
				$stored = get_option( Settings::OPTION );
				$this->assertFalse( $stored['delete_data'], 'in the option the earlier uninstall reads' );
				$this->assertSame( $on['mount'], $stored['mount'], 'and nothing else in it changed' );
				$this->assertSame( $on['enabled'], $stored['enabled'] );

				// From the settings screen, and from a script.
				$this->assertFalse( SettingsPage::sanitize( array( '_screen' => '1', 'delete_data' => '1' ) + $stored )['delete_data'] );
				$this->assertFalse( SettingsPage::sanitize( array( 'delete_data' => true ) )['delete_data'] );

				wp_set_current_user( $this->someone_who_manages_plugins() );
				set_current_screen( 'plugins' );
				ob_start();
				do_action( 'admin_notices' );
				$this->assertStringContainsString( 'Soapbox Blyg', (string) ob_get_clean(), 'and the administrator is told why' );
				set_current_screen( 'front' );
			}
		);

		// Once it has gone, the choice is the owner's again.
		$this->assertTrue( SettingsPage::sanitize( array( 'delete_data' => true ) )['delete_data'] );
	}

	/**
	 * The other way round: this plugin deleted while the earlier one is still installed. Its
	 * uninstall leaves everything, the scheduled work included, whatever the setting says.
	 */
	public function test_this_plugins_uninstall_leaves_everything_while_the_earlier_plugin_is_installed() {
		global $wpdb;
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		// Written past the sanitiser, as a setting saved before this release would be.
		update_option( Settings::OPTION, array( 'delete_data' => true ) + Settings::all() );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'soapbox_blyg_repair' );
		update_option( 'soapbox_blyg_plugin_version', SOAPBOX_BLYG_VERSION );

		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );

		$this->with_the_old_copy_installed(
			function () use ( $wpdb ) {
				if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
					define( 'WP_UNINSTALL_PLUGIN', 'soapbox-for-blygger/soapbox-for-blygger.php' );
				}
				if ( function_exists( 'soapbox_blyg_uninstall_site' ) ) {
					soapbox_blyg_uninstall_site();
				} else {
					include dirname( __DIR__, 2 ) . '/uninstall.php';
				}
				foreach ( Schema::tables() as $table ) {
					$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), $table ); // phpcs:ignore
				}
				$this->assertSame( 1, Plugin::instance()->ledger->count_items( 'posts' ) );
				$this->assertNotEmpty( get_option( Settings::OPTION ) );
				$this->assertNotFalse( wp_next_scheduled( 'soapbox_blyg_repair' ), 'the earlier plugin\'s scheduled work is left alone' );
				$this->assertSame( SOAPBOX_BLYG_VERSION, get_option( 'soapbox_blyg_plugin_version' ) );
			}
		);
		wp_unschedule_hook( 'soapbox_blyg_repair' );
	}
}
