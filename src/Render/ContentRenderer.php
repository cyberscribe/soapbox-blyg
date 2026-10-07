<?php
/**
 * Rendering.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Render;

use WP_Embed;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a source's HTML into the three things a version stores: sanitised, self-contained
 * content_html; content_md, whose bytes the content hash covers; and the media list.
 *
 * Rendering happens once, when something is published. Requests never render: a plugin update
 * that changed the output of a published post would otherwise be a silent edit, which conforming
 * readers flag (spec §13.3).
 */
class ContentRenderer {

	/**
	 * Embeds the stand-in for oEmbed turned into links during the current render.
	 *
	 * @var int
	 */
	private static $embed_links = 0;

	/**
	 * Renders HTML and a title into content_md, content_html and media.
	 *
	 * @param string $html  Body HTML, already through the source's own filters.
	 * @param string $title The item title. Tags are stripped and entities decoded.
	 * @param array  $opts  base: the URL relative links resolve against (default the site's home).
	 *                      repair: turn runs of line breaks into paragraphs (default true).
	 *                      wire_tokens: keep the protocol's blyg-transclusion and blyg-tk-gen
	 *                      markers (default true; the posts source passes false).
	 * @return array { content_md, content_html, media, stats }. stats is diagnostic only.
	 */
	public static function render_html( string $html, string $title, array $opts = array() ): array {
		$base  = isset( $opts['base'] ) ? (string) $opts['base'] : home_url( '/' );
		$title = self::plain_title( $title );

		$sanitizer = new Sanitizer(
			$base,
			! isset( $opts['repair'] ) || (bool) $opts['repair'],
			! isset( $opts['wire_tokens'] ) || (bool) $opts['wire_tokens']
		);
		$body      = $sanitizer->sanitize( wp_check_invalid_utf8( $html, true ) );

		$content_html = '<h1>' . Sanitizer::text( $title ) . '</h1>' . ( '' === $body ? '' : "\n" . $body );
		$body_md      = '' === trim( $body ) ? '' : Markdown::from_html( $body );

		return array(
			'content_md'   => Markdown::normalize( '# ' . Markdown::escape_text( $title ) . "\n\n" . $body_md ),
			'content_html' => $content_html,
			'media'        => $sanitizer->media(),
			'stats'        => array( 'breaks' => substr_count( $content_html, '<br>' ) ) + $sanitizer->stats(),
		);
	}

	/**
	 * A title as plain text: no tags, entities decoded, whitespace collapsed to one line. A title
	 * containing a newline would otherwise end the Markdown heading early.
	 *
	 * The result is text, not markup, and may contain any character, "<" included: a title of
	 * "Using &lt;b&gt; in HTML" is the text "Using <b> in HTML". Every place it is written escapes
	 * it for that place: Markdown::escape_text() for the heading, Sanitizer::text() for the h1,
	 * and XML escaping in the feed.
	 *
	 * @param string $title Title as stored.
	 */
	public static function plain_title( string $title ): string {
		$title = html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$title = self::xml_safe( wp_check_invalid_utf8( $title, true ) );
		return trim( preg_replace( '/\s+/u', ' ', $title ) );
	}

	/**
	 * Removes the code points XML 1.0 does not allow, which would make the feed malformed: the C0
	 * controls other than tab and line breaks, and the two noncharacters at the end of the Basic
	 * Multilingual Plane. WordPress's own filters keep the latter.
	 *
	 * @param string $text UTF-8 text.
	 */
	public static function xml_safe( string $text ): string {
		$clean = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text );
		return null === $clean ? '' : $clean;
	}

	/**
	 * How many embeds became links in the render that just ran.
	 */
	public static function embed_links(): int {
		return self::$embed_links;
	}

	/**
	 * Runs the_content for one post so that the result depends only on the post and the site's
	 * settings, never on where the save happened. The same post is saved from the classic editor,
	 * the block editor (a REST request), WP-CLI and cron, and any difference between those contexts
	 * would show up as a new version on a save that changed nothing.
	 *
	 * - The global post and the postdata globals are set to this post, then restored.
	 * - The render runs as a logged-out visitor in the site's locale. An editor's login would
	 *   otherwise reach shortcodes that vary by user, and an editor's admin language would reach
	 *   any translated string a plugin adds.
	 * - Nothing is fetched over the network. oEmbed replaces a bare URL with the provider's markup,
	 *   which comes from the network and from a cache that expires, so the same post would render
	 *   differently from one week to the next. Every URL WordPress would try to embed becomes a
	 *   plain link instead, which is also the more useful form in a feed reader.
	 *
	 * @param WP_Post $post The post.
	 * @return string The rendered body, before sanitising.
	 */
	public static function post_body( WP_Post $post ): string {
		global $wp_embed;

		self::$embed_links = 0;

		$globals = array( 'post', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages' );
		$saved   = array();
		foreach ( $globals as $name ) {
			$saved[ $name ] = isset( $GLOBALS[ $name ] ) ? $GLOBALS[ $name ] : null;
		}
		$user     = get_current_user_id();
		$switched = switch_to_locale( get_locale() );
		$stand_in = array( __CLASS__, 'links_for_embeds' );

		$embed_filters = array();
		if ( $wp_embed instanceof WP_Embed ) {
			foreach ( array( 'run_shortcode', 'autoembed' ) as $method ) {
				$priority = has_filter( 'the_content', array( $wp_embed, $method ) );
				if ( false !== $priority ) {
					remove_filter( 'the_content', array( $wp_embed, $method ), $priority );
					$embed_filters[ $method ] = $priority;
				}
			}
		}
		add_filter( 'the_content', $stand_in, 8 );

		try {
			wp_set_current_user( 0 );
			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored in finally; the_content filters read the global post.
			setup_postdata( $post );

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter, applied to render the post as its page would.
			$html = apply_filters( 'the_content', $post->post_content );

			/**
			 * Filters a post's rendered body before it is sanitised.
			 *
			 * If a plugin adds text of its own to the_content (a "Share this" heading, a related-posts
			 * list), strip it here. Its markup would go anyway, but its words would survive the
			 * allowlist and end up in every version.
			 *
			 * @param string  $html Rendered body.
			 * @param WP_Post $post The post.
			 */
			return (string) apply_filters( 'soapbox_blyg_rendered_body', $html, $post );
		} finally {
			remove_filter( 'the_content', $stand_in, 8 );
			foreach ( $embed_filters as $method => $priority ) {
				add_filter( 'the_content', array( $wp_embed, $method ), $priority );
			}
			wp_set_current_user( $user );
			if ( $switched ) {
				restore_previous_locale();
			}
			foreach ( $saved as $name => $value ) {
				$GLOBALS[ $name ] = $value; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- restoring core's own globals, saved above.
			}
		}
	}

	/**
	 * Stands in for WP_Embed during a render: embed shortcodes, and URLs alone on a line or alone
	 * in a paragraph (the two patterns WP_Embed::autoembed() matches), become plain links.
	 *
	 * @param string $content Post content.
	 */
	public static function links_for_embeds( $content ) {
		$link = static function ( string $url ): string {
			++self::$embed_links;
			$url = trim( $url );
			return '<a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>';
		};

		$content = preg_replace_callback(
			'#\[embed(?:\s[^\]]*)?\](.+?)\[/embed\]#is',
			static function ( $m ) use ( $link ) {
				return $link( $m[1] );
			},
			(string) $content
		);

		$content = preg_replace_callback(
			'|^(\s*)(https?://[^\s<>"]+)(\s*)$|im',
			static function ( $m ) use ( $link ) {
				return $m[1] . $link( $m[2] ) . $m[3];
			},
			$content
		);

		return preg_replace_callback(
			'|(<p(?: [^>]*)?>\s*)(https?://[^\s<>"]+)(\s*</p>)|i',
			static function ( $m ) use ( $link ) {
				return $m[1] . $link( $m[2] ) . $m[3];
			},
			$content
		);
	}
}
