<?php
/**
 * Stands in for SiteGround Speed Optimizer's public API (helpers/helpers.php:17 in 7.8.4).
 *
 * @package SoapboxBlyg
 */

// phpcs:ignoreFile -- a test stand-in for another plugin's function, under that plugin's name.
function sg_cachepress_purge_cache( $url = false ) {
	\SoapboxBlyg\Tests\PurgeTest::$calls['sg'][] = func_get_args();
}
