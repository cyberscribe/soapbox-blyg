<?php
/**
 * One origin.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Origins;

defined( 'ABSPATH' ) || exit;

/**
 * A registered origin: one blyg, mounted at one path of this site. A site can carry several.
 */
final class Origin {

	/**
	 * The origin's key, e.g. "posts".
	 *
	 * @var string
	 */
	public $key;

	/**
	 * The mount path, with leading and trailing slash, e.g. "/blyg/".
	 *
	 * @var string
	 */
	public $path;

	/**
	 * "templated" (documents behind REST routes, located by the manifest) or "fixed" (the
	 * protocol's default paths under the mount).
	 *
	 * @var string
	 */
	public $layout;

	/**
	 * Manifest title.
	 *
	 * @var string
	 */
	public $title;

	/**
	 * Feed description.
	 *
	 * @var string
	 */
	public $description;

	/**
	 * Manifest author: name, bio (optional), links.
	 *
	 * @var array
	 */
	public $author;

	/**
	 * Returns true on pages that should advertise this origin with rel="blyg".
	 *
	 * @var callable|null
	 */
	public $discover;

	/**
	 * Discovery order; lower is asked first, as with WordPress hooks.
	 *
	 * @var int
	 */
	public $priority;

	/**
	 * Where the bare mount sends a person: a URL, or a callable returning one. Without it the
	 * mount shows a small page describing the blyg.
	 *
	 * @var string|callable|null
	 */
	public $redirect;

	/**
	 * For a source the plugin can ask: given a source key, says what the source holds now, read
	 * from where it is kept and not from a cache. Returns { public: bool, fingerprint: string },
	 * or null for a key it does not know. The ledger asks it while it holds an item's lock.
	 *
	 * @var callable|null
	 */
	public $source_state;

	/**
	 * Given a list of source keys, or null for all of them, returns those whose items are live in
	 * the ledger although the source is no longer public. Asked before anything is served.
	 *
	 * @var callable|null
	 */
	public $stale_sources;

	/**
	 * Told when a stale item could not be withdrawn as it was about to be served: the source key
	 * and the reason the write failed, or null when it was simply one too many for a single
	 * request. The origin makes sure the withdrawal happens later.
	 *
	 * @var callable|null
	 */
	public $deferred;

	/**
	 * Registration order, the tie-break for priority.
	 *
	 * @var int
	 */
	public $order;

	/**
	 * The origin's base URL, e.g. https://example.com/blyg/. This is its identity on the wire
	 * (spec §12.2), and it is never hard-coded.
	 */
	public function url(): string {
		return home_url( $this->path );
	}
}
