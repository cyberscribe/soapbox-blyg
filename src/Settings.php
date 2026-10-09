<?php
/**
 * The plugin's one option.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the site's settings, with defaults. Everything a particular site needs is here or in a
 * filter: there is no site-specific value in code.
 */
class Settings {

	const OPTION = 'soapbox_blyg_settings';

	/**
	 * When the settings last changed, for the manifest's Last-Modified. The manifest carries the
	 * title, the author and the surface's locations, none of which is a ledger event.
	 */
	const CHANGED_OPTION = 'soapbox_blyg_settings_changed';

	const LAYOUTS = array( 'templated', 'fixed' );

	/**
	 * Defaults for a site that has saved nothing.
	 *
	 * Publishing is off until the site's owner has chosen where the blyg lives and how it is laid
	 * out, and saved that choice (`setup`), and then switched it on. Both choices become permanent
	 * with the first item, and what is published can never be unpublished, so neither is made by
	 * activating a plugin. The layout offered first is the fixed one, which every reader of the
	 * protocol as published understands.
	 */
	public static function defaults(): array {
		return array(
			'enabled'        => false,
			'setup'          => false,
			'mount'          => '/blyg/',
			'layout'         => 'fixed',
			'title'          => '',
			'description'    => '',
			'author_name'    => '',
			'author_bio'     => '',
			'author_links'   => array(),
			'post_types'     => array( 'post' ),
			'featured_image' => true,
			'legacy_repair'  => true,
			'cache_lifetime' => Surface\Caching::DEFAULT_LIFETIME,
			'webmention'     => false,
			'delete_data'    => false,
		);
	}

	/**
	 * The version of the stored settings' meaning, and the option that records which one a site's
	 * settings were last brought up to.
	 */
	const MEANING        = 2;
	const MEANING_OPTION = 'soapbox_blyg_settings_version';

	/**
	 * Brings settings saved under earlier defaults up to date, once.
	 *
	 * The first release candidates published as soon as they were activated, in the templated
	 * layout, with nothing saved. Read with today's defaults (off, fixed, not set up) such a site
	 * would stop serving its blyg, or have its layout change under it. So what those defaults
	 * meant is written down for any site that relied on them: a site that has published keeps
	 * its mount and layout and stays on, and a site that had publishing on has, by that, done
	 * its setup.
	 *
	 * @param array|null $lock      The posts origin's lock { path, layout }, if it has published.
	 * @param bool       $has_items Whether the posts origin has any item.
	 */
	public static function migrate( $lock, bool $has_items ): void {
		if ( (int) get_option( self::MEANING_OPTION, 0 ) >= self::MEANING ) {
			return;
		}
		$saved   = get_option( self::OPTION, null );
		$existed = is_array( $saved );
		$saved   = $existed ? $saved : array();
		if ( $lock || $has_items || $existed ) {
			// What an unset key meant when these settings were last saved, or relied on.
			$saved += array(
				'enabled' => ! self::is_network_active(),
				'mount'   => '/blyg/',
				'layout'  => 'templated',
			);
			if ( $lock ) {
				$saved['mount']  = (string) $lock['path'];
				$saved['layout'] = (string) $lock['layout'];
			}
			if ( $lock || $has_items || ! empty( $saved['enabled'] ) ) {
				$saved['setup'] = true;
			}
			update_option( self::OPTION, $saved, true );
		}
		update_option( self::MEANING_OPTION, self::MEANING, true );
		delete_option( 'soapbox_blyg_last_error' ); // Replaced by the repair queue.
	}

	/**
	 * Every setting, saved values over defaults.
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * The manifest identity of the posts origin. Blank fields fall back to the site's own name and
	 * tagline, so a site that saves nothing still publishes a sensible manifest.
	 *
	 * @return array { title, description, author: { name, bio?, links[] } }
	 */
	public static function identity(): array {
		$s     = self::all();
		$plain = static function ( $text ) {
			return html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		};
		$title = '' !== trim( (string) $s['title'] ) ? (string) $s['title'] : $plain( get_bloginfo( 'name' ) );
		$links = array();
		foreach ( (array) $s['author_links'] as $link ) {
			if ( ! empty( $link['label'] ) && ! empty( $link['url'] ) ) {
				$links[] = array(
					'label' => (string) $link['label'],
					'url'   => (string) $link['url'],
				);
			}
		}
		if ( ! $links ) {
			$links[] = array(
				'label' => __( 'Home', 'soapbox-for-blygger' ),
				'url'   => home_url( '/' ),
			);
		}
		$author = array( 'name' => '' !== trim( (string) $s['author_name'] ) ? (string) $s['author_name'] : $title );
		if ( '' !== trim( (string) $s['author_bio'] ) ) {
			$author['bio'] = (string) $s['author_bio']; // An empty bio is left out, never sent as "".
		}
		$author['links'] = $links;

		return array(
			'title'       => $title,
			'description' => '' !== trim( (string) $s['description'] ) ? (string) $s['description'] : $plain( get_bloginfo( 'description' ) ),
			'author'      => $author,
		);
	}

	/**
	 * Whether the plugin is active for the whole network rather than for this site.
	 */
	public static function is_network_active(): bool {
		if ( ! is_multisite() ) {
			return false;
		}
		$plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
		return isset( $plugins[ plugin_basename( SOAPBOX_BLYG_FILE ) ] );
	}

	/**
	 * Records that the settings changed, so the manifest's Last-Modified moves with them.
	 */
	public static function touch(): void {
		update_option( self::CHANGED_OPTION, gmdate( 'Y-m-d\TH:i:s\Z' ), true );
	}
}
