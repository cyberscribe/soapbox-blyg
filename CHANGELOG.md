# Changelog

## 0.1.2 (unreleased)

For WordPress.org, whose review of 0.1.0 asked for a name that does not read as the Blygger
project's own, and for no bundled translations.

- **Renamed: "Soapbox for Blygger", slug `soapbox-for-blygger`.** The main file, the folder the
  archive unpacks to, the text domain and the display name change. Nothing that is data or an
  address does: `SOAPBOX_BLYG_SLUG` stays `soapbox-blyg` for good and still gives the table
  prefix and the REST namespace; hooks, functions, options, the `SoapboxBlyg\` namespace, the admin
  page slugs and `wp blyg` are as they were. A test pins them. The text domain is
  `SOAPBOX_BLYG_TEXT_DOMAIN`.
- The generator in every document is `soapbox-for-blygger/<version>` (`SOAPBOX_BLYG_GENERATOR`).
- **Beside the plugin under its earlier name.** WordPress takes the two folders for two plugins.
  The second to load stands down and says so; `Plugin::boot()` runs once whoever calls it. While
  the earlier plugin is installed, "delete all data" is held off, in the option and on the
  settings screen, because the earlier plugin's uninstall reads the same option and the tables
  are the same tables. This plugin's own uninstall removes nothing while the earlier one is there.
- **No translations in the plugin.** `load_plugin_textdomain()` is gone, the scripts' translations
  are looked for in WordPress's languages directory, and `languages/` is not in the archive. The
  repository keeps the `.pot` and the seven `.po` files as sources for translate.wordpress.org;
  the compiled files are no longer tracked.
- The build takes the slug from the header, not from the checkout's directory, and the archive's
  one top folder is `soapbox-for-blygger`. `bin/rename.sh` is gone: it renamed the data's names,
  which was only ever safe before a first release.
- `bin/switchover.sh`: from 0.1.1 as Soapbox Blyg, with a ledger, to this release, in both orders.

## 0.1.1 (unreleased)

From production (robertpeake.com, 2026-10-07):

- **With Breeze, an unpublished post stayed readable at its address.** Breeze 2.6.1 drops a post's
  page by the address `get_permalink()` gives after the change, and for a draft that is `/?p=N`:
  the page it cached under the permalink stayed, in Breeze's file cache and in Varnish. The
  plugin answers Breeze's `breeze_collect_urls_for_cache_purge` filter with the address the
  ledger has for the post. (P1)
- **The withdrawn page is shown where WordPress redirected to a 404.** `wp_old_slug_redirect()`
  redirects an address that carries one of a post's earlier slugs to the post's permalink without
  asking whether the post can be seen. For a withdrawn item's page, that redirect is now stopped
  unless the post it leads to is publicly viewable. (P2)

From QA's review of the purging in 0.1.0 (F1 to F8):

- A pin purges the item's document. (F1)
- A new plugin version, and a change to the site's name, tagline, `home` or permalink structure,
  purge every origin's documents. (F2)
- A new item's own address is not purged. A list longer than fifty addresses is cut, for a
  cache that is purged one address at a time, to what cannot wait: the origins' manifest, feed
  and index, and the document and the page of everything withdrawn. For Breeze it is queued
  through Action Scheduler. `soapbox_blyg_purge_urls` gains a second argument, that shorter
  list. (F3, and R1 of QA's review of this release)
- The bare mount is purged when the settings change, not on every item. (F4)
- When an item is withdrawn or returns, the post's page is purged. (F5)
- Site Health asks for the documents compressed, as a reader does, and probes an item in the
  templated layout too. (F6, F8)
- Each list is announced once more a little later. (F7)
- The plugin's own listeners run at priority 5; a lifetime filter that returns `NAN` is ignored;
  switching publishing on, or moving a mount, purges the new address. (F8)

- The purge a new version causes happens only once its version has been recorded. (R2)

The source is public at <https://github.com/cyberscribe/soapbox-blyg>, named in the plugin's
header (`Plugin URI`) and both readmes.

The repository no longer holds working papers or the E1 scripts, and `bin/check-tree.sh` keeps
such things out.

## 0.1.0 (unreleased)

First release.

- Publishes posts, and any post type with `blyg` support, under Blygger 0.3 at level 2.
- Versions, withdrawal endcaps and pins, in an append-only ledger of the plugin's own tables.
- Two surface layouts: templated (the manifest locates the documents, which are served through
  the REST API; spec 0.3 §16.6e) and fixed (the protocol's default paths).
- Optional Webmention receiver with structural verification (spec §15), off by default.
- Settings, Tools (backfill, verify, pins, mentions), an editor panel, Site Health tests, WP-CLI.
- Multisite: per-site ledger and per-site opt-in.

### Release candidate 2

rc1 was withdrawn after an adversarial review. Since rc1:

- `content_md` is safe to render with raw HTML allowed. The title is escaped; link and image
  addresses are encoded; code delimiters are longer than what they hold; code containing `<` or
  `[` is written as an HTML `code` element with character references.
- Nothing of a withdrawn item can be pinned. A pin records who made it.
- A post that stops being public is withdrawn whether or not its type is still ticked, and what
  was unpublished while publishing was off is withdrawn when it is switched back on.
- Webmention verification: no decompression, two attempts at most, the IANA special-purpose
  address tables, fail-closed address pinning, five requests per claim, limits by sending
  client and by resolved network.
- The feed drops code points XML forbids; a withdrawn entry links to the origin.
- No `Last-Modified` on the manifest, the feed and the index.
- Site Health notices a host that adds its own `Cache-Control`.
- Requires WordPress 6.6.

### After release candidate 2 (unreleased)

From the external review of rc2 (three High, eight Medium):

- **Nothing unpublished is served, whatever the ledger says.** Before an item, the feed or the
  index is served, the plugin checks the posts themselves and withdraws anything that is no
  longer public. If that cannot be written, the document is served without that content and
  marked not to be stored. (F1, F3)
- A publish or withdrawal that fails is queued and retried until it succeeds. (F1)
- A version can be pinned only while its post is public and unchanged since it was published. (F1)
- Every backfill goes through the same path as a save. (F2)
- Network activation reconciles each site that already has a ledger. (F3)
- A change to the title, address, author, media or HTML is a new version even when the Markdown
  is the same. (F4)
- Two saves in one second cannot leave the older one published. (F5)
- Item documents no longer send `Last-Modified`. (F6)
- Webmentions: requests are counted per client before anything else and nothing is resolved while
  answering; limits are atomic counters; a verification job is leased to one worker; a job has
  a time budget; old failures are pruned. (F7 to F9)
- A settings screen with no post type ticked means none. (F10)
- **Publishing is off until the address and layout have been chosen and saved.** Fixed paths
  is offered first. (F11)
- Activation fails clearly without InnoDB, DOM or mbstring. A lost origin lock is rebuilt.

From QA's pass on those fixes:

- One test of whether a post is public, with the site's filter, is used by the item, the feed,
  the index, the ledger and pins. The ledger will not publish a post that test rejects.
- Never a 503 on the documents: a stale item is shown as withdrawn or left out, uncached.
- A visitor's request renders nothing and writes at most five withdrawals. Repairs and
  reconciles run from cron and the admin, with backoff.
- A post type that is unregistered for a moment no longer has its posts withdrawn by a reader.
- Sites that published under the first release candidates' defaults keep publishing: settings
  are migrated once.
- The schema is upgraded on any request, and a database without InnoDB is reported, not retried.
- A password of spaces counts as a password.
- Webmentions: a busy network defers a claim rather than failing it; budgets are charged only
  when there is room; a retry waits its time; an IPv4 client written as IPv6 is one client.
- The setup notice appears only on the Plugins screen and the plugin's own.

From QA's second pass:

- The post fingerprint covers only the meta that matters (`_thumbnail_id`, the featured image,
  and keys named with `soapbox_blyg_fingerprint_meta`). Hashing all meta made every post look
  changed, which blocked pins and could stop edits publishing.
- If the check for stale items cannot be made, nothing is served that it would have covered.
- `soapbox_blyg_is_public` always runs as a logged-out visitor.
- A reconcile leaves the posts of an unregistered type alone.
- Repairs and reconciles in the admin need a manager on a screen, not an admin-ajax request.
- A Webmention is put off three times at most for a busy destination, and one client may use
  a quarter of a destination's hourly budget.
- A failed schema upgrade is detected, reported on every admin screen, and retried sparingly.
- The build pins its tools and refuses files git ignores.

Also:

- The release archive is byte-reproducible: entries carry the commit's time and fixed modes.
- `composer install` leaves `vendor-prefixed/` readable by the web server.
- Site Health's "saved but not published" record clears when that post publishes.
- An exclamation mark that ends a paragraph is no longer escaped because the next paragraph
  starts with a link.

### Release candidate 4

- Webmention verification requires the item document's exact address (blygger-spec decision
  #61). A document speaks for the blyg it names only from `{origin}items/{id}.json`, or from the
  address that blyg's manifest gives by template, which costs one more fetch: three documents
  and six requests at most. Two blygs on one host can no longer verify in each other's name.
- The documents are cached as intended behind a host that adds caching headers of its own, with
  no change to `.htaccess`. Every response states its `s-maxage` first and carries its own
  `Expires`, so an appended thirty-day `s-maxage` (Breeze's "Browser Cache" on Cloudways) and
  mod_expires change nothing. Responses that must not be stored use WordPress's own no-cache
  headers, with `no-store`.
- Site Health reads the first lifetime in `Cache-Control`, as a cache does, and warns when a copy
  is older than its lifetime. A cache holding the documents for their lifetime is no longer a
  warning.
- When a document changes, the addresses that changed are announced once a request
  (`soapbox_blyg_purge_urls`) and passed to Breeze (Varnish), W3 Total Cache and SiteGround Speed
  Optimizer. Site Health compares the copy a reader is given with the document as it is now, by
  ETag, and warns when a cache serves an old one.
- A mention's origin, document and manifest addresses must be written plainly (no `..`, no
  needless percent-encoding); the page recorded for a mention must be under its origin, and
  Tools shows the origin.
- The cache lifetime is a setting: one minute, five (the default), fifteen, or an hour. Filter:
  `soapbox_blyg_cache_lifetime`. Every duration in the plugin is a named constant.
