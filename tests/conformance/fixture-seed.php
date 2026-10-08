<?php
/**
 * Seeds the fixture origin through the plugin's public API (includes/functions.php).
 *
 *     wp eval-file wp-content/plugins/soapbox-blyg/tests/conformance/fixture-seed.php
 *
 * Every run uses fresh source keys, so re-running adds a new set instead of colliding with the last.
 * Prints one JSON object on the last line: the ids it created, for scenario.sh to assert on.
 *
 * Exercises: fragment create, fragment edit (bump), same-content republish (no bump), thread
 * create, fragment withdraw, retroactive pin of an older version, soapbox_blyg_reconcile, and
 * soapbox_blyg_render_html.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'soapbox_blyg_publish' ) || ! defined( 'SOAPBOX_BLYG_FIXTURES' ) ) {
	WP_CLI::error( 'Engine not loaded, or SOAPBOX_BLYG_FIXTURES not defined in wp-config.php.' );
}

$run    = gmdate( 'YmdHis' ) . '-' . wp_rand( 1000, 9999 );
$author = array( 'name' => 'Fixture' );

$fragment = function ( $title, $body ) use ( $author ) {
	$md = "# {$title}\n\n{$body}\n";
	return array(
		'kind'         => 'fragment',
		'title'        => $title,
		'permalink'    => home_url( '/blyg-fixture/' ),
		'content_md'   => $md,
		'content_html' => '<h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $body ) . '</p>',
		'media'        => array(),
		'author'       => $author,
	);
};

$expect = function ( $result, $event, $version, $what ) {
	if ( $result['event'] !== $event || (int) $result['version'] !== $version ) {
		WP_CLI::error( sprintf( '%s: got %s v%d, want %s v%d', $what, $result['event'], $result['version'], $event, $version ) );
	}
	WP_CLI::log( "ok   {$what}: {$event} v{$version}" );
};

// Fragment A: create, republish unchanged, edit, then pin v1 retroactively.
$a1 = soapbox_blyg_publish( 'fixture', "frag-a:{$run}", $fragment( 'Fragment A', 'First wording.' ) );
$expect( $a1, 'created', 1, 'fragment A create' );
$expect( soapbox_blyg_publish( 'fixture', "frag-a:{$run}", $fragment( 'Fragment A', 'First wording.' ) ), 'none', 1, 'fragment A unchanged' );
$expect( soapbox_blyg_publish( 'fixture', "frag-a:{$run}", $fragment( 'Fragment A', 'Second wording.' ) ), 'bumped', 2, 'fragment A edit' );
soapbox_blyg_pin( $a1['id'], 1 );
WP_CLI::log( 'ok   fragment A v1 pinned retroactively' );

// Sanitiser: the wire tokens must survive soapbox_blyg_render_html. Checked on a throwaway render and
// never published: a baked blyg-transclusion blockquote is only valid in a thread whose
// transclusions array names that exact id and version (protocol §10.2, §10.3), and this phase
// authors no transclusions. (QA fix 2026-09-27: draft 1 published it with transclusions: [],
// which the implementer correctly flagged as inconsistent.)
$tokens = soapbox_blyg_render_html(
	'<blockquote class="blyg-transclusion" data-blyg-id="00000000000000000000000000" data-blyg-version="1"><p>kept</p></blockquote>'
	. '<p>Plain <span class="blyg-tk-gen">generated</span> and <span class="wp-x">unwrapped</span>.</p>',
	'Tokens'
);
foreach ( array( 'class="blyg-transclusion"', 'data-blyg-id="00000000000000000000000000"', 'data-blyg-version="1"', 'class="blyg-tk-gen"' ) as $keep ) {
	if ( false === strpos( $tokens['content_html'], $keep ) ) {
		WP_CLI::error( "render: sanitiser stripped wire token {$keep}" );
	}
}
if ( false !== strpos( $tokens['content_html'], 'wp-x' ) ) {
	WP_CLI::error( 'render: a non-token span kept its class' );
}
WP_CLI::log( 'ok   render keeps wire tokens and nothing else' );

// Thread T, rendered through the engine utility so the sanitiser is exercised on hostile input.
$rendered = soapbox_blyg_render_html(
	'<p class="x" style="color:red" onclick="alert(1)">Body with <a href="/relative">a relative link</a>.</p>'
	. '<script>alert(1)</script><iframe src="https://example.com/embed"></iframe>',
	'Thread T'
);
$t1 = soapbox_blyg_publish( 'fixture', "thread-t:{$run}", array(
	'kind'          => 'thread',
	'title'         => 'Thread T',
	'permalink'     => home_url( '/blyg-fixture/' ),
	'content_md'    => $rendered['content_md'],
	'content_html'  => $rendered['content_html'],
	'media'         => $rendered['media'],
	'author'        => $author,
	'transclusions' => array(),
) );
$expect( $t1, 'created', 1, 'thread T create' );
foreach ( array( '<script', 'onclick', 'style=', 'class="x"', 'href="/relative"', '<iframe' ) as $bad ) {
	if ( false !== strpos( $rendered['content_html'], $bad ) ) {
		WP_CLI::error( "render: sanitiser let {$bad} through" );
	}
}
if ( false === strpos( $rendered['content_html'], 'href="https://example.com/embed"' ) ) {
	WP_CLI::error( 'render: iframe was not replaced by a link to its src' );
}
WP_CLI::log( 'ok   render sanitises hostile input' );

// Engine validation: a fragment may not carry transclusions.
$bad = $fragment( 'Bad', 'x' );
$bad['transclusions'] = array();
$threw = false;
try {
	soapbox_blyg_publish( 'fixture', "bad:{$run}", $bad );
} catch ( Throwable $e ) {
	$threw = true;
}
if ( ! $threw ) {
	WP_CLI::error( 'validation: a fragment with transclusions was accepted' );
}
WP_CLI::log( 'ok   invalid snapshot rejected' );

// Fragment W, published, pinned, then withdrawn by reconcile (it is missing from the set).
$w1 = soapbox_blyg_publish( 'fixture', "frag-w:{$run}", $fragment( 'Fragment W', 'Will be withdrawn.' ) );
$expect( $w1, 'created', 1, 'fragment W create' );
soapbox_blyg_pin( $w1['id'], 1 );

// Pinning an endcap must throw, so withdraw a scratch item and try.
$s1 = soapbox_blyg_publish( 'fixture', "scratch:{$run}", $fragment( 'Scratch', 'x' ) );
$expect( soapbox_blyg_publish( 'fixture', "scratch:{$run}", null ), 'withdrawn', 2, 'scratch withdraw' );
$threw = false;
try {
	soapbox_blyg_pin( $s1['id'], 2 );
} catch ( Throwable $e ) {
	$threw = true;
}
if ( ! $threw ) {
	WP_CLI::error( 'pin: an endcap was pinned' );
}
WP_CLI::log( 'ok   endcap pin refused' );

// Reconcile keeps A and T (unchanged) and withdraws W. Only this run's keys are passed, so earlier
// runs' live items are withdrawn too, which is exactly what reconcile promises.
$counts = soapbox_blyg_reconcile( 'fixture', array(
	"frag-a:{$run}"   => $fragment( 'Fragment A', 'Second wording.' ),
	"thread-t:{$run}" => array(
		'kind'          => 'thread',
		'title'         => 'Thread T',
		'permalink'     => home_url( '/blyg-fixture/' ),
		'content_md'    => $rendered['content_md'],
		'content_html'  => $rendered['content_html'],
		'media'         => $rendered['media'],
		'author'        => $author,
		'transclusions' => array(),
	),
) );
$w = soapbox_blyg_get_status( 'fixture', "frag-w:{$run}" );
if ( 'withdrawn' !== $w['kind'] || 2 !== (int) $w['version'] ) {
	WP_CLI::error( 'reconcile: fragment W was not withdrawn to v2' );
}
$a = soapbox_blyg_get_status( 'fixture', "frag-a:{$run}" );
if ( 2 !== (int) $a['version'] ) {
	WP_CLI::error( 'reconcile: fragment A changed version on an unchanged snapshot' );
}
WP_CLI::log( 'ok   reconcile: ' . wp_json_encode( $counts ) );

WP_CLI::log( wp_json_encode( array(
	'a'       => $a1['id'],
	't'       => $t1['id'],
	'w'       => $w1['id'],
	'scratch' => $s1['id'],
) ) );
