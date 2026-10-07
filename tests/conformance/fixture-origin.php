<?php
/**
 * Test-only second origin, /blyg-fixture/ (SPEC.md §5.2, AC-16).
 *
 * Owned by QA. The engine loads this file only when SOAPBOX_BLYG_FIXTURES is defined, which is set in
 * the local wp-config.php and never on production. It proves the engine serves two origins from one
 * ledger without either seeing the other's items, before the practices corpus depends on that.
 *
 * It registers through the same action any real adapter uses and calls nothing but the public API.
 * If this file needs an engine internal to work, the API is incomplete: fix the API, not this file.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'soapbox_blyg_register_origins', function () {
	soapbox_blyg_register_origin( 'fixture', array(
		'path'        => '/blyg-fixture/',
		'title'       => 'Blyg fixture',
		'description' => 'Test-only origin for the rp-astra blyg engine.',
		'author'      => array( 'name' => 'Fixture' ),
		'discover'    => '__return_false',
		'priority'    => 100,
	) );
} );
