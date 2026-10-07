<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite that wp-env provides, with the plugin active.
 *
 * Run inside wp-env:
 *
 *     npx wp-env run tests-cli --env-cwd=wp-content/plugins/soapbox-blyg vendor/bin/phpunit
 *
 * @package SoapboxBlyg
 */

$soapbox_blyg_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $soapbox_blyg_tests_dir || ! file_exists( $soapbox_blyg_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WP_TESTS_DIR is not set. Run the tests inside wp-env (see tests/phpunit/bootstrap.php).\n" );
	exit( 1 );
}

require_once dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $soapbox_blyg_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/soapbox-blyg.php';
	}
);

require $soapbox_blyg_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/TestCase.php';
