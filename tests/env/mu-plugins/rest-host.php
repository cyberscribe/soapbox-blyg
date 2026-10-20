<?php
/**
 * Test environments only. Loaded by tests/env/webmention.json, never shipped.
 *
 * Puts the REST API on a host other than home's while the option soapbox_test_rest_host names
 * one, as domain mapping and headless set-ups do. The Webmention rehearsal sets it for one step
 * (decision #84: a target on the REST host is ours) and deletes it after; with no option set,
 * this does nothing.
 *
 * @package SoapboxBlyg
 */

add_filter(
	'rest_url',
	static function ( $url ) {
		$host = (string) get_option( 'soapbox_test_rest_host', '' );
		if ( '' === $host ) {
			return $url;
		}
		$home = wp_parse_url( home_url( '/' ) );
		$from = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		return 0 === strpos( $url, $from ) ? $host . substr( $url, strlen( $from ) ) : $url;
	}
);
