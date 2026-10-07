<?php
/**
 * What one version of an item looks like.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * A snapshot is everything a source says about one public item at one moment. Sources hand
 * snapshots to the ledger and nothing else: they never compute a hash, choose a version number or
 * mint an id. The ledger stores each accepted snapshot once and never renders it again, so a later
 * plugin, theme or library change cannot alter what was published.
 */
final class Snapshot {

	/**
	 * Either fragment or thread.
	 *
	 * @var string
	 */
	public $kind;

	/**
	 * Plain-text title, used for the feed's title element only. Items are titleless on the wire.
	 *
	 * @var string
	 */
	public $title;

	/**
	 * The item's human-readable page, absolute (spec §5.8).
	 *
	 * @var string
	 */
	public $permalink;

	/**
	 * The Markdown the content hash covers.
	 *
	 * @var string
	 */
	public $content_md;

	/**
	 * Self-contained HTML.
	 *
	 * @var string
	 */
	public $content_html;

	/**
	 * Media entries: url, mime, alt.
	 *
	 * @var array
	 */
	public $media;

	/**
	 * The author assertion, or null for none (spec §5.5).
	 *
	 * @var array|object|null
	 */
	public $author;

	/**
	 * Transclusion provenance. An array for a thread, null for a fragment.
	 *
	 * @var array|null
	 */
	public $transclusions;

	/**
	 * Generation provenance, or null when the version involved none.
	 *
	 * @var array|null
	 */
	public $generated;

	/**
	 * Builds a snapshot from the array form the public API takes, validating it first.
	 *
	 * @param array $data See soapbox_blyg_publish().
	 * @throws \InvalidArgumentException When the protocol would not accept the snapshot.
	 */
	public static function from_array( array $data ): self {
		Validator::check( $data );

		$snapshot                = new self();
		$snapshot->kind          = $data['kind'];
		$snapshot->title         = $data['title'];
		$snapshot->permalink     = $data['permalink'];
		$snapshot->content_md    = $data['content_md'];
		$snapshot->content_html  = $data['content_html'];
		$snapshot->media         = array_values( $data['media'] );
		$snapshot->author        = isset( $data['author'] ) ? $data['author'] : null;
		$snapshot->transclusions = 'thread' === $data['kind'] ? array_values( $data['transclusions'] ) : null;
		$snapshot->generated     = isset( $data['generated'] ) ? array_values( $data['generated'] ) : null;
		return $snapshot;
	}

	/**
	 * The content hash (spec §5.1). It covers content_md only, and is always computed here: a
	 * hash supplied by a source is never trusted.
	 *
	 * @param string $content_md Markdown bytes.
	 */
	public static function hash_of( string $content_md ): string {
		return 'sha256:' . hash( 'sha256', $content_md );
	}

	/**
	 * The snapshot as the strings a version row stores, column by column.
	 *
	 * @return array kind, title, permalink, content_md, content_html, media, transclusions,
	 *               generated, author.
	 */
	public function columns(): array {
		return array(
			'kind'          => $this->kind,
			'title'         => $this->title,
			'permalink'     => $this->permalink,
			'content_md'    => $this->content_md,
			'content_html'  => $this->content_html,
			'media'         => Ledger::json( $this->media ),
			'transclusions' => null === $this->transclusions ? null : Ledger::json( $this->transclusions ),
			'generated'     => null === $this->generated ? null : Ledger::json( $this->generated ),
			// No author means no assertion (spec §5.5): stored as JSON null, left out of every document.
			'author'        => null === $this->author ? 'null' : Ledger::json( (object) $this->author ),
		);
	}

	/**
	 * A fingerprint of everything a version publishes, from its stored columns.
	 *
	 * The content hash is the protocol's, and is of the Markdown alone: two versions can share it
	 * and still differ in their HTML, their page, their author or their media (spec §5.1 allows a
	 * new version with an unchanged hash). Whether something is a new version is decided by this
	 * fingerprint, which is never sent anywhere.
	 *
	 * @param array $columns A version row, or columns().
	 */
	public static function fingerprint_of( array $columns ): string {
		$parts = array();
		foreach ( array( 'kind', 'title', 'permalink', 'content_md', 'content_html', 'media', 'transclusions', 'generated', 'author' ) as $column ) {
			$parts[] = isset( $columns[ $column ] ) ? (string) $columns[ $column ] : null;
		}
		return hash( 'sha256', Ledger::json( $parts ) );
	}

	/**
	 * This snapshot's fingerprint.
	 */
	public function fingerprint(): string {
		return self::fingerprint_of( $this->columns() );
	}

	/**
	 * This snapshot's content hash.
	 */
	public function hash(): string {
		return self::hash_of( $this->content_md );
	}
}
