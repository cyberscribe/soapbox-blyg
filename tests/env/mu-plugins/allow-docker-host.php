<?php
/**
 * Test environments only. Loaded by tests/env/webmention.json, never shipped.
 *
 * The Webmention rehearsal runs a stub sender on the developer's machine, which a wp-env
 * container reaches as host.docker.internal: a private address, which the receiver rightly
 * refuses. This uses core's own switch to let that one host through, in this one environment.
 *
 * @package SoapboxBlyg
 */

add_filter(
	'http_request_host_is_external',
	static function ( $external, $host ) {
		return 'host.docker.internal' === $host ? true : $external;
	},
	10,
	2
);

/*
 * The stub listens on 8090, not 8080, which other local projects tend to hold. WordPress allows
 * outbound requests on 80, 443 and 8080 only; this adds 8090 for the stub's host alone.
 */
add_filter(
	'http_allowed_safe_ports',
	static function ( $ports, $host ) {
		return 'host.docker.internal' === $host ? array_merge( (array) $ports, array( 8090 ) ) : $ports;
	},
	10,
	2
);
