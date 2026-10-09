<?php
/**
 * Plugin Name:       Soapbox for Blygger
 * Plugin URI:        https://github.com/cyberscribe/soapbox-blyg
 * Description:       Publishes your posts as a blyg: stable ids, versions, pins and a feed that other clients can subscribe to and quote. An independent implementation of the Blygger protocol; not affiliated with the Blygger project.
 * Version:           0.1.2
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Robert Peake
 * Author URI:        https://www.robertpeake.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       soapbox-for-blygger
 *
 * @package SoapboxBlyg
 */

defined( 'ABSPATH' ) || exit;

/*
 * The plugin was first released as "Soapbox Blyg", in the folder soapbox-blyg. WordPress takes
 * that for a different plugin, and both can be active at once: they would define the same
 * constants and start the same classes twice. Whichever is loaded second stands down here, before
 * it defines or hooks anything, and says what to do. (The earlier one has no such check; where it
 * is the second to load, Plugin::boot() is what refuses to run twice.)
 */
if ( defined( 'SOAPBOX_BLYG_VERSION' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Soapbox Blyg and Soapbox for Blygger are both active. They are the same plugin under its earlier and its present name, and only one of them is running. Deactivate Soapbox Blyg: Soapbox for Blygger then takes over, with the same blyg at the same addresses.', 'soapbox-for-blygger' ) . '</p></div>';
			}
		}
	);
	return;
}

/*
 * The plugin has two names, and they are not interchangeable.
 *
 * SOAPBOX_BLYG_SLUG is the name its data was first written under: the table prefix, the REST
 * namespace of every templated document, script handles, the Action Scheduler group. It is in
 * the database and in every address a site has published, and it never changes. Function, hook,
 * option and constant names carry it too, and the PHP namespace.
 *
 * SOAPBOX_BLYG_TEXT_DOMAIN is the plugin's slug on WordPress.org: its folder, its main file and
 * its text domain. The gettext calls spell it out, as the tooling needs. SOAPBOX_BLYG_GENERATOR
 * is the name the documents give for the software that wrote them (spec §3.2).
 */
const SOAPBOX_BLYG_SLUG        = 'soapbox-blyg';
const SOAPBOX_BLYG_TEXT_DOMAIN = 'soapbox-for-blygger';
const SOAPBOX_BLYG_GENERATOR   = 'soapbox-for-blygger';
const SOAPBOX_BLYG_VERSION     = '0.1.2';
const SOAPBOX_BLYG_FILE        = __FILE__;

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
