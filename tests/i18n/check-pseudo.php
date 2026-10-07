<?php
/**
 * Renders what the plugin shows in the admin, in the pseudo-locale, and lists every piece of
 * visible text that is not wrapped in the pseudo-locale's brackets: text that never went through
 * a translation function. Run by bin/i18n.sh pseudo. Not part of the plugin.
 *
 *     wp eval-file tests/i18n/check-pseudo.php
 *
 * @package SoapboxBlyg
 */

use SoapboxBlyg\Admin\EditorPanel;
use SoapboxBlyg\Admin\SettingsPage;
use SoapboxBlyg\Admin\SiteHealth;
use SoapboxBlyg\Admin\ToolsPage;
use SoapboxBlyg\Plugin;

require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user( (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
switch_to_locale( 'xx_XX' );
Plugin::load_translations();

$capture = static function ( callable $render ): string {
	ob_start();
	$render();
	return (string) ob_get_clean();
};

set_current_screen( 'settings_page_soapbox-blyg' );
SettingsPage::register_setting();
do_action( 'admin_init' );
$html = array(
	'settings' => $capture( array( SettingsPage::class, 'render' ) ),
	'tools'    => $capture( array( ToolsPage::class, 'render' ) ),
);
set_current_screen( 'plugins' );
$html['notices'] = $capture( array( ToolsPage::class, 'notices' ) );
$post            = get_posts( array( 'numberposts' => 1 ) );
if ( $post ) {
	$html['meta box'] = $capture(
		static function () use ( $post ) {
			EditorPanel::render_meta_box( $post[0] );
		}
	);
}
foreach ( array( 'permalinks', 'mount', 'ledger' ) as $test ) {
	$result                           = call_user_func( array( SiteHealth::class, 'test_' . $test ) );
	$html[ "site health: {$test}" ] = '<p>' . $result['label'] . '</p><p>' . wp_strip_all_tags( $result['description'] ) . '</p><p>' . wp_strip_all_tags( $result['actions'] ) . '</p>';
}

// Text the plugin shows that is not the plugin's to translate: what the site owner typed, what
// WordPress supplies in its own language files (post type names, dates), and names.
$not_ours = array( get_bloginfo( 'name' ), get_bloginfo( 'description' ), 'Save Changes', 'Soapbox Blyg', 'templated', 'fixed' );
foreach ( get_post_types( array(), 'objects' ) as $type ) {
	$not_ours[] = $type->labels->name;
}
foreach ( Plugin::instance()->registry->all() as $origin ) {
	$not_ours[] = $origin->title;
	$not_ours[] = $origin->key;
	$not_ours[] = $origin->path;
}
foreach ( get_posts( array( 'numberposts' => -1, 'post_type' => 'any', 'post_status' => 'any' ) ) as $any ) {
	$not_ours[] = $any->post_title;
}
foreach ( Plugin::instance()->registry->all() as $origin ) {
	global $wpdb;
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT v.title FROM %i v JOIN %i i ON i.blyg_id = v.blyg_id WHERE i.origin_key = %s', \SoapboxBlyg\Ledger\Schema::table( 'versions' ), \SoapboxBlyg\Ledger\Schema::table( 'items' ), $origin->key ) ) as $title ) {
		$not_ours[] = $title;
	}
}
$not_ours = array_values( array_unique( array_filter( array_map( 'strval', $not_ours ), static fn( $text ) => '' !== $text ) ) );
usort(
	$not_ours,
	static function ( $a, $b ) {
		return strlen( $b ) - strlen( $a );
	}
);

$problems = array();
foreach ( $html as $where => $markup ) {
	$markup = preg_replace( '#<(script|style|code|textarea)\b.*?</\1>#s', '', $markup );
	// A translated sentence may have markup inside it (a link in the middle), so the bracketed
	// runs come out first, tags and all. What is left is what was never translated.
	$markup = preg_replace( '/⟦.*?⟧/su', '', $markup );
	foreach ( preg_split( '#<[^>]+>#', $markup ) as $text ) {
		$bare = trim( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
		$bare = trim( str_replace( $not_ours, '', $bare ) );
		$date = (bool) preg_match( '/^[A-Z][a-z]+ \d{1,2}, \d{4}( \d{1,2}:\d{2} [ap]m)?$/', $bare );
		if ( preg_match( '/[A-Za-z]{3,}/', $bare ) && ! $date && ! preg_match( '#^(https?://|/)\S*$#', $bare ) && ! preg_match( '/^[0-9a-z]{26}$/', $bare ) ) {
			$problems[] = "{$where}: {$bare}";
		}
	}
}
$problems = array_values( array_unique( $problems ) );
foreach ( $problems as $problem ) {
	echo $problem, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- command-line output.
}
echo count( $html ), ' surfaces rendered, ', count( $problems ), " untranslated\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- command-line output.
exit( $problems ? 1 : 0 );
