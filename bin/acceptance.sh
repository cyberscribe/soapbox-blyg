#!/usr/bin/env bash
#
# The black-box acceptance run, against wp-env's development site (http://localhost:8888).
# It resets that site twice. Never point it at anything else.
#
#   1. Templated layout (the default for a new install): a lifecycle through WP-CLI, then QA's
#      blyg-check.py on the posts origin and the fixture origin, the withdrawn page, and the
#      headers of every document.
#   2. Fixed layout: QA's scenario.sh, which also runs blyg-check.py on both origins.
#
# QA's scenario addresses the protocol's default paths, so it can only run in the fixed layout;
# an origin's layout is locked once it has an item, hence the reset between the two.
#
# Usage: bin/acceptance.sh [templated|fixed]     (default: both)

# No pipefail: several checks pipe curl into `grep -q`, which exits at its first match and leaves
# curl to die of SIGPIPE; under pipefail that reads as a failure some of the time.
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
WP="$ROOT/bin/wp-env-wp"
SITE="http://localhost:8888"
CHECK="python3 $ROOT/tests/conformance/blyg-check.py"
WHICH="${1:-both}"

pass=0; fails=0
ok()   { echo "  ok   $*"; pass=$((pass+1)); }
bad()  { echo "  FAIL $*"; fails=$((fails+1)); }
step() { echo; echo "[$*]"; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
jget() { python3 -c "import json,sys; d=json.load(sys.stdin); print(d$1)"; }

reset_site() {
  npx wp-env reset development >/dev/null 2>&1 || npx wp-env clean development >/dev/null 2>&1
  $WP rewrite structure '/%year%/%monthnum%/%postname%/' --hard >/dev/null
  $WP theme activate twentytwentyfive >/dev/null 2>&1 || true
}

# The protocol lead's condition for documents on routes rather than files (blygger-spec#2): they
# "behave like static files: ... the same bytes on every request until the item changes". Each
# is fetched twice, over a minute apart so that no clock-derived value can repeat, and compared;
# and the feed answers a conditional request with 304, since readers poll it.
stable() { # label, feed url, then every url to compare
  local label="$1" feed="$2"; shift 2
  local before="" after="" url etag
  for url in "$@"; do before+="$(curl -s "$url" | shasum -a 256 | cut -c1-64) "; done
  sleep 61
  for url in "$@"; do after+="$(curl -s "$url" | shasum -a 256 | cut -c1-64) "; done
  [[ "$before" == "$after" ]] && ok "$label: $# documents are byte-identical 61 seconds apart" || bad "$label: a document changed with nothing published"
  etag=$(curl -s -D - -o /dev/null "$feed" | tr -d '\r' | sed -n 's/^[Ee][Tt]ag: //p')
  [[ -n "$etag" && "$(code -H "If-None-Match: $etag" "$feed")" == "304" ]] && ok "$label: the feed has an ETag and answers If-None-Match with 304" || bad "$label: the feed did not 304"
}

templated() {
  step "a fresh site publishes nothing until it is set up"
  reset_site
  [[ "$(code "$SITE/blyg/blyg.json")" == "404" ]] && ok "no blyg is served on activation" || bad "a fresh install serves a blyg"
  [[ "$(curl -s "$SITE/" | grep -c 'rel="blyg"')" == "0" ]] && ok "and none is advertised" || bad "a fresh install advertises a blyg"
  $WP option update soapbox_blyg_settings '{"enabled":true}' --format=json >/dev/null 2>&1
  [[ "$(code "$SITE/blyg/blyg.json")" == "404" ]] && ok "publishing cannot be switched on before the address and layout are chosen" || bad "publishing switched on with nothing chosen"

  step "templated: set up explicitly"
  $WP option update soapbox_blyg_settings '{"enabled":true,"mount":"/blyg/","layout":"templated"}' --format=json >/dev/null
  $WP rewrite flush >/dev/null 2>&1
  [[ "$($WP eval 'echo soapbox_blyg_get_origin_url("posts");')" == "$SITE/blyg/" ]] && ok "posts origin at /blyg/" || bad "posts origin missing"
  $WP blyg origins | grep -q 'posts.*templated' && ok "the layout chosen is templated" || bad "layout is not templated"
  $WP blyg backfill >/dev/null && ok "backfill" || bad "backfill failed"

  step "templated: lifecycle"
  P=$($WP post create --post_status=publish --post_title="Acceptance A" --post_content="<p>First.</p>" --porcelain)
  $WP post update "$P" --post_content="<p>Second.</p>" >/dev/null
  $WP blyg pin "$P" 1 --yes >/dev/null && ok "pin v1" || bad "pin failed"
  T=$($WP post create --post_status=publish --post_title="Acceptance trashed" --post_content="<p>To be trashed.</p>" --porcelain)
  TPAGE=$($WP post list --post__in="$T" --field=url)
  TID=$($WP blyg status "$T" --format=json | jget '["id"]')
  $WP post delete "$T" >/dev/null
  D=$($WP post create --post_status=publish --post_title="Acceptance deleted" --post_content="<p>To be deleted.</p>" --porcelain)
  DPAGE=$($WP post list --post__in="$D" --field=url)
  $WP post delete "$D" --force >/dev/null
  $WP eval-file "$ROOT/tests/conformance/fixture-seed.php" >/dev/null 2>&1 && ok "fixture seed" || bad "fixture seed failed"
  $WP blyg verify --render >/dev/null 2>&1 && ok "verify --render" || bad "verify --render failed"

  step "templated: G1 blyg-check, both origins"
  $CHECK "$SITE/blyg/" --home "$SITE/" --all >/tmp/soapbox-templated-posts.txt 2>&1 && ok "posts origin: $(tail -1 /tmp/soapbox-templated-posts.txt)" || { bad "posts origin"; grep -E 'FAIL|WARN' /tmp/soapbox-templated-posts.txt | head -20; }
  grep -q 'layout: templated' /tmp/soapbox-templated-posts.txt && ok "checker saw a templated layout" || bad "checker did not see a templated layout"
  $CHECK "$SITE/blyg-fixture/" --all --no-discovery >/tmp/soapbox-templated-fixture.txt 2>&1 && ok "fixture origin: $(tail -1 /tmp/soapbox-templated-fixture.txt)" || { bad "fixture origin"; grep -E 'FAIL|WARN' /tmp/soapbox-templated-fixture.txt | head -20; }

  step "templated: withdrawn pages"
  [[ "$(code "$TPAGE")" == "200" ]] && ok "trashed post's page: 200" || bad "trashed post's page: $(code "$TPAGE")"
  [[ "$(code "$DPAGE")" == "200" ]] && ok "deleted post's page: 200" || bad "deleted post's page: $(code "$DPAGE")"
  curl -s "$TPAGE" | grep -q "rel=\"alternate\" type=\"application/json\" href=\"$SITE/wp-json/soapbox-blyg/v1/origins/posts/items/$TID\"" && ok "withdrawn page links its endcap document" || bad "withdrawn page lacks rel=alternate"
  [[ "$(code "$SITE/2026/10/never-existed/")" == "404" ]] && ok "an unrelated missing page is still 404" || bad "an unrelated missing page is not 404"
  # A later post that takes the address wins: WordPress no longer 404s there.
  SLUG=$(basename "$DPAGE")
  N=$($WP post create --post_status=publish --post_title="Takes the slug" --post_name="$SLUG" --post_content="<p>New post at the old address.</p>" --porcelain)
  if [[ "$($WP post list --post__in="$N" --field=url)" == "$DPAGE" ]]; then
    curl -s "$DPAGE" | grep -q "New post at the old address" && ok "a later post at the same address wins" || bad "the withdrawn page shadowed a live post"
  else
    bad "could not reuse the slug for the takeover check"
  fi

  # P2 (production, 2026-10-07): permalinks that carry the post's id, as on robertpeake.com, and
  # an earlier slug on record. WordPress redirects an address with an old slug to the post's
  # permalink without asking whether the post can be seen; for a draft that is /?p=N, a 404.
  step "templated: a withdrawn page under /archives/%post_id%-%postname%.html"
  $WP rewrite structure '/archives/%post_id%-%postname%.html' --hard >/dev/null
  W=$($WP post create --post_status=publish --post_title="Acceptance drafted" --post_content="<p>To be drafted.</p>" --porcelain)
  WPAGE="$SITE/archives/$W-acceptance-drafted.html"
  [[ "$($WP post list --post__in="$W" --field=url)" == "$WPAGE" ]] && ok "the post is at ${WPAGE#$SITE}" || bad "the post is not at ${WPAGE#$SITE}"
  $WP post update "$W" --post_name=renamed >/dev/null
  $WP post update "$W" --post_name=acceptance-drafted >/dev/null
  $WP post meta add "$W" _wp_old_slug acceptance-drafted >/dev/null
  $WP post update "$W" --post_status=draft >/dev/null
  [[ "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$WPAGE")" == "200 " ]] && ok "the drafted post's page: 200, no redirect" || bad "the drafted post's page: $(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$WPAGE")"
  curl -s "$WPAGE" | grep -q 'rel="alternate" type="application/json"' && ok "and it is the withdrawn page" || bad "and it is not the withdrawn page"
  [[ "$(code "$SITE/archives/$W-renamed.html")" == "200" ]] && ok "so is the address it had under its other slug" || bad "the address under its other slug: $(code "$SITE/archives/$W-renamed.html")"
  [[ "$(code "$SITE/?p=$W")" == "404" ]] && ok "the plain address of a draft is still 404" || bad "the plain address of a draft: $(code "$SITE/?p=$W")"
  $WP post update "$W" --post_status=publish >/dev/null
  curl -s "$WPAGE" | grep -q 'To be drafted' && ok "published again, the post is back at its address" || bad "published again, the post is not back"
  $WP post delete "$W" --force >/dev/null
  $WP rewrite structure '/%year%/%monthnum%/%postname%/' --hard >/dev/null

  step "templated: G2 headers on every document"
  # The lifetimes are the plugin's to say: the one in force, and a pin's.
  read -r LIFE PIN_LIFE <<<"$($WP eval 'echo SoapboxBlyg\Surface\Caching::lifetime(), " ", SoapboxBlyg\Surface\Caching::PIN_LIFETIME;')"
  ITEM=$($WP blyg status "$P" --format=json | jget '["url"]')
  BASE="$SITE/wp-json/soapbox-blyg/v1/origins/posts"
  for url in "$SITE/blyg/blyg.json" "$BASE/feed" "$BASE/items" "$ITEM" "$ITEM/v/1"; do
    H=$(curl -s -D - -o /tmp/soapbox-body "$url" | tr -d '\r')
    problems=""
    grep -qi '^access-control-allow-origin: \*$' <<<"$H" || problems+=" cors"
    grep -qi '^x-content-type-options: nosniff$' <<<"$H" || problems+=" nosniff"
    grep -qi '^etag: "' <<<"$H" || problems+=" etag"
    # Only a pinned version has a date: it never changes. Everything else can change inside one
    # second, a withdrawal included, so it carries an ETag and no Last-Modified.
    if [[ "$url" == "$ITEM/v/1" ]]; then
      grep -qi '^last-modified: ' <<<"$H" || problems+=" last-modified"
    else
      grep -qi '^last-modified: ' <<<"$H" && problems+=" has-last-modified"
    fi
    # The plugin's own s-maxage and Expires, so that a host adding either changes nothing (B12;
    # bin/acceptance-breeze.sh is the run behind such a host).
    grep -qiE "^cache-control: public, max-age=(${LIFE}, s-maxage=${LIFE}|${PIN_LIFE}, s-maxage=${PIN_LIFE}, immutable)\$" <<<"$H" || problems+=" cache-control"
    [[ "$(grep -ci '^expires: ' <<<"$H")" == "1" ]] || problems+=" expires"
    grep -qiE '^(set-cookie|link|x-robots-tag|x-wp-|access-control-allow-credentials|pragma):' <<<"$H" && problems+=" foreign-header"
    head -c 1 /tmp/soapbox-body | grep -q '[{<]' || problems+=" body"
    grep -q '"_links"\|"code":"rest_' /tmp/soapbox-body && problems+=" rest-envelope"
    [[ -z "$problems" ]] && ok "headers: ${url#$SITE}" || bad "headers: ${url#$SITE}:$problems"
  done
  curl -s -D - -o /dev/null "$ITEM/v/1" | grep -qi 'immutable' && ok "pinned document is immutable" || bad "pinned document is not immutable"
  curl -s -D - -o /dev/null "$BASE/feed" | grep -qi '^content-type: application/rss+xml' && ok "feed is application/rss+xml" || bad "feed content type"
  ETAG=$(curl -s -D - -o /dev/null "$ITEM" | tr -d '\r' | sed -n 's/^[Ee][Tt]ag: //p')
  [[ "$(code -H "If-None-Match: $ETAG" "$ITEM")" == "304" ]] && ok "If-None-Match: 304" || bad "If-None-Match did not 304"
  [[ "$(code -X OPTIONS -H 'Origin: https://reader.example' -H 'Access-Control-Request-Method: GET' "$ITEM")" == "204" ]] && ok "preflight: 204" || bad "preflight"
  [[ "$(code -H 'Origin: https://reader.example' -D /tmp/soapbox-h "$ITEM")" == "200" ]] && ! grep -qi 'allow-credentials\|reader.example' /tmp/soapbox-h && ok "a browser origin gets the same static CORS" || bad "CORS varies by caller"
  [[ "$(code -X POST "$ITEM")" =~ ^40[45]$ ]] && ok "POST is refused" || bad "POST was not refused"
  curl -s -o /tmp/soapbox-q "$SITE/?soapbox_blyg_origin=posts&soapbox_blyg_path=blyg.json"
  head -c 1 /tmp/soapbox-q | grep -q '{' && bad "the surface answered a query string" || ok "no surface on a query string"

  step "templated: documents behave like static files"
  stable templated "$BASE/feed" "$SITE/blyg/blyg.json" "$BASE/feed" "$BASE/items" "$ITEM" "$ITEM/v/1"

  step "templated: E3 survives a theme switch"
  $WP theme install twentytwentyfour --activate >/dev/null 2>&1
  $CHECK "$SITE/blyg/" --home "$SITE/" >/tmp/soapbox-theme.txt 2>&1 && ok "blyg-check under another theme" || { bad "blyg-check under another theme"; grep FAIL /tmp/soapbox-theme.txt | head; }
  $WP theme activate twentytwentyfive >/dev/null 2>&1
}

fixed() {
  step "fixed: fresh site, layout set before the first item"
  reset_site
  $WP option update soapbox_blyg_settings '{"enabled":true,"mount":"/blyg/","layout":"fixed"}' --format=json >/dev/null
  $WP blyg origins | grep -q 'posts.*fixed' && ok "layout is fixed" || bad "layout is not fixed"
  $WP blyg backfill >/dev/null && ok "backfill" || bad "backfill failed"

  step "fixed: QA's scenario"
  WP="$WP" SITE="$SITE" bash "$ROOT/tests/conformance/scenario.sh" >/tmp/soapbox-scenario.txt 2>&1
  rc=$?
  tail -1 /tmp/soapbox-scenario.txt
  [[ $rc -eq 0 ]] && ok "scenario.sh: $(tail -1 /tmp/soapbox-scenario.txt)" || { bad "scenario.sh"; grep FAIL /tmp/soapbox-scenario.txt | head -20; }

  step "fixed: blyg-check --all, both origins"
  $CHECK "$SITE/blyg/" --home "$SITE/" --all >/tmp/soapbox-fixed-posts.txt 2>&1 && ok "posts origin: $(tail -1 /tmp/soapbox-fixed-posts.txt)" || { bad "posts origin"; grep -E 'FAIL|WARN' /tmp/soapbox-fixed-posts.txt | head -20; }
  grep -q 'layout: fixed' /tmp/soapbox-fixed-posts.txt && ok "checker saw a fixed layout" || bad "checker did not see a fixed layout"
  $CHECK "$SITE/blyg-fixture/" --all --no-discovery >/tmp/soapbox-fixed-fixture.txt 2>&1 && ok "fixture origin: $(tail -1 /tmp/soapbox-fixed-fixture.txt)" || { bad "fixture origin"; grep -E 'FAIL|WARN' /tmp/soapbox-fixed-fixture.txt | head -20; }
  [[ "$(code "$SITE/wp-json/soapbox-blyg/v1/origins/posts/items")" == "404" ]] && ok "a fixed origin has no REST documents" || bad "a fixed origin also answers on REST"

  step "fixed: documents behave like static files"
  FID=$(curl -s "$SITE/blyg/items/index.json" | python3 -c 'import json,sys; print(next(i["id"] for i in json.load(sys.stdin)["items"] if i["kind"] != "withdrawn"))')
  FP=$($WP post create --post_status=publish --post_title="Pinned for the check" --post_content="<p>Fixed.</p>" --porcelain)
  $WP blyg pin "$FP" 1 --yes >/dev/null
  FPID=$($WP blyg status "$FP" --format=json | jget '["id"]')
  stable fixed "$SITE/blyg/feed.xml" "$SITE/blyg/blyg.json" "$SITE/blyg/feed.xml" "$SITE/blyg/items/index.json" "$SITE/blyg/items/$FID.json" "$SITE/blyg/items/$FPID/v1.json"

  step "fixed: the lock"
  $WP option update soapbox_blyg_settings '{"layout":"templated","mount":"/moved/"}' --format=json >/dev/null
  [[ "$(code "$SITE/blyg/blyg.json")" == "200" && "$(code "$SITE/moved/blyg.json")" == "404" ]] && ok "a changed setting cannot move a published origin" || bad "the mount moved"
}

case "$WHICH" in
  templated) templated ;;
  fixed)     fixed ;;
  *)         templated; fixed ;;
esac

echo; echo "$pass ok, $fails FAIL"
[[ $fails -eq 0 ]]
