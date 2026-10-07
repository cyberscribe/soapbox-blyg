=== Soapbox Blyg ===
Contributors: robertpeake
Tags: blygger, feed, rss, webmention, syndication
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publishes your posts as a blyg under the Blygger protocol: permanent ids, versions, pins, and a feed other Blygger clients can read and quote.

== Description ==

Soapbox Blyg makes your WordPress site a publisher under the [Blygger protocol](https://blygger.org/spec/0.3/), an open protocol for publishing on your own domain in a way other people's software can subscribe to, archive completely, and quote exactly.

Once it is switched on, every post you publish also becomes an item of your **blyg**, at an address such as `https://example.com/blyg/`. For each item the plugin keeps:

* **A permanent id**, which never changes when you edit, rename or move the post.
* **Versions.** Each time the visible content changes, the item gets a new version. Saving without changing anything does not.
* **Withdrawal, not deletion.** Trash or delete a post and its item stays at its address, empty, marked withdrawn. Republish and it returns under the same id.
* **Pins.** Pin a version and exactly that version is served at its own address forever, so somebody can cite it and find the same words there later.

Readers get a manifest, an RSS feed that any feed reader can use, a complete archive index, and a document for every item, with the content as both Markdown and clean, self-contained HTML.

= Before you publish =

**The protocol is a draft.** This plugin implements Blygger 0.3, which is before 1.0. Its wire format may change, and the plugin will follow it. What will not change is what publishing means: every address your blyg serves is a promise to keep answering there, and a pin cannot be undone. Read "Can I undo this?" below first.

**Nothing is published until you say so.** Activating the plugin publishes nothing. You choose where the blyg lives and how it is laid out, save that, and then switch publishing on. Before you run the backfill, which is the step that puts your existing posts out, do these four things:

1. **Check what a post looks like on the blyg if you use a membership, paywall or content-restriction plugin.** The plugin renders each post as a logged-out visitor would see it, but not on the post's own page, and a plugin that hides content only there may not hide it here. See "Does it work with membership or paywall plugins?" below. Test this first: what the backfill publishes cannot be unpublished.
2. **Run the backfill's dry run** (Tools > Soapbox Blyg). It renders every post and publishes nothing. Read some of what it shows.
3. **Check Site Health** for the entries about the blyg, the one about caching above all. A cache that holds the blyg's documents would keep serving a post after you withdrew it.
4. **Be sure of the mount path and the layout.** Both are permanent from the first item published.

**This is an independent implementation.** Soapbox Blyg is not written by, endorsed by or affiliated with the authors of the Blygger protocol. The protocol's specification is at [blygger.org](https://blygger.org/spec/0.3/).

= What it does not do =

* It does not change how your site looks, and it does not replace your existing RSS feed.
* It does not read or subscribe to other blygs. It publishes.
* It makes no requests to any other server, unless you switch on Webmentions (see below).

= Works the WordPress way =

* Any post type can publish: tick it in the settings, or add `blyg` support to your own post type.
* Settings are under Settings > Soapbox Blyg; the backfill and integrity checks are under Tools > Soapbox Blyg, and need no command line.
* A panel in the editor shows each post's id, versions and pins.
* Site Health checks that the blyg is reachable and that no cache in front of your site is holding it.
* WP-CLI: `wp blyg backfill`, `status`, `pin`, `verify` and `origins`.
* Multisite: each site has its own blyg and its own switch.

== Installation ==

1. Install and activate the plugin. Nothing is published yet.
2. Go to Settings > Soapbox Blyg. Choose the mount path (the default, `/blyg/`, is the convention) and the surface layout, and save. **Both become permanent once anything is published**, so decide now.
3. On the same screen, tick "Publish this site's content as a blyg" and save again. The blyg now exists and is empty; from here on, a post joins it when you publish or update that post.
4. Visit Tools > Site Health and check the entries about the blyg.
5. Go to Tools > Soapbox Blyg and run a **dry run** of the backfill. It shows what each existing post would look like, and publishes nothing.
6. When you are happy with it, run the backfill. That publishes your existing posts.

The plugin needs a permalink structure other than "Plain".

== Frequently Asked Questions ==

= What is a blyg? =

A blyg is a publication that follows the Blygger protocol: a set of documents at one address, describing every item you have published, each with a permanent id and a version history. Software that speaks the protocol can subscribe to it, recover its whole archive, and quote a specific version of a specific item. People using an ordinary feed reader can subscribe to its feed.

= Can I undo this? =

Partly, and it is worth understanding before you start.

* **A post can be withdrawn.** Trash it, make it private or password-protect it, and its content is no longer served. This does not depend on the plugin having noticed the change: before it serves anything, it checks that the post is still public, and withdraws it there and then if it is not. Its address keeps answering, with an empty document marked withdrawn. Readers that follow the protocol drop their copy. Copies already made by anyone else are beyond your reach, as with anything published on the web.
* **A pin cannot be undone.** A pinned version is served forever, even after the post is withdrawn. Pin deliberately.
* **The address cannot be moved.** Once the blyg has an item, its mount path and layout are fixed.
* **Deactivating the plugin** keeps all data and takes the blyg offline (its addresses answer 404) until you activate it again.
* **Deleting the plugin** also keeps all data, unless you tick "Delete all Soapbox Blyg data when the plugin is deleted" in the settings. If you delete the data, every address the blyg ever served is broken for good, and reinstalling starts again with new ids.

= Which surface layout should I choose? =

**Fixed paths** (selected to begin with) serves the protocol's own file names under the mount: `feed.xml`, `items/index.json`, `items/{id}.json`. This is Blygger 0.3 as published, and every reader understands it. Many hosts cache addresses ending in `.json` and `.xml` aggressively, so check Site Health after you choose it; see the next question.

**Templated** serves the manifest at `/blyg/blyg.json` and everything else through the WordPress REST API, at addresses the manifest names. It avoids the paths hosts cache, but it follows a construct the protocol has ruled on for version 0.4 and not yet published as part of 0.3 (the manifest locates the surface). A reader has to support that, and most do not yet: one that does not will find the manifest and then get 404s. Choose it only if you know your readers can use it.

The choice is permanent once the blyg has an item, so it is made by you, on the settings screen, before publishing can be switched on.

= Site Health says a cache is holding my blyg. What do I do? =

Something in front of WordPress (your host's cache, Varnish, a CDN) is keeping copies of the blyg's documents for longer than the cache lifetime in the plugin's settings (five minutes unless you changed it). That matters: if you withdraw or edit a post, the old content keeps being served until the cache expires, which on some hosts is a month.

Exclude the blyg from that cache. With the default settings that means the path `/blyg/` and, in the templated layout, `/wp-json/soapbox-blyg/`.

* **Managed hosts** (Cloudways, Kinsta, WP Engine and similar): look for "Varnish exclusions" or "cache exclusions" in the application settings and add both paths. If there is no such setting, ask support to exclude them.
* **Varnish**: in `vcl_recv`, `if (req.url ~ "^/(blyg/|wp-json/soapbox-blyg/)") { return (pass); }`
* **Cloudflare**: add a Cache Rule for URI paths starting with `/blyg/` and `/wp-json/soapbox-blyg/`, set to "Bypass cache".
* **nginx** `fastcgi_cache` or `proxy_cache`: `if ($request_uri ~* "^/(blyg/|wp-json/soapbox-blyg/)") { set $skip_cache 1; }`, with `$skip_cache` wired to `fastcgi_cache_bypass` and `fastcgi_no_cache`.

Then reload Site Health. Pinned versions never change and are safe to cache, but excluding the whole path is simpler.

= Site Health says my host adds its own caching instructions =

Many hosts add a caching instruction of their own to everything WordPress sends, often with a lifetime of a month. The plugin expects that: it states its own lifetime first, and a cache that is given the same instruction twice follows the first (RFC 9111, section 4.2.1). So an extra instruction after the plugin's is harmless, and Site Health does not warn about it.

It warns when the first lifetime a cache would read is longer than the one in the plugin's settings. Either your server puts its instruction before the plugin's, or something on the site has removed the plugin's: a plugin or theme using the `soapbox_blyg_cache_control` filter must keep `s-maxage` in the value it returns. If it is the server, adding the path to a cache's exclusion list does not remove the header; ask your host's support to stop adding a `Cache-Control` header for `/blyg/` and `/wp-json/soapbox-blyg/` (with the default settings), then reload Site Health.

= Does it work with Breeze, and with Cloudways? =

Yes, with Breeze's "Browser Cache" option on or off, and with no change to `.htaccess`. With that option on, the server adds a `max-age` chosen by content type to any response that has no `Expires`; and on a site where the option was switched on under Breeze 2.2.24 or earlier, every response also leaves with a thirty-day `s-maxage` appended, because the rule that older Breeze wrote is still in `.htaccess`. The plugin sends its own `s-maxage` first and its own `Expires`, so neither changes how long its documents are cached. Breeze's page cache does not store them either: the plugin marks each response with the `DONOTCACHEPAGE` constant, which Breeze and most page caches honour. Adding `/blyg/` and `/wp-json/soapbox-blyg/` to "Never cache these URLs" does no harm, and is not needed.

If your site still has that older rule, saving Breeze's settings once makes Breeze rewrite its block without it. After installing or updating, purge Breeze and Varnish once, so that nothing cached earlier is left, and look at Site Health.

Cloudways' Varnish keeps a document for as long as its own configuration says, whatever lifetime the response states. So whenever a document of the blyg changes, the plugin asks Breeze to purge its address from Varnish; see the next question.

= Which caches does Soapbox Blyg purge? =

When a post is published, edited, withdrawn or restored, or the blyg's settings change, the plugin works out which of the blyg's addresses changed (the manifest, the feed, the archive index, the item's own document and the mount) and tells the cache plugin beside it, once, at the end of the request:

* **Breeze** (Cloudways): Varnish is purged for those addresses. Cloudways' Varnish keeps what it caches for hours whatever lifetime a response states, so this is what makes a withdrawal prompt there. Without Breeze, add your mount path as a Varnish exclusion in the Cloudways panel.
* **W3 Total Cache**: each address is flushed from whichever of its caches are on, Varnish and a full-site CDN included.
* **SiteGround Speed Optimizer**: each address is purged from SiteGround's dynamic cache.

The page caches of these and of LiteSpeed Cache, WP Super Cache and WP Fastest Cache never hold the blyg's documents to begin with: the plugin marks each response `DONOTCACHEPAGE`, which all of them honour. For any other cache, hook the `soapbox_blyg_purge_urls` action, which is given the list of addresses, or exclude the mount path from the cache.

Otherwise your server's cache defaults apply. A withdrawn item stops being served once your cache expires it, and Site Health tells you whether the copy being served is the current one.

= How long can a withdrawn post still be seen? =

For as long as the cache lifetime under Settings > Soapbox Blyg > Caching: five minutes unless you change it, and one minute, fifteen minutes or an hour if you do. When you withdraw or edit a post, your site answers with the new state at once; readers and caches that already hold a copy may keep using it until that time is up, and then ask again. A reader behind a cache can hold one copy after the other, so allow twice the lifetime before expecting everyone to have seen a withdrawal. Shorter means a withdrawal is seen sooner; longer means fewer requests reach your site. The same time applies to "not found": an address asked for just before its item is published can answer 404 for that long.

Pinned versions are the exception. They never change, so they are cached for a year, and a pin cannot be withdrawn.

= Site Health says the blyg's documents are behind a login =

In the templated layout the documents are served through the REST API, and something on your site is restricting the REST API to logged-in users. The usual causes are a security plugin with a "disable REST API" or "require authentication for REST" setting, or a plugin whose only job is to disable the REST API. Allow anonymous access to the `soapbox-blyg/v1` namespace in that plugin, or choose the fixed layout before you publish.

= Does it work with "Plain" permalinks? =

No. A blyg lives at an address of its own, which needs WordPress's rewrite rules. Choose any other permalink structure.

= What happens when I replace an image? =

The protocol asks that an image address always serves the same picture once it has been published. If you need to change an image in a published post, upload the new one as a new file rather than replacing the old file in place.

= What are Webmentions, and what happens if I switch them on? =

When another blyg quotes one of your items, answers it or forks it, that site can tell yours, by sending a Webmention. It is off by default.

If you switch it on (Settings > Soapbox Blyg > Mentions), then for each mention your site receives it fetches the sender's page and its item document, and sometimes that blyg's manifest, to check that the document really refers to your item. See "External requests" below for exactly what that involves. Verified mentions are listed under Tools > Soapbox Blyg, for administrators. Nothing is shown to visitors, and nothing from the other site is stored except its address, its item's id and its author's published name.

If the [Webmention](https://wordpress.org/plugins/webmention/) plugin is active, it keeps the Webmention endpoint advertised on your pages, and this plugin advertises its own only in the blyg's manifest.

= Why did a post get a new version when I did not edit it? =

A version is made when anything the blyg publishes about the post changes: its text, its title, its address (so a change of slug is a version), its author's name, its featured image, or the HTML readers are shown even where the Markdown comes out the same. It can also be a change made by a plugin or theme update that alters how the post's content is rendered. Tools > Soapbox Blyg > "Verify with render drift" lists posts that would publish a new version if saved now, so you can check after an update.

= Does it work with membership or paywall plugins? =

Test it before you publish anything, and above all before you run the backfill: what is published cannot be unpublished, only withdrawn. The plugin renders each post as a logged-out visitor would see it, by running the post through `the_content` outside the main loop. A plugin that hides content only inside the loop, or only on the post's own page, may not hide it there, and the full text would then be published in the blyg. Publish a test post, open its item document from the Blyg panel in the editor, and look at what it contains. If it shows more than a visitor should see, leave that post type unticked, or exclude posts with the `soapbox_blyg_is_public` filter.

= Does it work on multisite? =

Yes. Each site has its own blyg, its own settings and its own data. However the plugin is activated, publishing is off on a site until that site's administrator has chosen its address and layout and switched it on.

== External requests ==

With its default settings this plugin makes no requests to any other server, and sends no data anywhere.

If, and only if, you switch on "Accept Webmentions from other blygs":

* When a Webmention arrives, your site later fetches at most three documents to verify it: the source address the sender gave; the item document that page points to; and, when that document is not at the protocol's usual address for it, the manifest (`blyg.json`) of the blyg it says it belongs to, which is asked where that blyg keeps its items. Counting redirects, that is six HTTP GET requests at most, so three documents leave room for three redirects between them: a sender whose addresses each redirect twice will not verify, and should send the final addresses. Over its whole life a mention can be tried twice and put off three times, so thirty requests is the most one mention can ever cause. They go to the sender's own server, whose address the sender chose, or to wherever it redirects.
* The request carries a User-Agent naming this plugin and your site's address, as any HTTP request reveals your server's IP address. No other data is sent.
* Requests are refused to private, loopback, link-local and other reserved network addresses, IPv4 and IPv6, at every redirect. Each is limited to 5 seconds and 1 MB, and a compressed response is refused rather than unpacked. A mention is tried twice at most, and put off at most three times when the destination has had its hour's requests. Mentions are rate-limited by who sends them, by the publication they name and overall, and so are the requests your site makes to any one network: when that limit is reached a mention waits for the next hour instead of failing. The limits are counted per clock hour, so across the turn of an hour a sender can use one hour's allowance and then the next.
* To apply the limit by sender, the plugin keeps a scrambled form of the network address each Webmention came from. It cannot be turned back into the address. If your site is behind a reverse proxy or a CDN, every sender appears to come from the proxy: use the `soapbox_blyg_webmention_client` filter to name the visitor's address.
* There is no third-party service involved: no account, no API, no terms beyond those of whichever site sent the mention.

How a mention is handled. Your site answers the sender at once and looks nothing up while it does: the sender's address is resolved, checked and fetched afterwards, by a background job. That job stops after twenty seconds in all, checked between each step; one step it cannot cut short is a DNS lookup, which can take as long as your server's own resolver allows. Each sender is limited by the number of requests it makes, whether or not they are accepted.

Known limits. Each request is pinned to the address that was checked, which closes DNS rebinding. That needs PHP's curl extension, which nearly every host has. A site without it, or one that sends its outbound requests through a proxy (`WP_PROXY_HOST`), cannot pin a request, so it verifies no Webmentions: they are accepted and then marked as failed. The templated layout's manifest declares Blygger "0.3", because the specification does not say what a manifest that uses the 0.4 construct should declare. IPv6-only senders are refused: a sender's host must have an IPv4 address, and no address it has, in either family, may be private. WordPress only allows such requests on ports 80, 443 and 8080; a sender on another port is refused unless you add it with WordPress's `http_allowed_safe_ports` filter. A mention is believed only when its item document is at exactly the address its own blyg gives for it, written plainly: an address with `..` in it, or with characters percent-encoded that need not be, is refused. A document kept on another host than its blyg (a CDN, say) cannot be sent as the source itself; send the page that links to it, which must be on the blyg's own host. In the templated layout, your own items are at addresses only your manifest names, so a receiver that implements Blygger 0.3 alone cannot verify a mention that names one of your items as its source; a receiver that reads the manifest's templates can. That is one more reason the fixed layout is the one selected to begin with.

Caching. The plugin states its lifetime first, and relies on caches using the first of a repeated instruction, as RFC 9111 says and Varnish does. A cache that does not follow that rule could still use a lifetime your host appends. nginx's `proxy_cache` and `fastcgi_cache` are one such case: when the host's instruction arrives as a separate `Cache-Control` header line after the plugin's, nginx uses the last line. This has not been tested with nginx in front. Site Health asks for the documents as a reader would and compares what it is given with the documents as they are now; if it is given an old copy, purge that cache and exclude the blyg's paths from it as described above.

== For developers ==

The functions below are the stable API. Classes under `src/` are internal. Guard calls with `function_exists()`.

`soapbox_blyg_register_origin( string $key, array $args ): void`
`soapbox_blyg_publish( string $origin, string $source_key, ?array $snapshot, array $opts = [] ): array`
`soapbox_blyg_reconcile( string $origin, array $snapshots_by_key, array $opts = [] ): array`
`soapbox_blyg_render_html( string $html, string $title, array $opts = [] ): array`
`soapbox_blyg_pin( string $id, int $version ): void`
`soapbox_blyg_get_status( string $origin, string $source_key ): ?array`
`soapbox_blyg_get_origin_url( string $origin ): string`

**Publishing your own post type.** This is all it takes:

`add_action( 'init', function () {
	register_post_type( 'book', array(
		'public'   => true,
		'label'    => 'Books',
		'supports' => array( 'title', 'editor', 'thumbnail', 'blyg' ),
	) );
} );`

Or, for a post type you do not control: `add_post_type_support( 'book', 'blyg' );`

**Publishing something that is not a post.** Register an origin, which is a second blyg at its own address, and hand the plugin snapshots. It decides what is a new version.

`add_action( 'soapbox_blyg_register_origins', function () {
	soapbox_blyg_register_origin( 'notes', array(
		'path'   => '/notes/',
		'title'  => 'Notes',
		'author' => array( 'name' => 'Ada Example' ),
	) );
} );

$rendered = soapbox_blyg_render_html( $html, $title );
soapbox_blyg_publish( 'notes', 'note:42', array(
	'kind'         => 'fragment',
	'title'        => $title,
	'permalink'    => 'https://example.com/notes/42/',
	'content_md'   => $rendered['content_md'],
	'content_html' => $rendered['content_html'],
	'media'        => $rendered['media'],
) );
soapbox_blyg_publish( 'notes', 'note:42', null ); // Withdraws it.`

Rules for snapshots: `permalink` must be an absolute `http` or `https` URL, since it is served as the item's page; a `thread` must carry a `transclusions` array and a `fragment` must not; `content_hash` is always computed by the plugin. Give each item a page of its own if you want Webmentions to be able to name it by page.

**Actions:** `soapbox_blyg_register_origins`, `soapbox_blyg_event` (event, id, version, origin), `soapbox_blyg_pinned` (id, version, origin), `soapbox_blyg_mention` (status, mention, fields).

**Actions:** `soapbox_blyg_purge_urls` (the addresses whose documents changed in this request; see "Which caches does Soapbox Blyg purge?"), `soapbox_blyg_event`, `soapbox_blyg_pinned`, `soapbox_blyg_forget_stale`.

**Filters:** `soapbox_blyg_origin_args`, `soapbox_blyg_is_public`, `soapbox_blyg_snapshot`, `soapbox_blyg_rendered_body`, `soapbox_blyg_allowed_html`, `soapbox_blyg_cache_lifetime`, `soapbox_blyg_cache_control`, `soapbox_blyg_discovery_origin`, `soapbox_blyg_webmention_limits`, `soapbox_blyg_webmention_client`, `soapbox_blyg_fingerprint_meta`.

**Withholding posts with `soapbox_blyg_is_public`.** Return false for a post and it is kept off the blyg, and withdrawn if it is already there: at its next save, and whenever anything of it would be served. Three things to know when you write one:

* It is always run as a logged-out visitor, whoever is making the request. `is_user_logged_in()` and `current_user_can()` are false inside it. The question is whether the post is public, not whether the current user may read it.
* If it reads post meta, name the keys with the `soapbox_blyg_fingerprint_meta` filter, so that a change to one counts as a change to the post.
* The feed and the archive index check every post through your filter, and keep the answer for up to a minute. A change to a post, its meta or its terms is seen at once. If your filter depends on anything else (an option, a membership level), call `do_action( 'soapbox_blyg_forget_stale' )` when that changes, or the feed and index may be up to a minute behind. An item's own document is always checked afresh.

If a plugin adds its own text to every post through `the_content` (a "Share this" heading, a list of related posts), remove it in `soapbox_blyg_rendered_body`, or it will be published in every item.

**Capabilities:** `soapbox_blyg_manage` (maps to `manage_options`) and `soapbox_blyg_pin_item` (maps to being able to edit and publish the post). Change either through `map_meta_cap`.

**Templates:** copy `templates/soapbox-blyg-withdrawn.php` or `templates/soapbox-blyg-origin.php` into your theme to change the page shown for a withdrawn item, or at the bare address of an origin you registered.

JavaScript sources are in `assets/src/`; the built files in `build/` are produced from them with `@wordpress/scripts`. The bundled library, league/html-to-markdown (MIT), is in `vendor-prefixed/` under a prefixed namespace.

== Screenshots ==

1. Settings: address, identity, content and data.
2. Tools: each blyg on the site, the backfill, verify and pins.
3. The Blyg panel in the editor, with a post's versions and pins.

== Changelog ==

= 0.1.0 =
* First release. Blygger 0.3, level 2. Posts and any post type with `blyg` support; versions, withdrawal and pins; templated and fixed surface layouts; an optional Webmention receiver with structural verification; Site Health tests; WP-CLI.

== Upgrade Notice ==

= 0.1.0 =
First release.
