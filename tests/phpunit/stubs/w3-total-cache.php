<?php
/**
 * Stands in for W3 Total Cache's public API (w3-total-cache-api.php:324 in 2.10.7).
 *
 * @package SoapboxBlyg
 */

// phpcs:ignoreFile -- a test stand-in for another plugin's function, under that plugin's name.
function w3tc_flush_url( $w3tc_url, $extras = null ) {
	\SoapboxBlyg\Tests\PurgeTest::$calls['w3tc'][] = func_get_args();
}
