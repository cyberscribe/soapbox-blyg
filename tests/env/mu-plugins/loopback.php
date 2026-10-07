<?php
/**
 * Test environments only. Never shipped.
 *
 * A wp-env site is published on a host port (http://localhost:8888) that its own container cannot
 * reach, so WordPress cannot make loopback requests and Site Health's HTTP tests have nothing to
 * read. This sends requests for the site's own URL to the web server inside the container instead,
 * keeping the Host header, which is what a working loopback would have reached.
 *
 * With SOAPBOX_BLYG_TEST_EDGE_CACHE defined it also makes the surface claim a thirty-day shared
 * cache lifetime, to stand in for a host that rewrites Cache-Control (acceptance criterion E10).
 *
 * @package SoapboxBlyg
 */

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$home = home_url();
		if ( false !== $pre || 0 !== strpos( $url, $home ) || ! empty( $args['_soapbox_blyg_looped'] ) ) {
			return $pre;
		}
		$args['_soapbox_blyg_looped'] = true;
		$args['headers']              = (array) $args['headers'] + array( 'Host' => wp_parse_url( $home, PHP_URL_HOST ) . ':' . wp_parse_url( $home, PHP_URL_PORT ) );
		return wp_remote_request( 'http://127.0.0.1' . substr( $url, strlen( $home ) ), $args );
	},
	10,
	3
);

if ( file_exists( WP_CONTENT_DIR . '/soapbox-blyg-test-edge-cache' ) ) {
	add_filter(
		'soapbox_blyg_cache_control',
		static function ( $value ) {
			return $value . ', s-maxage=2592000';
		}
	);
}
