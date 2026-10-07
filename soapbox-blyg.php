<?php
/**
 * Plugin Name:       Soapbox Blyg
 * Description:       Publishes your posts as a blyg under the Blygger protocol: stable ids, versions, pins and a feed that other Blygger clients can subscribe to and quote. An independent implementation.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Robert Peake
 * Author URI:        https://www.robertpeake.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       soapbox-blyg
 * Domain Path:       /languages
 *
 * @package SoapboxBlyg
 */

defined( 'ABSPATH' ) || exit;

/*
 * The slug is the one name everything derivable at runtime is built from: the REST namespace, the
 * generator string, the table prefix, script handles. It is permanent once WordPress.org approves
 * the plugin. What a constant cannot reach (the text domain literals gettext tooling needs,
 * function, hook and option names, the namespace) is renamed by bin/rename.sh.
 */
const SOAPBOX_BLYG_SLUG    = 'soapbox-blyg';
const SOAPBOX_BLYG_VERSION = '0.1.0';
const SOAPBOX_BLYG_FILE    = __FILE__;

/*
 * Where this client's source lives, for the manifest's generator_url (spec §6.1). Empty until
 * there is a public address to name; the key is then left out.
 */
const SOAPBOX_BLYG_GENERATOR_URL = '';

/*
 * PSR-4 for the plugin's own classes. The one bundled library is namespace-prefixed under
 * vendor-prefixed/ and loaded by Render\Markdown only when something is rendered.
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'SoapboxBlyg\\';
		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) || 0 === strncmp( $class_name, $prefix . 'Vendor\\', strlen( $prefix ) + 7 ) ) {
			return;
		}
		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

require_once __DIR__ . '/includes/functions.php';

register_activation_hook( __FILE__, array( SoapboxBlyg\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( SoapboxBlyg\Plugin::class, 'deactivate' ) );

if ( did_action( 'plugins_loaded' ) ) {
	SoapboxBlyg\Plugin::boot();
} else {
	add_action( 'plugins_loaded', array( SoapboxBlyg\Plugin::class, 'boot' ) );
}
