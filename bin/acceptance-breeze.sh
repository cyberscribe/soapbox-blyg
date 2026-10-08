#!/usr/bin/env bash
#
# B12 and B13: the caching headers of every kind of response behind real Apache and real Breeze,
# in both layouts, at the default cache lifetime and at the longest offered. Against the "breeze"
# wp-env cell (tests/env/breeze.json, http://localhost:8896), which it resets. Never point it at
# anything else.
#
#   npx wp-env start --config tests/env/breeze.json
#   bin/acceptance-breeze.sh [templated|fixed]     (default: both)
#
# Breeze 2.6.1 is the plugin from wordpress.org. Each lifetime is run through four states of
# its "Browser Cache":
#
#   legacy     The block an older Breeze wrote (up to 2.2.24), still in .htaccess under 2.6.1:
#              `Header append Cache-Control "s-maxage=2592000"` on every response, and
#              mod_expires rules, with a default of a month. This is robertpeake.com. The block
#              is tests/env/breeze-2.2.24-browser-cache.htaccess, captured from that release.
#   off        Switched off through 2.6.1's own settings code, which removes the block.
#   on         Switched on through 2.6.1, which writes its own block: mod_expires rules and no
#              s-maxage.
#   (In every state: the content type of each response, and that a change to the blyg hands
#   Breeze the addresses to purge from Varnish.)
#   two lines  (default lifetime only) A stand-in for PHP-FPM, where an appended header arrives
#              as a second Cache-Control line: tests/env/breeze-separate-line.conf.
#
# Before each state's checks, two of WordPress's own responses are read, to show that the cell
# does to them what that state should. If it did not, the checks after would prove nothing.
#
# What this cannot show. Apache answers directly: there is no Varnish and no nginx cache in
# front, so this is what is sent to a cache, not what a cache does with it. And wp-env runs
# mod_php, which merges an appended header into the plugin's line; production runs PHP-FPM, which
# sends it as a line of its own. The two-lines state imitates that shape with a different
# directive. Whether the first line wins is then up to the cache: Varnish joins the lines and
# reads the first value; nginx reads the last line.

set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
export WP_ENV_CELL=breeze
WP="$ROOT/bin/wp-env-wp"
SITE="http://localhost:8896"
WHICH="${1:-both}"
WEB="$(docker ps --format '{{.Names}}' | grep '^wp-env-env-breeze-.*-wordpress-1$' | grep -v tests | head -n 1)"
[ -n "$WEB" ] || { echo "the breeze cell is not running (npx wp-env start --config tests/env/breeze.json)" >&2; exit 2; }
HOST_ADDS="s-maxage=2592000"
# What Breeze's mod_expires rule gives a feed that has no Expires of its own: "access plus 1 hour".
BREEZE_FEED="max-age=$((60 * 60))"

# The lifetimes, from the plugin's own constants: the default, the longest offered, and a pin's.
read -r DEFAULT LONGEST YEAR <<<"$($WP eval 'echo SoapboxBlyg\Surface\Caching::DEFAULT_LIFETIME, " ", max( SoapboxBlyg\Surface\Caching::LIFETIMES ), " ", SoapboxBlyg\Surface\Caching::PIN_LIFETIME;')"
L="$DEFAULT"
STATE="legacy"

pass=0; fails=0
ok()   { echo "  ok   $*"; pass=$((pass+1)); }
bad()  { echo "  FAIL $*"; fails=$((fails+1)); }
step() { echo; echo "[$*]"; }
jget() { python3 -c "import json,sys; d=json.load(sys.stdin); print(d$1)"; }
headers() { curl -s -D - -o /dev/null "$@" | tr -d '\r'; }
# The Cache-Control lines of a response, as sent: one per line, in order.
cc_lines() { grep -i '^cache-control:' | sed 's/^[^:]*: *//'; }

# check <label> <kind: live|pin|held> <status> <type: json|rss|text|none> <url> [curl arguments]
# What the host adds, and in what shape, follows from $STATE.
check() {
  local label="$1" kind="$2" status="$3" type="$4"; shift 4
  local H lines first_line joined first want problems="" n ctype
  H=$(headers "$@")
  lines=$(cc_lines <<<"$H")
  n=$(grep -c . <<<"$lines")
  first_line=$(head -n 1 <<<"$lines")
  joined=$(paste -sd, - <<<"$lines" | sed 's/,\([^ ]\)/, \1/g')
  first=$(grep -oE 's-maxage=[0-9]+' <<<"$joined" | head -n 1)
  grep -q "^HTTP/[0-9.]* $status" <<<"$H" || problems+=" status-$(sed -n '1s/^HTTP[^ ]* \([0-9]*\).*/\1/p' <<<"$H")"
  case "$kind" in
    live) want="public, max-age=$L, s-maxage=$L" ;;
    pin)  want="public, max-age=$YEAR, s-maxage=$YEAR, immutable" ;;
    held) want="" ;;
  esac
  if [[ "$kind" == "held" ]]; then
    grep -qE '(^|, )no-store(,|$)' <<<"$first_line" || problems+=" not-no-store"
    grep -q 'public' <<<"$joined" && problems+=" public"
  else
    # The plugin's is the first thing on the first line, so its s-maxage is the first there is.
    [[ "$first_line" == "$want"* ]] || problems+=" ours-is-not-first"
    [[ "$first" == "s-maxage=${want##*s-maxage=}" || "$first" == "s-maxage=$L" || "$first" == "s-maxage=$YEAR" ]] || problems+=" first-s-maxage"
    # No max-age but the plugin's: mod_expires adds one, with an Expires, to a response that has none.
    [[ "$(grep -oE '(^|[ ,])max-age=[0-9]+' <<<"$joined" | wc -l | tr -d ' ')" == "1" ]] || problems+=" another-max-age"
  fi
  case "$STATE" in
    legacy)      [[ "$n" == "1" && "$joined" == *", $HOST_ADDS" ]] || problems+=" host-did-not-append(nothing-is-being-tested)" ;;
    "two lines") [[ "$n" == "2" && "$(tail -n 1 <<<"$lines")" == "$HOST_ADDS" ]] || problems+=" not-two-lines(nothing-is-being-tested)"
                 [[ "$kind" == "held" || "$first_line" == "$want" ]] || problems+=" first-line-is-not-exactly-ours" ;;
    *)           [[ "$n" == "1" ]] || problems+=" $n-lines"; grep -q '2592000' <<<"$joined" && problems+=" a-host-header-in-this-state" ;;
  esac
  # The content type is the one the protocol names (Blygger spec §7), exactly, and stated once.
  ctype=$(grep -i '^content-type:' <<<"$H" | sed 's/^[^:]*: *//' | paste -sd'|' -)
  case "$type" in
    json) [[ "$ctype" == "application/json; charset=utf-8" ]] || problems+=" content-type($ctype)" ;;
    rss)  [[ "$ctype" == "application/rss+xml; charset=utf-8" ]] || problems+=" content-type($ctype)" ;;
    text) [[ "$ctype" == "text/plain; charset=utf-8" ]] || problems+=" content-type($ctype)" ;;
    none) [[ -z "$ctype" ]] || problems+=" content-type($ctype)" ;;
  esac
  # One Expires, and it is the plugin's: the lifetime after Date, or long past.
  [[ "$(grep -ci '^expires:' <<<"$H")" == "1" ]] || problems+=" expires-count-$(grep -ci '^expires:' <<<"$H")"
  python3 - "$kind" "$(sed -n 's/^[Dd]ate: //p' <<<"$H")" "$(sed -n 's/^[Ee]xpires: //p' <<<"$H" | head -n 1)" "$L" "$YEAR" <<'PY' || problems+=" expires-value"
import sys
from email.utils import parsedate_to_datetime
kind, date, expires = sys.argv[1:4]
try:
    after = (parsedate_to_datetime(expires) - parsedate_to_datetime(date)).total_seconds()
except Exception:
    sys.exit(1)
want = {"live": int(sys.argv[4]), "pin": int(sys.argv[5])}.get(kind)
sys.exit(0 if (after < 0 if want is None else abs(after - want) <= 5) else 1)
PY
  [[ -z "$problems" ]] && ok "$label: $(paste -sd'|' - <<<"$lines" | sed 's/|/  |  /g')" || bad "$label:$problems :: $(paste -sd'|' - <<<"$lines") :: expires=$(sed -n 's/^[Ee]xpires: //p' <<<"$H" | paste -sd'|' -)"
}

site_health() { # expected status
  local got
  # Run in the web container: Site Health requests the site's own documents, through Apache and
  # .htaccess, and the CLI container has no web server to ask.
  got=$(docker exec "$WEB" php -r 'require "/var/www/html/wp-load.php"; $r = SoapboxBlyg\Admin\SiteHealth::test_edge_cache(); echo $r["status"], " (", $r["label"], ")";')
  [[ "$got" == "$1 "* ]] && ok "Site Health says $got" || bad "Site Health says $got, expected $1"
}

# B14: with Breeze active, a change to the blyg hands Breeze the addresses that changed, through
# the action Breeze purges Varnish on. A listener ahead of Breeze's own shows what it was given.
# (There is no Varnish here: what Breeze then does with the list is not shown.)
# F3 to F5: a new item's own address was never served, so publishing names the origin's three
# documents and no more; the bare mount changes only with the settings; and when the item goes,
# its document and the post's page are named too.
purged() {
  local got made gone
  got=$($WP eval '
    add_action( "breeze_purge_varnish_urls", function ( $urls ) { echo is_array( $urls ) ? implode( " ", $urls ) : "not-an-array", "\n"; }, 1 );
    $id = wp_insert_post( array( "post_status" => "publish", "post_title" => "Purged " . microtime(), "post_content" => "<p>x</p>" ) );
    SoapboxBlyg\Plugin::instance()->purge->flush();
    wp_delete_post( $id, true ); SoapboxBlyg\Plugin::instance()->purge->flush();' 2>/dev/null)
  made=$(sed -n 1p <<<"$got"); gone=$(sed -n 2p <<<"$got")
  if [[ "$(tr ' ' '\n' <<<"$made" | sort | paste -sd' ' -)" == "$(printf '%s\n' "$MANIFEST" "$FEED" "$INDEX" | sort | paste -sd' ' -)" ]]; then
    ok "publishing a post hands Breeze the manifest, the feed and the index, and nothing else"
  else
    bad "on publishing, Breeze was given: ${made:-nothing}"
  fi
  if grep -qF "$MANIFEST" <<<"$gone" && grep -qF "$FEED" <<<"$gone" && grep -qF "$INDEX" <<<"$gone" && grep -qE "${ITEM%/*}/[0-9a-z]{26}" <<<"$gone" && grep -qE "$SITE/[0-9]{4}/[0-9]{2}/purged-" <<<"$gone" && ! grep -qF "$SITE/blyg/ " <<<"$gone "; then
    ok "deleting it adds the item's document and the post's page, and not the mount ($(wc -w <<<"$gone" | tr -d ' ') in the request)"
  else
    bad "on deleting, Breeze was given: ${gone:-nothing}"
  fi
}

# P1 (production, 2026-10-07): a post set back to Draft went on being served, in full, at its
# permalink, from Breeze's page cache. Breeze drops a post's page by the address get_permalink()
# gives after the change, and for a draft that is /?p=N. The plugin adds the address the post was
# published at. Run with the permalinks of robertpeake.com, and as a user, since Breeze purges
# only for someone who may edit the post.
unpublished() {
  local p u body
  step "$LAYOUT: a post set back to Draft leaves Breeze's page cache"
  $WP rewrite structure '/archives/%post_id%-%postname%.html' --hard >/dev/null
  $WP eval '$o = (array) get_option( "breeze_basic_settings" ); $o["breeze-active"] = "1"; update_option( "breeze_basic_settings", $o );' >/dev/null
  $WP eval 'Breeze_ConfigCache::factory()->write(); Breeze_ConfigCache::factory()->write_config_cache();' >/dev/null 2>&1
  p=$($WP post create --user=1 --post_status=publish --post_title="Taken down" --post_content="<p>Only-while-published.</p>" --porcelain)
  u="$SITE/archives/$p-taken-down.html"
  curl -s -o /dev/null "$u"
  body=$(curl -s "$u")
  grep -q 'Cache served by breeze' <<<"$body" && grep -q 'Only-while-published' <<<"$body" && ok "control: Breeze serves the post's page from its cache" || bad "control: the page is not served from Breeze's cache (nothing is being tested)"
  $WP post update "$p" --user=1 --post_status=draft >/dev/null
  body=$(curl -s "$u")
  grep -q 'Only-while-published' <<<"$body" && bad "the article is still served at ${u#$SITE} after Draft" || ok "after Draft the article is no longer served at ${u#$SITE}"
  grep -q 'rel="alternate" type="application/json"' <<<"$body" && ok "the withdrawn page is" || bad "the withdrawn page is not served there"
  $WP post update "$p" --user=1 --post_status=publish >/dev/null
  curl -s "$u" | grep -q 'Only-while-published' && ok "published again, the article is back" || bad "published again, the article is not back"
  $WP post delete "$p" --force >/dev/null
}

apache() { # reload after a change to its configuration
  docker exec -u root "$WEB" sh -c 'apache2ctl -t 2>/dev/null && apache2ctl graceful 2>/dev/null' || bad "Apache refused its configuration"
  sleep 2
}

browser_cache() { # 1 | 0, through Breeze 2.6.1's own option and the code its settings screen runs on save
  # Two processes: Breeze reads its options once per request, as it would on the request after a save.
  $WP eval '$o = (array) get_option( "breeze_basic_settings" ); $o["breeze-active"] = "1"; $o["breeze-browser-cache"] = "'"$1"'"; update_option( "breeze_basic_settings", $o );' >/dev/null
  $WP eval 'Breeze_Configuration::update_htaccess();' >/dev/null
}

htaccess_has() { $WP eval 'echo substr_count( (string) file_get_contents( ABSPATH . ".htaccess" ), "'"$1"'" );'; }

enter() { # a state
  STATE="$1"
  step "$LAYOUT, lifetime $L, Browser Cache: $STATE"
  docker exec -u root "$WEB" sh -c ': > /etc/apache2/conf-enabled/zz-breeze-separate-line.conf'
  case "$STATE" in
    legacy)
      browser_cache 0
      $WP eval '$f = ABSPATH . ".htaccess"; file_put_contents( $f, file_get_contents( "'"$ROOT"'/tests/env/breeze-2.2.24-browser-cache.htaccess" ) . file_get_contents( $f ) );' >/dev/null
      # The option says on, as it would on a site that switched it on long ago; nothing is saved, so 2.6.1 rewrites nothing.
      $WP eval '$o = (array) get_option( "breeze_basic_settings" ); $o["breeze-browser-cache"] = "1"; update_option( "breeze_basic_settings", $o );' >/dev/null
      [[ "$(htaccess_has 'Header append Cache-Control \"s-maxage=2592000\"')" == "1" ]] && ok "the block Breeze 2.2.24 wrote is in .htaccess, under Breeze $($WP plugin get breeze --field=version)" || bad "the legacy block is not in .htaccess"
      apache
      CC=$(headers "$SITE/wp-json/" | cc_lines | paste -sd'|' -); [[ "$CC" == "max-age=0, $HOST_ADDS" ]] && ok "control, core's REST index: $CC" || bad "control, core's REST index: $CC"
      CC=$(headers "$SITE/feed/" | cc_lines | paste -sd'|' -);    [[ "$CC" == *"$BREEZE_FEED, $HOST_ADDS" ]] && ok "control, core's feed: $CC" || bad "control, core's feed: $CC"
      ;;
    off)
      browser_cache 0
      [[ "$(htaccess_has 'Header append Cache-Control')$(htaccess_has 'ExpiresByType application/json')" == "00" ]] && ok "Breeze 2.6.1 removed the block, the legacy one included" || bad "a Browser Cache block is still in .htaccess"
      apache
      CC=$(headers "$SITE/wp-json/" | cc_lines | paste -sd'|' -); [[ "$CC" != *max-age* ]] && ok "control, core's REST index: '${CC}'" || bad "control, core's REST index: $CC"
      ;;
    on)
      browser_cache 1
      [[ "$(htaccess_has 'Header append Cache-Control')$(htaccess_has 'ExpiresByType application/json')" == "01" ]] && ok "Breeze 2.6.1 wrote its own block: mod_expires rules, no s-maxage" || bad "not the block 2.6.1 writes"
      apache
      CC=$(headers "$SITE/wp-json/" | cc_lines | paste -sd'|' -); [[ "$CC" == "max-age=0" ]] && ok "control, core's REST index: $CC" || bad "control, core's REST index: $CC"
      CC=$(headers "$SITE/feed/" | cc_lines | paste -sd'|' -);    [[ "$CC" == *"$BREEZE_FEED" ]] && ok "control, core's feed: $CC" || bad "control, core's feed: $CC"
      ;;
    "two lines")
      browser_cache 0
      docker cp "$ROOT/tests/env/breeze-separate-line.conf" "$WEB:/etc/apache2/conf-enabled/zz-breeze-separate-line.conf" >/dev/null
      apache
      CC=$(headers "$SITE/wp-json/" | cc_lines | paste -sd'|' -); [[ "$CC" == "$HOST_ADDS" ]] && ok "control, core's REST index: $CC" || bad "control, core's REST index: $CC"
      ;;
  esac
}

documents() {
  check "manifest" live 200 json "$MANIFEST"
  check "feed" live 200 rss "$FEED"
  check "items index" live 200 json "$INDEX"
  check "item" live 200 json "$ITEM"
  check "item, HEAD" live 200 json -I "$ITEM"
  check "pin" pin 200 json "$PIN"
  check "not found (404)" live 404 text "${ITEM%/*}/nothing-here"
  check "the bare mount (302, text/plain)" live 302 text "$SITE/blyg/"
  # A route that takes no POST: 405 from the rewrite path, 404 from the REST one. Either way it is the plugin's answer.
  check "POST refused" live "40[45]" text -X POST "$ITEM"
  check "preflight (OPTIONS, 204)" live 204 none -X OPTIONS -H 'Origin: https://reader.example' -H 'Access-Control-Request-Method: GET' "$ITEM"
  etag=$(headers "$FEED" | sed -n 's/^[Ee][Tt]ag: //p')
  check "feed, not modified (304)" live 304 none -H "If-None-Match: $etag" "$FEED"

  # More stale items than one request may withdraw: the rest are held back, and that response
  # must not be stored anywhere (it is the one that keeps a post that went private out of the feed).
  local ids="" tag="Held $L ${STATE// /-} "
  for i in 1 2 3 4 5 6 7; do ids+="$($WP post create --post_status=publish --post_title="$tag$i" --post_content="<p>x</p>" --porcelain),"; done
  curl -s -o /dev/null "$FEED"
  $WP db query "UPDATE wp_posts SET post_status='private' WHERE ID IN (${ids%,})" >/dev/null
  $WP cache flush >/dev/null 2>&1; $WP eval 'do_action( "soapbox_blyg_forget_stale" );' >/dev/null
  check "feed with items held back (no-store)" held 200 rss "$FEED"
  curl -s "$FEED" | grep -q "$tag" && bad "a held item is in the feed" || ok "and no held item is in it"

  site_health good
  purged
}

run() {
  LAYOUT="$1"; L="$DEFAULT"
  step "$LAYOUT: a fresh site, Breeze 2.6.1 active"
  npx wp-env reset development --config tests/env/breeze.json >/dev/null 2>&1
  $WP rewrite structure '/%year%/%monthnum%/%postname%/' --hard >/dev/null
  $WP plugin activate breeze soapbox-blyg >/dev/null 2>&1
  [[ "$($WP plugin get breeze --field=version)" == "2.6.1" ]] && ok "Breeze 2.6.1" || bad "Breeze is $($WP plugin get breeze --field=version)"
  docker exec -u root "$WEB" sh -c 'a2enmod -q headers expires env >/dev/null' && ok "mod_headers, mod_expires and mod_env are loaded, as on the host" || bad "could not load the Apache modules"

  step "$LAYOUT: publish and pin"
  $WP option update soapbox_blyg_settings '{"enabled":true,"mount":"/blyg/","layout":"'"$LAYOUT"'"}' --format=json >/dev/null
  $WP rewrite flush >/dev/null 2>&1
  $WP blyg origins | grep -q "posts.*$LAYOUT" && ok "the layout is $LAYOUT" || bad "the layout is not $LAYOUT"
  P=$($WP post create --post_status=publish --post_title="Breeze A" --post_content="<p>First.</p>" --porcelain)
  $WP post update "$P" --post_content="<p>Second.</p>" >/dev/null
  PIN=$($WP blyg pin "$P" 1 --yes | sed -n 's/^Success: Pinned: //p')
  ITEM=$($WP blyg status "$P" --format=json | jget '["url"]')
  read -r MANIFEST FEED INDEX <<<"$($WP eval '$p = SoapboxBlyg\Plugin::instance(); $o = $p->registry->get( "posts" ); echo $p->locations->manifest( $o ), " ", $p->locations->feed( $o ), " ", $p->locations->index( $o );')"
  [[ -n "$PIN" && -n "$ITEM" && -n "$INDEX" ]] && ok "documents: ${MANIFEST#$SITE} ${FEED#$SITE} ${INDEX#$SITE} ${ITEM#$SITE} ${PIN#$SITE}" || bad "could not find the documents"

  for L in "$DEFAULT" "$LONGEST"; do
    # Through the settings' own sanitiser, as the screen's select would send it.
    $WP eval 'update_option( SoapboxBlyg\Settings::OPTION, array( "cache_lifetime" => "'"$L"'" ) + SoapboxBlyg\Settings::all() );' >/dev/null
    step "$LAYOUT: cache lifetime $L"
    [[ "$($WP eval 'echo SoapboxBlyg\Surface\Caching::lifetime();')" == "$L" ]] && ok "the setting is $L" || bad "the cache lifetime is not $L"
    for state in legacy off on; do enter "$state"; documents; done
    [[ "$L" == "$DEFAULT" ]] && { enter "two lines"; documents; }
  done
  enter off
  unpublished
}

[[ "$WHICH" == "both" || "$WHICH" == "templated" ]] && run templated
[[ "$WHICH" == "both" || "$WHICH" == "fixed" ]] && run fixed

echo
echo "$pass ok, $fails FAIL"
[[ "$fails" == "0" ]]
