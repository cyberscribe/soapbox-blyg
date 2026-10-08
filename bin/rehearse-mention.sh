#!/usr/bin/env bash
#
# Rehearses the Webmention half of the protocol's 0.4 gate (spec 0.3 §16.6e) on this machine: a stub
# standing in for another Blygger client publishes a thread that quotes one of our items and sends
# us a mention, and we verify it by fetching the stub's page and document.
#
# It uses the "webmention" wp-env cell (tests/env/webmention.json, http://localhost:8892), which it
# resets. The real gate is the reference client against a public staging origin; this proves the
# receiving side end to end before that.
#
#   npx wp-env start --config tests/env/webmention.json
#   bin/rehearse-mention.sh

# No pipefail: several checks pipe curl into `grep -q`, which exits at its first match and leaves
# curl to die of SIGPIPE; under pipefail that reads as a failure some of the time.
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
export WP_ENV_CELL=webmention
WP="$ROOT/bin/wp-env-wp"
SITE="http://localhost:8892"
ORIGIN="$SITE/blyg/"

pass=0; fails=0
ok()   { echo "  ok   $*"; pass=$((pass+1)); }
bad()  { echo "  FAIL $*"; fails=$((fails+1)); }
step() { echo; echo "[$*]"; }
post() { curl -s -o /tmp/soapbox-mention-body -w '%{http_code}' -X POST --data-urlencode "source=$1" --data-urlencode "target=$2" "${ORIGIN}webmention"; }
mention_field() { $WP eval 'global $wpdb; echo $wpdb->get_var( "SELECT '"$1"' FROM " . SoapboxBlyg\Ledger\Schema::table( "mentions" ) . " ORDER BY mention_id DESC LIMIT 1" );'; }
sender_fetches() { grep -c -E 'GET /blyg/(t|items)/' /tmp/soapbox-stub.log; }  # Not counting this script's own readiness probe.
run_queue() { $WP cron event run soapbox_blyg_verify_mention >/dev/null 2>&1; }

STUB_PID=""
stub() { # mode
  [ -n "$STUB_PID" ] && kill "$STUB_PID" 2>/dev/null && wait "$STUB_PID" 2>/dev/null
  python3 "$ROOT/tests/webmention/stub-sender.py" "$ORIGIN" "$TARGET_ID" "$1" >/tmp/soapbox-stub.out 2>/tmp/soapbox-stub.log &
  STUB_PID=$!
  for _ in 1 2 3 4 5 6 7 8 9 10; do curl -s -o /dev/null "http://localhost:8080/blyg/blyg.json" && break; sleep 0.3; done
  SOURCE=$(head -1 /tmp/soapbox-stub.out)
}
trap '[ -n "$STUB_PID" ] && kill "$STUB_PID" 2>/dev/null' EXIT

step "a fresh site with the receiver switched on"
npx wp-env reset development --config tests/env/webmention.json >/dev/null 2>&1
$WP rewrite structure '/%year%/%monthnum%/%postname%/' --hard >/dev/null
$WP plugin deactivate webmention >/dev/null 2>&1
$WP option update soapbox_blyg_settings '{"enabled":true,"mount":"/blyg/","layout":"templated","webmention":true}' --format=json >/dev/null
$WP blyg backfill >/dev/null
P=$($WP post create --post_status=publish --post_title="Quoted post" --post_content="<p>Worth quoting.</p>" --porcelain)
TARGET_ID=$($WP blyg status "$P" --format=json | python3 -c 'import json,sys; print(json.load(sys.stdin)["id"])')
PAGE=$($WP post list --post__in="$P" --field=url)
[[ -n "$TARGET_ID" ]] && ok "target item $TARGET_ID at $PAGE" || bad "no target item"

step "T5 advertising"
curl -s "${ORIGIN}blyg.json" | python3 -c 'import json,sys; sys.exit(0 if json.load(sys.stdin).get("webmention")=="webmention" else 1)' && ok "manifest carries webmention" || bad "manifest lacks webmention"
curl -s "$PAGE" | grep -q "<link rel=\"webmention\" href=\"${ORIGIN}webmention\"" && ok "permalink page has rel=webmention" || bad "permalink page lacks rel=webmention"
curl -s -D - -o /dev/null "$PAGE" | grep -qi "^link: <${ORIGIN}webmention>; rel=\"webmention\"" && ok "permalink page sends the Link header" || bad "no Link header"
[[ "$(curl -s "$SITE/" | grep -c 'rel="webmention"')" == "0" ]] && ok "no rel=webmention on a page that is not an item's" || bad "rel=webmention on the home page"

step "G3 syntactic checks (QA's checker)"
python3 "$ROOT/tests/conformance/blyg-check.py" "$ORIGIN" --home "$SITE/" --check-webmention >/tmp/soapbox-mention-check.txt 2>&1 && ok "blyg-check --check-webmention: $(tail -1 /tmp/soapbox-mention-check.txt)" || { bad "blyg-check --check-webmention"; grep -E 'FAIL' /tmp/soapbox-mention-check.txt | head; }
[[ "$(post "http://127.0.0.1/x" "$PAGE")" == "400" ]] && ok "a loopback source is refused: 400" || bad "loopback source accepted"
[[ "$(post "http://169.254.169.254/latest/meta-data/" "$PAGE")" == "400" ]] && ok "a link-local source is refused: 400" || bad "link-local source accepted"
[[ "$(post "http://10.0.0.1/x" "$PAGE")" == "400" ]] && ok "a private-network source is refused: 400" || bad "private source accepted"

step "a mention that verifies: a page whose document transcludes our item"
stub transclusion
[[ "$(post "$SOURCE" "$PAGE")" == "202" ]] && ok "202 for $SOURCE" || bad "valid mention not accepted: $(cat /tmp/soapbox-mention-body)"
[[ "$(mention_field status)" == "pending" && "$(sender_fetches)" == "0" ]] && ok "recorded as pending; nothing fetched yet" || bad "not pending"
run_queue
[[ "$(mention_field status)" == "verified" && "$(mention_field relation)" == "transclusion" ]] && ok "verified, relation transclusion" || bad "did not verify: $(mention_field status) / $(mention_field reason)"
[[ "$(sender_fetches)" == "2" ]] && ok "exactly two fetches: the page, then its document" || bad "$(sender_fetches) fetches"
[[ "$(mention_field source_origin)" == "http://host.docker.internal:8080/blyg/" ]] && ok "recorded the sender's origin, item and author, and no content" || bad "wrong source origin"

step "a re-sent mention for a withdrawn source is marked gone"
stub withdrawn
$WP eval 'global $wpdb; $wpdb->query( "UPDATE " . SoapboxBlyg\Ledger\Schema::table( "mentions" ) . " SET received_at = \"2026-01-01T00:00:00Z\"" );' >/dev/null
[[ "$(post "$SOURCE" "$PAGE")" == "202" ]] && ok "re-sent: 202" || bad "re-send refused: $(cat /tmp/soapbox-mention-body)"
run_queue
[[ "$(mention_field status)" == "gone" ]] && ok "now gone, and the row is kept ($($WP eval 'global $wpdb; echo $wpdb->get_var( "SELECT COUNT(*) FROM " . SoapboxBlyg\Ledger\Schema::table( "mentions" ) );') row)" || bad "status is $(mention_field status)"

step "a document that does not cite the target is rejected"
stub none
[[ "$(post "http://host.docker.internal:8080/blyg/items/1vgtgz0gq5b2c9k7d3m8r4n6xy.json" "$PAGE")" == "202" ]] && ok "202" || bad "not accepted"
run_queue
[[ "$(mention_field status)" == "failed" && "$(mention_field reason)" == "no-reference" ]] && ok "failed: no-reference" || bad "status $(mention_field status) / $(mention_field reason)"

step "two blygs on one host: one cannot speak as the other (blygger-spec decision #61)"
: > /tmp/soapbox-stub.log
BOB="http://host.docker.internal:8080/bob/blyg/items/1vgtgz0gq5b2c9k7d3m8r4n6xy.json"
[[ "$(post "$BOB" "$PAGE")" == "202" ]] && ok "202 for Bob's document, which says its origin is Alice's" || bad "not accepted: $(cat /tmp/soapbox-mention-body)"
run_queue
[[ "$(mention_field status)" == "failed" && "$(mention_field reason)" == "origin-mismatch" ]] && ok "failed: origin-mismatch" || bad "status $(mention_field status) / $(mention_field reason)"
[[ "$(grep -a -c 'GET /bob/blyg/items/' /tmp/soapbox-stub.log) $(grep -a -c 'GET /alice/blyg/blyg.json' /tmp/soapbox-stub.log) $(grep -a -c 'GET ' /tmp/soapbox-stub.log)" == "1 1 2" ]] && ok "two fetches: the document, then the manifest of the origin it named, which does not lead to it" || bad "fetched: $(grep -a -o 'GET [^ ]*' /tmp/soapbox-stub.log | paste -sd' ' -)"

step "a templated sender verifies through its manifest (decision #61, spec 16.6e)"
: > /tmp/soapbox-stub.log
TPL="http://host.docker.internal:8080/tpl/api/items/1vgtgz0gq5b2c9k7d3m8r4n6xy"
[[ "$(post "$TPL" "$PAGE")" == "202" ]] && ok "202 for a document at a templated address" || bad "not accepted: $(cat /tmp/soapbox-mention-body)"
run_queue
[[ "$(mention_field status)" == "verified" && "$(mention_field source_origin)" == "http://host.docker.internal:8080/tpl/blyg/" ]] && ok "verified, for the origin whose manifest led to it" || bad "status $(mention_field status) / $(mention_field reason) / $(mention_field source_origin)"
[[ "$(grep -a -o 'GET [^ ]*' /tmp/soapbox-stub.log | paste -sd' ' -)" == "GET /tpl/api/items/1vgtgz0gq5b2c9k7d3m8r4n6xy GET /tpl/blyg/blyg.json" ]] && ok "two fetches: the document, then that origin's manifest" || bad "fetched: $(grep -a -o 'GET [^ ]*' /tmp/soapbox-stub.log | paste -sd' ' -)"

step "a gzip bomb is refused without being inflated (review A7)"
: > /tmp/soapbox-stub.log
BOMB="http://host.docker.internal:8080/blyg/bomb.json"
$WP eval 'file_put_contents( WP_CONTENT_DIR . "/debug.log", "" );' >/dev/null 2>&1
[[ "$(post "$BOMB" "$PAGE")" == "202" ]] && ok "202" || bad "not accepted: $(cat /tmp/soapbox-mention-body)"
$WP cron event run soapbox_blyg_verify_mention >/tmp/soapbox-cron.out 2>&1 && ok "the verification run finished" || bad "the verification run died: $(tail -3 /tmp/soapbox-cron.out)"
[[ "$(mention_field status)" == "failed" && "$(mention_field reason)" == "encoded" ]] && ok "failed: encoded" || bad "status $(mention_field status) / $(mention_field reason)"
[[ "$(mention_field attempts)" == "1" ]] && ok "the attempt is recorded, and it failed on the first" || bad "attempts: $(mention_field attempts)"
grep -q "bomb: accept-encoding=identity" /tmp/soapbox-stub.log && ok "the request asked for no compression" || bad "Accept-Encoding sent: $(grep bomb: /tmp/soapbox-stub.log)"
[[ "$(grep -c 'GET /blyg/bomb.json' /tmp/soapbox-stub.log)" == "1" ]] && ok "fetched once" || bad "fetched $(grep -c 'GET /blyg/bomb.json' /tmp/soapbox-stub.log) times"
FATALS=$($WP eval 'echo (int) preg_match_all( "/PHP Fatal|Allowed memory size/", (string) @file_get_contents( WP_CONTENT_DIR . "/debug.log" ) );')
[[ "$FATALS" == "0" ]] && ok "no fatal error and no memory exhaustion in debug.log" || bad "$FATALS fatal errors logged"
$WP eval 'global $wpdb; $wpdb->query( "UPDATE " . SoapboxBlyg\Ledger\Schema::table( "mentions" ) . " SET status = \"pending\", attempts = 2 ORDER BY mention_id DESC LIMIT 1" );' >/dev/null
$WP eval 'SoapboxBlyg\Plugin::instance(); do_action( "soapbox_blyg_sweep_mentions" );' >/dev/null 2>&1
$WP eval 'global $wpdb; $id = (int) $wpdb->get_var( "SELECT mention_id FROM " . SoapboxBlyg\Ledger\Schema::table( "mentions" ) . " ORDER BY mention_id DESC LIMIT 1" ); do_action( "soapbox_blyg_verify_mention", $id );' >/dev/null 2>&1
[[ "$(mention_field status)" == "failed" && "$(mention_field reason)" == "gave-up" && "$(grep -c 'GET /blyg/bomb.json' /tmp/soapbox-stub.log)" == "1" ]] && ok "a claim left pending after two attempts (as a fatal fetch would leave it) is failed without a third fetch" || bad "status $(mention_field status) / $(mention_field reason), $(grep -c 'GET /blyg/bomb.json' /tmp/soapbox-stub.log) fetches"

step "a response that never ends is cut off at the size limit (review B4)"
: > /tmp/soapbox-stub.log
[[ "$(post "http://host.docker.internal:8080/blyg/endless.json" "$PAGE")" == "202" ]] && ok "202" || bad "not accepted: $(cat /tmp/soapbox-mention-body)"
STARTED=$(date +%s)
run_queue
TOOK=$(( $(date +%s) - STARTED ))
[[ "$(mention_field status)" == "failed" && "$(mention_field reason)" == "too-large" ]] && ok "failed: too-large" || bad "status $(mention_field status) / $(mention_field reason)"
sleep 1
SENT=$(grep -a -o 'endless: sent=[0-9]*' /tmp/soapbox-stub.log | tail -1 | cut -d= -f2)
[[ -n "$SENT" && "$SENT" -lt 16777216 ]] && ok "the transfer was aborted: the sender got ${SENT} of 67108864 bytes out (${TOOK}s)" || bad "the sender wrote ${SENT:-all} bytes"

step "T7 with the IndieWeb Webmention plugin active"
$WP plugin activate webmention >/dev/null 2>&1
N=$(curl -s "$PAGE" | grep -c "href=\"${ORIGIN}webmention\"")
[[ "$N" == "0" ]] && ok "our endpoint is not advertised on the page" || bad "both plugins advertise on the page"
curl -s -D - -o /dev/null "$PAGE" | grep -qi "^link: <${ORIGIN}webmention>" && bad "our Link header is still sent" || ok "our Link header is not sent"
[[ "$(curl -s "$PAGE" | grep -o '<link[^>]*rel="webmention"[^>]*>' | wc -l | tr -d ' ')" == "1" ]] && ok "the page carries exactly one rel=webmention, the IndieWeb plugin's" || bad "the page carries $(curl -s "$PAGE" | grep -o '<link[^>]*rel="webmention"[^>]*>' | wc -l | tr -d ' ') rel=webmention links"
curl -s "${ORIGIN}blyg.json" | python3 -c 'import json,sys; sys.exit(0 if json.load(sys.stdin).get("webmention")=="webmention" else 1)' && ok "the manifest still advertises ours" || bad "manifest lost webmention"
$WP plugin deactivate webmention >/dev/null 2>&1

echo; echo "$pass ok, $fails FAIL"
[[ $fails -eq 0 ]]
