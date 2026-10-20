#!/usr/bin/env bash
#
# An update in place, as a site takes it from WordPress.org: the previous release publishing, then
# the new one installed over it. On the switchover cell (a network; the blyg is on its site
# /two/, templated, as on robertpeake.com).
#
# What must hold: every address answers as before, and every document says the same thing apart
# from what the release changes on purpose: the protocol version key, the generator, and
# generator_url, and from 0.1.5 a pinned changelog entry's pinned_at. Ids, versions, `updated`,
# content hashes, pins and the withdrawn page do not move.
#
#   npx wp-env start --config tests/env/switchover.json
#   bin/upgrade.sh <previous release zip> [new zip, default ./soapbox-for-blygger.zip]

set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
OLD_ZIP="${1:?usage: bin/upgrade.sh <previous release zip> [soapbox-for-blygger.zip]}"
NEW_ZIP="${2:-$ROOT/soapbox-for-blygger.zip}"
[ -f "$OLD_ZIP" ] && [ -f "$NEW_ZIP" ] || { echo "an archive is missing: $OLD_ZIP, $NEW_ZIP" >&2; exit 2; }
export WP_ENV_CELL=switchover
WP="$ROOT/bin/wp-env-wp"
SITE="http://localhost:8898"
TWO="$SITE/two"
SLUG=soapbox-for-blygger
version_of() { unzip -p "$1" "$SLUG/$SLUG.php" | sed -n 's/^ \* Version: *//p' | head -n 1; }
OLD_VERSION="$(version_of "$OLD_ZIP")"
NEW_VERSION="$(version_of "$NEW_ZIP")"
[ -n "$OLD_VERSION" ] && [ -n "$NEW_VERSION" ] || { echo "an archive has no $SLUG/$SLUG.php with a Version header" >&2; exit 2; }
CLI="$(docker ps --format '{{.Names}}' | grep '^wp-env-env-switchover-.*-cli-1$' | grep -v tests | head -n 1)"
[ -n "$CLI" ] || { echo "the switchover cell is not running (npx wp-env start --config tests/env/switchover.json)" >&2; exit 2; }
T="$(mktemp -d)"

pass=0; fails=0
ok()   { echo "  ok   $*"; pass=$((pass+1)); }
bad()  { echo "  FAIL $*"; fails=$((fails+1)); }
step() { echo; echo "[$*]"; }
wp2()  { "$WP" --url="$TWO/" "$@"; }
jget() { python3 -c "import json,sys; d=json.load(sys.stdin); print(d$1)"; }

# One line for an address: its status and a hash of its body with the intended changes taken out
# (generator, the blyg version key, generator_url, pinned_at).
line() {
  curl -s -D "$T/h" -o "$T/b" "$1"
  printf '%s %s\n' \
    "$(sed -n '1s/^HTTP[^ ]* \([0-9]*\).*/\1/p' "$T/h")" \
    "$(sed -E -e 's#soapbox-for-blygger/[0-9.]+#GENERATOR#g' -e 's#"blyg":"0\.[0-9]+"#"blyg":"0.x"#g' -e 's#"generator_url":"[^"]*",?##g' -e 's#,"pinned_at":"[^"]*"##g' "$T/b" | shasum -a 256 | cut -c1-16)"
}
snapshot() { : > "$1"; for u in "${URLS[@]}"; do echo "$u $(line "$u")" >> "$1"; done; }
field()    { curl -s "$1" | jget "$2" 2>/dev/null; }
ledger()   { wp2 blyg verify --render 2>&1 | tr '\n' ' '; }
counts()   { wp2 db query "SELECT COUNT(*) FROM wp_2_soapbox_blyg_items; SELECT COUNT(*) FROM wp_2_soapbox_blyg_versions; SELECT COUNT(*) FROM wp_2_soapbox_blyg_versions WHERE pinned = 1" --skip-column-names | tr '\n' ' '; }
fatals()   { docker exec "$CLI" sh -c 'f=/var/www/html/wp-content/debug.log; [ -f "$f" ] && grep -c "PHP Fatal" "$f" || echo 0'; }

step "a network, one site with a blyg, published by $SLUG $OLD_VERSION"
npx wp-env clean development --config tests/env/switchover.json >/dev/null 2>&1
docker cp "$OLD_ZIP" "$CLI:/tmp/old.zip" >/dev/null && docker cp "$NEW_ZIP" "$CLI:/tmp/new.zip" >/dev/null
docker exec -u root "$CLI" sh -c 'chmod 644 /tmp/old.zip /tmp/new.zip; rm -f /var/www/html/wp-content/debug.log'
"$WP" site create --slug=two >/dev/null 2>&1
"$WP" plugin install /tmp/old.zip --force >/dev/null 2>&1  # clean resets the database, not the plugins folder
wp2 plugin activate $SLUG >/dev/null 2>&1
[[ "$(wp2 plugin get $SLUG --field=version)" == "$OLD_VERSION" ]] && ok "$OLD_VERSION is active on $TWO/" || bad "the previous release is not set up"
wp2 rewrite structure '/archives/%post_id%-%postname%.html' >/dev/null 2>&1
wp2 option update soapbox_blyg_settings '{"enabled":true,"mount":"/blyg/","layout":"templated","webmention":true}' --format=json >/dev/null
wp2 rewrite flush >/dev/null 2>&1
A=$(wp2 post create --post_status=publish --post_title="Kept" --post_content="<p>First.</p>" --porcelain)
wp2 post update "$A" --post_content="<p>Second.</p>" >/dev/null
PIN=$(wp2 blyg pin "$A" 1 --yes | sed -n 's/^Success: Pinned: //p')
B=$(wp2 post create --post_status=publish --post_title="Taken down" --post_content="<p>Only-while-published.</p>" --porcelain)
PAGE=$(wp2 post list --post__in="$B" --field=url)
wp2 post update "$B" --post_status=draft >/dev/null
wp2 post create --post_status=publish --post_title="Third" --post_content="<p>Third.</p>" --porcelain >/dev/null
ITEM=$(wp2 blyg status "$A" --format=json | jget '["url"]')
WITHDRAWN=$(wp2 blyg status "$B" --format=json | jget '["url"]')
read -r MANIFEST FEED INDEX <<<"$(wp2 eval '$p = SoapboxBlyg\Plugin::instance(); $o = $p->registry->get( "posts" ); echo $p->locations->manifest( $o ), " ", $p->locations->feed( $o ), " ", $p->locations->index( $o );')"
URLS=("$MANIFEST" "$FEED" "$INDEX" "$ITEM" "$WITHDRAWN" "$PIN" "$PAGE")
curl -s -o /dev/null "$TWO/"
snapshot "$T/before"
LEDGER="$(ledger)"; COUNTS="$(counts)"; UPDATED="$(field "$MANIFEST" '["updated"]')"
awk '{print $2}' "$T/before" | grep -qv '^200$' && bad "not every address answers 200: $(awk '{print $2}' "$T/before" | tr '\n' ' ')" || ok "seven addresses answer 200: manifest, feed, index, an item, a withdrawn item, a pin, a withdrawn post's page"
echo "       blyg $(field "$MANIFEST" '["blyg"]'), generator $(field "$MANIFEST" '["generator"]'); items, versions, pins: $COUNTS"

step "updated in place to $NEW_VERSION"
"$WP" plugin install /tmp/new.zip --force >/dev/null 2>&1
[[ "$(wp2 plugin get $SLUG --field=version)" == "$NEW_VERSION" && "$(wp2 plugin get $SLUG --field=status)" == "active" ]] && ok "$NEW_VERSION is installed and still active" || bad "after the update: $(wp2 plugin get $SLUG --field=version) $(wp2 plugin get $SLUG --field=status)"
curl -s -o /dev/null "$TWO/"
snapshot "$T/after"
if diff <(awk '{print $1, $2, $3}' "$T/before") <(awk '{print $1, $2, $3}' "$T/after") >/dev/null; then
  ok "every address answers as before, and says the same apart from the version key, generator, generator_url and pinned_at"
else
  bad "an address changed beyond what the release intends"; diff "$T/before" "$T/after" | sed 's/^/         /' | head -14
fi
[[ "$(wp2 option get soapbox_blyg_plugin_version)" == "$NEW_VERSION" ]] && ok "the site records $NEW_VERSION" || bad "recorded version: $(wp2 option get soapbox_blyg_plugin_version)"
[[ "$(ledger)" == "$LEDGER" && "$(counts)" == "$COUNTS" ]] && ok "the ledger verifies as before; no item, version or pin added" || bad "ledger: $(ledger) / $(counts), was $LEDGER / $COUNTS"
[[ "$(field "$MANIFEST" '["updated"]')" == "$UPDATED" ]] && ok "manifest updated unchanged: $UPDATED" || bad "manifest updated moved: $(field "$MANIFEST" '["updated"]')"
[[ "$(field "$MANIFEST" '["generator"]')" == "$SLUG/$NEW_VERSION" ]] && ok "generator: $SLUG/$NEW_VERSION" || bad "generator: $(field "$MANIFEST" '["generator"]')"
echo "       manifest blyg $(field "$MANIFEST" '["blyg"]'), item $(field "$ITEM" '["blyg"]'), pin $(field "$PIN" '["blyg"]'), withdrawn $(field "$WITHDRAWN" '["blyg"]'); generator_url $(field "$MANIFEST" '.get("generator_url")')"
AT=$(wp2 db query "SELECT pinned_at FROM wp_2_soapbox_blyg_versions WHERE pinned = 1 LIMIT 1" --skip-column-names)
[[ -n "$AT" && "$(field "$ITEM" '["changelog"][0].get("pinned_at")')" == "$AT" ]] && ok "the pinned entry carries pinned_at, the ledger's $AT" || bad "pinned_at: $(field "$ITEM" '["changelog"][0].get("pinned_at")'), ledger $AT"
[[ "$(fatals)" == "0" ]] && ok "no fatal error in debug.log" || bad "$(fatals) fatal errors in debug.log"

rm -rf "$T"
echo
echo "$pass ok, $fails FAIL"
[ "$fails" -eq 0 ]
