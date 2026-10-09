#!/usr/bin/env bash
#
# The switch from the plugin under its earlier name to this one, on a site that has published.
#
# WordPress takes soapbox-blyg/ and soapbox-for-blygger/ for two plugins. They share one ledger,
# one set of options and one set of published addresses. This installs the earlier release from
# its archive on one site of a network (as on robertpeake.com), gives it a ledger, and then makes
# the switch in both orders, deletes the earlier plugin, and shows after each step that every
# address answers as it did. Against the "switchover" wp-env cell (tests/env/switchover.json,
# http://localhost:8898), which it resets. Never point it at anything else.
#
#   npx wp-env start --config tests/env/switchover.json
#   bin/switchover.sh <soapbox-blyg-0.1.1.zip> [soapbox-for-blygger.zip]
#
# The first archive is the 0.1.1 release; the second defaults to what bin/dist.sh last built here.

set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
OLD_ZIP="${1:?usage: bin/switchover.sh <soapbox-blyg-0.1.1.zip> [soapbox-for-blygger.zip]}"
NEW_ZIP="${2:-$ROOT/soapbox-for-blygger.zip}"
[ -f "$OLD_ZIP" ] && [ -f "$NEW_ZIP" ] || { echo "an archive is missing: $OLD_ZIP, $NEW_ZIP" >&2; exit 2; }
export WP_ENV_CELL=switchover
WP="$ROOT/bin/wp-env-wp"
SITE="http://localhost:8898"
TWO="$SITE/two"
OLD=soapbox-blyg
NEW=soapbox-for-blygger
CLI="$(docker ps --format '{{.Names}}' | grep '^wp-env-env-switchover-.*-cli-1$' | grep -v tests | head -n 1)"
[ -n "$CLI" ] || { echo "the switchover cell is not running (npx wp-env start --config tests/env/switchover.json)" >&2; exit 2; }
T="$(mktemp -d)"

pass=0; fails=0
ok()   { echo "  ok   $*"; pass=$((pass+1)); }
bad()  { echo "  FAIL $*"; fails=$((fails+1)); }
step() { echo; echo "[$*]"; }
wp2()  { "$WP" --url="$TWO/" "$@"; }
jget() { python3 -c "import json,sys; d=json.load(sys.stdin); print(d$1)"; }

# One line for an address: its status, its ETag, and a hash of its body with the generator's name
# and version taken out, which is the one thing in a document that differs between the plugins.
line() {
  curl -s -D "$T/h" -o "$T/b" "$1"
  printf '%s %s %s\n' \
    "$(sed -n '1s/^HTTP[^ ]* \([0-9]*\).*/\1/p' "$T/h")" \
    "$(tr -d '\r' < "$T/h" | sed -n 's/^[Ee][Tt]ag: //p')" \
    "$(sed -E 's#soapbox-(blyg|for-blygger)/[0-9.]+#GENERATOR#g' "$T/b" | shasum -a 256 | cut -c1-16)"
}
# snapshot <file>: every address, one line each.
snapshot() { : > "$1"; for u in "${URLS[@]}"; do echo "$u $(line "$u")" >> "$1"; done; }
# same <label> <file a> <file b> [etags]: statuses and bodies agree; with "etags", the ETags too.
same() {
  local a b
  if [ "${4:-}" = "etags" ]; then a="$(cat "$2")"; b="$(cat "$3")"; else a="$(awk '{print $1, $2, $4}' "$2")"; b="$(awk '{print $1, $2, $4}' "$3")"; fi
  if [ "$a" = "$b" ] && ! awk '{print $2}' "$3" | grep -qv '^200$'; then ok "$1"; else bad "$1"; diff <(echo "$a") <(echo "$b") | sed 's/^/         /' | head -12; fi
}
generator() { curl -s "$MANIFEST" | jget '["generator"]' 2>/dev/null; }
ledger()    { wp2 blyg verify --render 2>&1 | tr '\n' ' '; }
settings()  { wp2 option get soapbox_blyg_settings --format=json; }
tables()    { wp2 db query "SELECT table_name FROM information_schema.tables WHERE table_name LIKE 'wp\_2\_soapbox\_blyg\_%' ORDER BY 1" --skip-column-names | tr '\n' ',' ; wp2 db query "SELECT COUNT(*) FROM wp_2_soapbox_blyg_items; SELECT COUNT(*) FROM wp_2_soapbox_blyg_versions" --skip-column-names | tr '\n' ' '; }
active()    { wp2 plugin list --status=active --field=name 2>/dev/null | grep '^soapbox' | tr '\n' ' '; "$WP" plugin list --status=active-network --field=name 2>/dev/null | grep '^soapbox' | sed 's/$/(network)/' | tr '\n' ' '; }
fatals()    { docker exec "$CLI" sh -c 'grep -c "PHP Fatal" /var/www/html/wp-content/debug.log 2>/dev/null || true'; }
state()     { same "$1: every address answers 200 as before" "$T/before" "$2" "${3:-}"; }

step "a network, one site with a blyg, published by Soapbox Blyg 0.1.1"
npx wp-env clean development --config tests/env/switchover.json >/dev/null 2>&1
docker cp "$OLD_ZIP" "$CLI:/tmp/old.zip" >/dev/null && docker cp "$NEW_ZIP" "$CLI:/tmp/new.zip" >/dev/null
docker exec -u root "$CLI" sh -c 'chmod 644 /tmp/old.zip /tmp/new.zip; rm -f /var/www/html/wp-content/debug.log'
"$WP" site create --slug=two >/dev/null 2>&1
"$WP" plugin install /tmp/old.zip >/dev/null 2>&1
wp2 plugin activate $OLD >/dev/null 2>&1
[[ "$(wp2 plugin get $OLD --field=version)" == "0.1.1" && "$(active)" == "$OLD " ]] && ok "Soapbox Blyg 0.1.1 is active on $TWO/ only" || bad "the earlier plugin is not set up: $(active)"
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
wp2 blyg backfill >/dev/null 2>&1
ITEM=$(wp2 blyg status "$A" --format=json | jget '["url"]')
read -r MANIFEST FEED INDEX <<<"$(wp2 eval '$p = SoapboxBlyg\Plugin::instance(); $o = $p->registry->get( "posts" ); echo $p->locations->manifest( $o ), " ", $p->locations->feed( $o ), " ", $p->locations->index( $o );')"
URLS=("$MANIFEST" "$FEED" "$INDEX" "$ITEM" "$PIN" "$PAGE")
curl -s -o /dev/null "$TWO/"
snapshot "$T/before"
LEDGER="$(ledger)"; SETTINGS="$(settings)"; TABLES="$(tables)"
awk '{print $2}' "$T/before" | grep -qv '^200$' && bad "not every address answers 200 to begin with: $(awk '{print $2}' "$T/before" | tr '\n' ' ')" || ok "six addresses answer 200: manifest, feed, index, a templated item, a pin, a withdrawn post's page"
[[ "$ITEM" == *"/wp-json/soapbox-blyg/v1/"* ]] && ok "the item is at ${ITEM#$SITE}" || bad "the item is not a templated document: $ITEM"
curl -s "$PAGE" | grep -q 'rel="alternate" type="application/json"' && ok "the withdrawn post's page is the withdrawn page" || bad "the withdrawn page is not shown at $PAGE"
[[ "$(generator)" == "soapbox-blyg/0.1.1" ]] && ok "generator: soapbox-blyg/0.1.1" || bad "generator: $(generator)"
wp2 cron event list --fields=hook --format=csv | grep -q '^soapbox_blyg_sweep_mentions$' && ok "the mentions sweep is scheduled" || bad "the mentions sweep is not scheduled"
echo "       ledger: $LEDGER"

"$WP" plugin install /tmp/new.zip >/dev/null 2>&1
[[ "$("$WP" plugin get $NEW --field=version 2>/dev/null)" == "0.1.2" && "$(active)" == "$OLD " ]] && ok "Soapbox for Blygger 0.1.2 is installed, inactive" || bad "the new plugin is not installed inactive: $(active)"
snapshot "$T/now"; state "with the new plugin only installed" "$T/now" etags

step "order 1: deactivate the earlier plugin, then activate this one"
wp2 plugin deactivate $OLD >/dev/null 2>&1
GAP="$(for u in "${URLS[@]}"; do curl -s -o /dev/null -w '%{http_code} ' "$u"; done)"
echo "       in the gap, with neither active, the six addresses answer: $GAP"
[[ "$GAP" == "404 404 404 404 404 404 " ]] && ok "the gap is a 404 at every address, as deactivating has always been" || bad "the gap is not what deactivating was known to do: $GAP"
wp2 plugin activate $NEW >/dev/null 2>&1
snapshot "$T/now"; state "order 1, first requests after activating" "$T/now"
[[ "$(generator)" == "soapbox-for-blygger/0.1.2" && "$(active)" == "$NEW " ]] && ok "this plugin is serving: $(generator)" || bad "active: $(active); generator: $(generator)"
[[ "$(ledger)" == "$LEDGER" ]] && ok "the ledger verifies with the same counts" || bad "the ledger differs: $(ledger)"
[[ "$(settings)" == "$SETTINGS" ]] && ok "the settings are unchanged" || bad "the settings changed: $(settings)"

step "rolling back: deactivate this one, activate the earlier plugin"
wp2 plugin deactivate $NEW >/dev/null 2>&1; wp2 plugin activate $OLD >/dev/null 2>&1
snapshot "$T/now"; state "rolled back" "$T/now" etags
[[ "$(generator)" == "soapbox-blyg/0.1.1" && "$(ledger)" == "$LEDGER" && "$(settings)" == "$SETTINGS" ]] && ok "the earlier plugin serves again, same ledger, same settings" || bad "the rollback differs: $(generator) / $(ledger)"

step "order 2: activate this one beside the earlier plugin, then deactivate the earlier one"
wp2 plugin activate $NEW >/dev/null 2>&1
[[ "$(active)" == "$OLD $NEW " ]] && ok "both are active" || bad "active: $(active)"
snapshot "$T/now"; state "both active: the earlier plugin goes on serving" "$T/now" etags
[[ "$(generator)" == "soapbox-blyg/0.1.1" ]] && ok "and it is the earlier plugin that runs: this one stands down" || bad "generator with both active: $(generator)"
NOTICE=$(wp2 eval 'wp_set_current_user( 1 ); ob_start(); do_action( "admin_notices" ); echo ob_get_clean();' 2>/dev/null)
grep -q 'both active' <<<"$NOTICE" && ok "an administrator is told: ${NOTICE:0:0}\"Soapbox Blyg and Soapbox for Blygger are both active…\"" || bad "no notice about two copies"
# Each of the four kinds of address in turn is the very first request after the deactivation.
for first in "$MANIFEST" "$ITEM" "$PIN" "$PAGE"; do
  wp2 plugin deactivate $OLD >/dev/null 2>&1
  got="$(line "$first")"
  want="$(grep -F "$first " "$T/before" | cut -d' ' -f2-)"
  [[ "$(awk '{print $1, $3}' <<<"$got")" == "$(awk '{print $1, $3}' <<<"$want")" && "${got%% *}" == "200" ]] && ok "first request after deactivating, ${first#$SITE}: 200, the same body" || bad "first request after deactivating, ${first#$SITE}: got '$got', want '$want'"
  snapshot "$T/now"; state "  and then the rest" "$T/now"
  [[ "$first" == "$PAGE" ]] || { wp2 plugin activate $OLD >/dev/null 2>&1; }
done
[[ "$(generator)" == "soapbox-for-blygger/0.1.2" && "$(active)" == "$NEW " ]] && ok "this plugin is serving: $(generator)" || bad "active: $(active); generator: $(generator)"
[[ "$(ledger)" == "$LEDGER" ]] && ok "the ledger verifies with the same counts" || bad "the ledger differs: $(ledger)"
[[ "$(settings)" == "$SETTINGS" ]] && ok "the settings are unchanged" || bad "the settings changed: $(settings)"
curl -s -o /dev/null "$TWO/"
wp2 cron event list --fields=hook --format=csv | grep -q '^soapbox_blyg_sweep_mentions$' && ok "the mentions sweep is scheduled again" || bad "the mentions sweep is not scheduled after the switch"
snapshot "$T/switched"

# The earlier plugin has no check for a second copy. Loaded second, it defines its four constants
# again, which PHP reports as warnings on every request, and calls Plugin::boot() again.
step "on a network: this one network-active, loaded first, and the earlier plugin loaded after it"
wp2 plugin deactivate $NEW >/dev/null 2>&1; wp2 plugin activate $OLD >/dev/null 2>&1
"$WP" plugin activate $NEW --network >/dev/null 2>&1
[[ "$(active)" == "$OLD $NEW(network) " ]] && ok "active: $(active)" || bad "active: $(active)"
snapshot "$T/now"; state "both loaded, this one first" "$T/now"
[[ "$(generator)" == "soapbox-for-blygger/0.1.2" ]] && ok "this plugin runs, once: $(generator)" || bad "generator: $(generator)"
N=$(wp2 eval 'global $wp_filter; $n = 0; foreach ( $wp_filter["soapbox_blyg_event"]->callbacks as $c ) { $n += count( $c ); } echo $n;' 2>/dev/null)
"$WP" plugin deactivate $NEW --network >/dev/null 2>&1; wp2 plugin deactivate $OLD >/dev/null 2>&1; wp2 plugin activate $NEW >/dev/null 2>&1
M=$(wp2 eval 'global $wp_filter; $n = 0; foreach ( $wp_filter["soapbox_blyg_event"]->callbacks as $c ) { $n += count( $c ); } echo $n;' 2>/dev/null)
[[ -n "$N" && "$N" == "$M" ]] && ok "nothing was hooked twice: $N listeners on the ledger's event, as with one copy" || bad "listeners on the ledger's event: $N with both loaded, $M with one"

step "\"delete all data\" is on when the earlier plugin is about to be deleted"
wp2 eval 'global $wpdb; $s = get_option( "soapbox_blyg_settings" ); $s["delete_data"] = true; $wpdb->update( $wpdb->options, array( "option_value" => serialize( $s ) ), array( "option_name" => "soapbox_blyg_settings" ) ); wp_cache_flush();' >/dev/null 2>&1
[[ "$(wp2 db query "SELECT option_value LIKE '%\"delete_data\";b:1%' FROM wp_2_options WHERE option_name = 'soapbox_blyg_settings'" --skip-column-names | tr -d '[:space:]')" == "1" ]] && ok "control: the setting is on in the database, written past the plugin" || bad "control: could not switch the setting on"
curl -s -o /dev/null "$TWO/"
[[ "$(wp2 db query "SELECT option_value LIKE '%\"delete_data\";b:0%' FROM wp_2_options WHERE option_name = 'soapbox_blyg_settings'" --skip-column-names | tr -d '[:space:]')" == "1" ]] && ok "one request later this plugin has switched it off" || bad "the setting is still on"
[[ "$(settings)" == "$SETTINGS" ]] && ok "and the settings are otherwise as they were" || bad "the settings changed: $(settings)"
wp2 option patch update soapbox_blyg_settings delete_data 1 >/dev/null 2>&1
[[ "$(wp2 option pluck soapbox_blyg_settings delete_data 2>/dev/null)" != "1" ]] && ok "it cannot be switched on while the earlier plugin is installed" || bad "the setting could be switched on"
NOTICE=$(wp2 eval 'wp_set_current_user( 1 ); set_current_screen( "plugins" ); ob_start(); do_action( "admin_notices" ); echo ob_get_clean();' 2>/dev/null)
grep -q 'still installed' <<<"$NOTICE" && ok "the Plugins screen says why" || bad "no notice on the Plugins screen"

step "delete the earlier plugin"
"$WP" plugin delete $OLD >/dev/null 2>&1
[[ "$("$WP" plugin is-installed $OLD; echo $?)" == "1" ]] && ok "Soapbox Blyg is deleted (its own uninstall ran, on every site)" || bad "the earlier plugin is still installed"
snapshot "$T/now"; same "every address answers as it did before the delete, ETags included" "$T/switched" "$T/now" etags
state "and as it did under the earlier plugin" "$T/now"
[[ "$(tables)" == "$TABLES" ]] && ok "the site's five tables and their rows are there: ${TABLES##*,}(items, versions)" || bad "the tables changed: $(tables) (were: $TABLES)"
[[ "$(ledger)" == "$LEDGER" ]] && ok "the ledger verifies with the same counts" || bad "the ledger differs: $(ledger)"
[[ "$(settings)" == "$SETTINGS" ]] && ok "the settings are unchanged" || bad "the settings changed: $(settings)"
curl -s -o /dev/null "$TWO/"
wp2 cron event list --fields=hook --format=csv | grep -q '^soapbox_blyg_sweep_mentions$' && ok "the mentions sweep, which the earlier uninstall unscheduled, is scheduled again" || bad "the mentions sweep is not scheduled after the delete"
wp2 option patch update soapbox_blyg_settings delete_data 1 >/dev/null 2>&1
[[ "$(wp2 option pluck soapbox_blyg_settings delete_data 2>/dev/null)" == "1" ]] && ok "\"delete all data\" is the owner's to choose again" || bad "the setting is still held off"
wp2 option patch update soapbox_blyg_settings delete_data 0 >/dev/null 2>&1

step "a post published, edited and withdrawn by this plugin, on the same ledger"
C=$(wp2 post create --post_status=publish --post_title="After" --post_content="<p>After the switch.</p>" --porcelain)
[[ "$(wp2 blyg status "$C" --format=json | jget '["url"]')" == *"/wp-json/soapbox-blyg/v1/"* ]] && ok "a new item is at an address of the same kind" || bad "a new item's address: $(wp2 blyg status "$C" --format=json | jget '["url"]')"
wp2 post update "$A" --post_content="<p>Third.</p>" >/dev/null
[[ "$(wp2 blyg status "$A" --format=json | jget '["version"]')" == "3" && "$(wp2 blyg status "$A" --format=json | jget '["url"]')" == "$ITEM" ]] && ok "the item the earlier plugin made is now at version 3, same address" || bad "the earlier item: $(wp2 blyg status "$A" --format=json)"
[[ "$(curl -s -o /dev/null -w '%{http_code}' "$PIN")" == "200" ]] && ok "its pin still answers" || bad "the pin: $(curl -s -o /dev/null -w '%{http_code}' "$PIN")"

F="$(fatals)"
[[ "${F:-0}" == "0" ]] && ok "no PHP fatal error was logged at any step" || { bad "$F PHP fatal errors in debug.log"; docker exec "$CLI" sh -c 'grep "PHP Fatal" /var/www/html/wp-content/debug.log | head -5'; }
echo "       what debug.log holds (the earlier plugin's constants, in the network case, are expected):"
docker exec "$CLI" sh -c 'grep -o "PHP [A-Za-z]*:  [^/]*" /var/www/html/wp-content/debug.log 2>/dev/null | sort | uniq -c | sort -rn | head -5' | sed 's/^/         /'

rm -rf "$T"
echo
echo "$pass ok, $fails FAIL"
[[ "$fails" == "0" ]]
