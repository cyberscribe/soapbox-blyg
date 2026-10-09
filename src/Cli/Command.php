<?php
/**
 * WP-CLI commands.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Cli;

use SoapboxBlyg\Ledger\Ledger;
use SoapboxBlyg\Ledger\Schema;
use SoapboxBlyg\Ledger\Verifier;
use SoapboxBlyg\Origins\PostTypeSource;
use SoapboxBlyg\Plugin;
use Throwable;
use WP_CLI;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Publishes WordPress content as a blyg under the Blygger protocol.
 *
 * The command is `wp blyg`: "blyg" is the protocol's word for what is being managed, as "post" is
 * WordPress's.
 */
class Command {

	/**
	 * Gives v1 to every public post that has no blyg item yet.
	 *
	 * Each item's created is the post's publish date, the v1 time is its last-modified date, and
	 * the changelog note is "imported from WordPress". Safe to run again: posts that already have an item are
	 * skipped.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Render every candidate but write nothing. Reports render failures.
	 *
	 * [--dump=<dir>]
	 * : With --dry-run: write each would-be v1 to <dir> as <post_id>-<slug>.md and .html, plus
	 * index.tsv (post_id, slug, chars, media, flags) for review before a real backfill.
	 * Flags: featured-only (the featured image was added), embed-link (an embed became a plain
	 * link), br-heavy (more than 10 hard breaks: usually a poem), relative-src (an image or embed
	 * source was relative), empty-body (nothing but the title).
	 *
	 * [--limit=<n>]
	 * : Process at most this many posts.
	 *
	 * @when after_wp_load
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function backfill( $args, $assoc ) {
		$dry   = ! empty( $assoc['dry-run'] );
		$posts = $this->posts_source( $dry );
		$limit = isset( $assoc['limit'] ) ? max( 0, (int) $assoc['limit'] ) : 0;
		$dump  = isset( $assoc['dump'] ) ? rtrim( (string) $assoc['dump'], '/' ) : null;
		$tsv   = null;
		if ( null !== $dump ) {
			if ( ! $dry ) {
				WP_CLI::error( '--dump only works with --dry-run: it is for reviewing a backfill before running it.' );
			}
			if ( ! wp_mkdir_p( $dump ) || ! wp_is_writable( $dump ) ) {
				WP_CLI::error( "Cannot write to {$dump}." );
			}
			$tsv = "post_id\tslug\tchars\tmedia\tflags\n";
		}

		$ids    = $posts->unpublished_ids( $limit );
		$total  = count( $ids );
		$counts = array(
			'created'  => 0,
			'rendered' => 0,
			'failed'   => 0,
		);
		WP_CLI::log( sprintf( '%d public post(s) without a blyg item%s.', $total, $dry ? ' (dry run)' : '' ) );
		if ( ! $total ) {
			return;
		}

		$bar = \WP_CLI\Utils\make_progress_bar( $dry ? 'Rendering' : 'Backfilling', $total );
		foreach ( array_chunk( $ids, 50 ) as $batch ) {
			foreach ( $batch as $id ) {
				$post = get_post( (int) $id );
				try {
					if ( $dry ) {
						$build = $posts->build( $post );
						if ( null !== $dump && null !== $build['snapshot'] ) {
							$tsv .= $this->dump_one( $dump, $post, $build );
						}
						++$counts['rendered'];
					} else {
						$result = $posts->backfill_one( $post );
						if ( $result && 'created' === $result['event'] ) {
							++$counts['created'];
						}
					}
				} catch ( Throwable $e ) {
					++$counts['failed'];
					WP_CLI::warning( "post {$id}: " . $e->getMessage() );
				}
				$bar->tick();
			}
			// A long run holds every post and its meta in the object cache otherwise.
			\WP_CLI\Utils\wp_clear_object_cache();
		}
		$bar->finish();
		if ( null !== $dump ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a command-line export to a directory the operator named.
			file_put_contents( "{$dump}/index.tsv", $tsv );
			WP_CLI::log( "Dump written to {$dump}/ (index.tsv lists every post and its flags)." );
		}

		WP_CLI::log(
			$dry
				? sprintf( 'Rendered %d, failed %d.', $counts['rendered'], $counts['failed'] )
				: sprintf( 'Created %d item(s), failed %d.', $counts['created'], $counts['failed'] )
		);
		if ( $counts['failed'] ) {
			WP_CLI::error( 'Some posts failed. Nothing about them was written; fix and run again.' );
		}
		WP_CLI::success( $dry ? 'Dry run complete.' : 'Backfill complete.' );
	}

	/**
	 * Writes one post of a --dump: its .md and .html. Returns its index.tsv row.
	 *
	 * @param string  $dir   Dump directory.
	 * @param WP_Post $post  The post.
	 * @param array   $build What PostTypeSource::build() returned.
	 */
	private function dump_one( string $dir, WP_Post $post, array $build ): string {
		$snapshot = $build['snapshot'];
		$stats    = $build['stats'];
		$slug     = \SoapboxBlyg\Origins\PostTypeSource::dump_slug( $post );
		$base     = "{$dir}/{$post->ID}-{$slug}";
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a command-line export to a directory the operator named.
		file_put_contents( "{$base}.md", $snapshot['content_md'] );
		file_put_contents( "{$base}.html", $snapshot['content_html'] );
		// phpcs:enable

		$flags = array();
		if ( $build['featured_added'] ) {
			$flags[] = 'featured-only';
		}
		if ( $build['embed_links'] > 0 || $stats['embeds'] > 0 ) {
			$flags[] = 'embed-link';
		}
		if ( $stats['breaks'] > 10 ) {
			$flags[] = 'br-heavy';
		}
		if ( $stats['relative_src'] > 0 ) {
			$flags[] = 'relative-src';
		}
		if ( $build['body_empty'] ) { // The post body rendered to nothing, whatever the featured image then added.
			$flags[] = 'empty-body';
		}
		// A spreadsheet treats a cell that begins with one of these as a formula; a leading
		// apostrophe makes it text. The Tools screen's download does the same.
		$cells = array_map(
			static function ( $cell ) {
				$cell = (string) $cell;
				return preg_match( '/^[=+\-@\t]/', $cell ) ? "'" . $cell : $cell;
			},
			array( $post->ID, $slug, mb_strlen( $snapshot['content_md'] ), count( $snapshot['media'] ), implode( ',', $flags ) )
		);
		return implode( "\t", $cells ) . "\n";
	}

	/**
	 * Shows a source's blyg item: id, current version, kind and pinned versions.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : A source key such as post:123. A bare number means post:{n}.
	 *
	 * [--origin=<origin>]
	 * : Origin key. Default: posts.
	 *
	 * [--format=<format>]
	 * : table or json. Default: table.
	 *
	 * @when after_wp_load
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function status( $args, $assoc ) {
		list( $origin, $key ) = $this->source( $args[0], $assoc );
		$status               = soapbox_blyg_get_status( $origin, $key );
		if ( null === $status ) {
			$status = array(
				'origin'     => $origin,
				'source_key' => $key,
				'id'         => null,
				'version'    => null,
				'kind'       => null,
				'pinned'     => array(),
				'url'        => null,
			);
		}

		if ( 'json' === ( isset( $assoc['format'] ) ? $assoc['format'] : 'table' ) ) {
			WP_CLI::line( Ledger::json( $status ) );
			return;
		}
		foreach ( $status as $field => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}
			WP_CLI::line( sprintf( '%-11s %s', $field, null === $value || '' === $value ? '-' : $value ) );
		}
	}

	/**
	 * Pins a version, which serves it at its own URL forever. Irrevocable.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : A source key such as post:123. A bare number means post:{n}.
	 *
	 * <version>
	 * : The version to pin.
	 *
	 * [--origin=<origin>]
	 * : Origin key. Default: posts.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @when after_wp_load
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function pin( $args, $assoc ) {
		list( $origin, $key ) = $this->source( $args[0], $assoc );
		$version              = (int) $args[1];
		$status               = soapbox_blyg_get_status( $origin, $key );
		if ( ! $status ) {
			WP_CLI::error( "{$origin} {$key} has never been published, so it has nothing to pin." );
		}

		WP_CLI::confirm(
			"Pin v{$version} of {$status['id']} ({$key})? Pinning is irrevocable: this exact version will be "
			. 'served at its own URL forever, including after later edits and withdrawal.',
			$assoc
		);
		try {
			soapbox_blyg_pin( $status['id'], $version );
		} catch ( Throwable $e ) {
			WP_CLI::error( preg_replace( '/^soapbox-blyg: /', '', $e->getMessage() ) );
		}
		$plugin = Plugin::instance();
		WP_CLI::success( 'Pinned: ' . $plugin->locations->pin( $plugin->registry->get( $origin ), $status['id'], $version ) );
	}

	/**
	 * Checks the ledger's integrity. Exits non-zero if anything fails.
	 *
	 * Recomputes every stored content hash, checks each item's versions run 1..N with no gaps,
	 * that `updated` equals the latest version's time, that endcaps are empty and unpinned, and
	 * that no version row is orphaned.
	 *
	 * ## OPTIONS
	 *
	 * [--origin=<origin>]
	 * : Check one origin. Default: every origin with rows in the ledger, registered or not.
	 *
	 * [--render]
	 * : Also compare the posts origin with the site. Fails on a public post with no live item, or
	 * a live item whose post is no longer public. Warns when re-rendering a live post would change
	 * its hash, meaning its next save will create a version; run it after WordPress or plugin
	 * updates.
	 *
	 * @when after_wp_load
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function verify( $args, $assoc ) {
		$verifier          = new Verifier();
		$verifier->on_line = static function ( string $kind, string $text ) {
			if ( 'problem' === $kind ) {
				WP_CLI::log( "  FAIL {$text}" );
			} elseif ( 'warning' === $kind ) {
				WP_CLI::warning( $text );
			} else {
				WP_CLI::log( $text );
			}
		};
		$render            = ! empty( $assoc['render'] ) && Plugin::instance()->registry->has( PostTypeSource::ORIGIN );
		$verifier->run( isset( $assoc['origin'] ) ? (string) $assoc['origin'] : null, $render ? Plugin::instance()->posts : null );

		if ( $verifier->problems ) {
			WP_CLI::error( count( $verifier->problems ) . ' problem(s) found.' );
		}
		WP_CLI::success( 'Ledger verified.' );
	}

	/**
	 * Lists the registered origins.
	 *
	 * @when after_wp_load
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function origins( $args, $assoc ) {
		unset( $args, $assoc );
		$plugin = Plugin::instance();
		$rows   = array();
		foreach ( $plugin->registry->all() as $key => $origin ) {
			$updated = $plugin->ledger->latest_updated( $key );
			$rows[]  = array(
				'origin'   => $key,
				'mount'    => $origin->url(),
				'layout'   => $origin->layout,
				'items'    => $plugin->ledger->count_items( $key ),
				'updated'  => null === $updated ? '-' : $updated,
				'priority' => $origin->priority,
				'locked'   => $plugin->registry->is_locked( $key ) ? 'yes' : 'no',
			);
		}
		if ( ! $rows ) {
			WP_CLI::log( 'No origins are registered. Publishing may be switched off in Settings > Soapbox for Blygger.' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'origin', 'mount', 'layout', 'items', 'updated', 'priority', 'locked' ) );
	}

	/**
	 * The posts source, or an error when this site does not publish.
	 *
	 * @param bool $read_only Whether nothing will be written. A dry run may be made with publishing
	 *                        off, once the blyg's address has been chosen.
	 */
	private function posts_source( bool $read_only = false ): PostTypeSource {
		$plugin = Plugin::instance();
		if ( ! $plugin->registry->has( PostTypeSource::ORIGIN ) && ! ( $read_only && \SoapboxBlyg\Settings::get( 'setup' ) ) ) {
			WP_CLI::error( 'Publishing is switched off for this site (Settings > Soapbox for Blygger).' );
		}
		Schema::maybe_install();
		return $plugin->posts;
	}

	/**
	 * Resolves <source> and --origin to [ origin, source_key ].
	 *
	 * @param string $source The source argument.
	 * @param array  $assoc  Options.
	 * @return string[]
	 */
	private function source( string $source, array $assoc ): array {
		$origin = isset( $assoc['origin'] ) ? (string) $assoc['origin'] : PostTypeSource::ORIGIN;
		if ( ! Plugin::instance()->registry->has( $origin ) ) {
			WP_CLI::error( "Unknown origin '{$origin}'. See `wp blyg origins`." );
		}
		return array( $origin, ctype_digit( $source ) ? PostTypeSource::key( (int) $source ) : $source );
	}
}
