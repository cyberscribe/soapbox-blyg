#!/usr/bin/env bash
# A post's whole life, and two origins on one site: publish, edit, pin, trash, restore, delete
# (the checker's AC-3, 6 to 10, 13, 14 and 16). Runs against a LOCAL site only: it creates, edits, trashes and deletes posts.
#
#   WP="wp --path=$HOME/Sites/wordpress" SITE=http://wordpress.oz tests/conformance/scenario.sh
#
# Needs: wp-cli, curl, python3, and SOAPBOX_BLYG_FIXTURES defined in the local wp-config.php.
# Posts it creates are force-deleted at the end. Their ledger items stay behind as withdrawn items,
# which is correct: an item once published answers 200 forever.

# No pipefail: several checks pipe curl into `grep -q`, which exits on the first match and makes
# curl die of SIGPIPE; under pipefail that read as a failure about 38 times in 100 runs.
set -u

WP=${WP:-wp}
SITE=${SITE:-http://wordpress.oz}
SITE=${SITE%/}
ORIGIN="$SITE/blyg/"
FIXTURE="$SITE/blyg-fixture/"
HERE="$(cd "$(dirname "$0")" && pwd)"
CHECK="python3 $HERE/blyg-check.py"

case "$SITE" in
  *robertpeake.com*) echo "refusing to run against production ($SITE)"; exit 2 ;;
esac

pass=0; fails=0
ok()   { echo "  ok   $*"; pass=$((pass+1)); }
bad()  { echo "  FAIL $*"; fails=$((fails+1)); }
step() { echo; echo "[$*]"; }

http()   { curl -s -o /dev/null -w '%{http_code}' "$1"; }
body()   { curl -sL "$1"; }  # -L: production's /feed/ 301s to /feed
jget()   { python3 -c "import json,sys; d=json.load(sys.stdin); print(d$1 if d is not None else '')"; }
status() { $WP blyg status "$1" --format=json 2>/dev/null; }
field()  { status "$1" | jget "[\"$2\"]"; }

expect_version() { # post_id version kind label
  local v k; v=$(field "$1" version); k=$(field "$1" kind)
  if [[ "$v" == "$2" && "$k" == "$3" ]]; then ok "$4: v$v $k"; else bad "$4: got v$v $k, want v$2 $3"; fi
}
expect_http() { # url code label
  local c; c=$(http "$1"); [[ "$c" == "$2" ]] && ok "$3: $c" || bad "$3: got $c, want $2 ($1)"
}
feed_entries_for() { # id -> "count|titles"
  body "${ORIGIN}feed.xml" | python3 -c "
import sys, xml.etree.ElementTree as ET
ns='{https://blygger.org/ns/0.1}'
es=[e for e in ET.parse(sys.stdin).getroot().find('channel').findall('item') if e.find(ns+'id').text=='$1']
print(str(len(es))+'|'+','.join(e.find('title').text or '' for e in es))"
}
new_post() { $WP post create --post_type=post --post_status=draft --post_title="$1" --post_content="$2" --porcelain; }

step "preflight"
$WP blyg origins >/dev/null 2>&1 && ok "wp blyg origins" || { bad "wp blyg is not available"; exit 1; }
$WP blyg origins | grep -q fixture && ok "fixture origin registered" || bad "fixture origin missing: define SOAPBOX_BLYG_FIXTURES locally"
# A fresh site has published posts with no items yet (WordPress's "Hello world"); R2's
# verify --render rightly fails on those, so bring the ledger level with the site first.
$WP blyg backfill >/dev/null 2>&1 && ok "backfill before the run" || bad "wp blyg backfill failed"

step "AC-10 drafts are invisible"
count_index() { body "${ORIGIN}items/index.json" | python3 -c "import json,sys; print(len(json.load(sys.stdin)['items']))"; }
N0=$(count_index)
P=$(new_post "Blyg scenario A" "<p>First body.</p>")
[[ "$(field "$P" id)" == "None" || -z "$(field "$P" id)" ]] && ok "draft has no blyg id" || bad "draft has a blyg id"
[[ "$(count_index)" == "$N0" ]] && ok "index unchanged by a draft ($N0 items)" || bad "index count changed on draft save"

step "AC-8 publish -> v1"
$WP post update "$P" --post_status=publish >/dev/null
expect_version "$P" 1 thread "publish"
ID=$(field "$P" id)
expect_http "${ORIGIN}items/$ID.json" 200 "item document"
body "${ORIGIN}feed.xml" | grep -q "blyg:$ID:v1" && ok "feed carries v1" || bad "feed lacks blyg:$ID:v1"

step "AC-7 no-op saves do not bump"
$WP post update "$P" --post_title="Blyg scenario A" >/dev/null
$WP post meta update "$P" soapbox_blyg_scenario_meta "x" >/dev/null
$WP post term add "$P" post_tag blyg-scenario >/dev/null
expect_version "$P" 1 thread "same title, meta and tag change"

step "AC-7 / AC-8 edit -> v2"
$WP post update "$P" --post_content="<p>Second body.</p>" >/dev/null
expect_version "$P" 2 thread "body edit"
$WP post update "$P" --post_title="Blyg scenario A (retitled)" >/dev/null
expect_version "$P" 3 thread "title edit"

step "AC-6 pin v1 retroactively"
$WP blyg pin "$P" 1 --yes >/dev/null && ok "pin v1" || bad "pin v1 failed"
expect_http "${ORIGIN}items/$ID/v1.json" 200 "pinned v1"
expect_http "${ORIGIN}items/$ID/v2.json" 404 "unpinned v2"
expect_http "${ORIGIN}items/$ID/v9.json" 404 "nonexistent v9"
$WP blyg pin "$P" 1 --yes >/dev/null 2>&1 && bad "double pin accepted" || ok "double pin refused"

step "AC-8 / AC-3 trash -> withdrawn endcap"
$WP post delete "$P" >/dev/null
expect_version "$P" 4 withdrawn "trash"
expect_http "${ORIGIN}items/$ID.json" 200 "withdrawn item still 200"
expect_http "${ORIGIN}items/$ID/v1.json" 200 "pin survives withdrawal"
FE=$(feed_entries_for "$ID")
[[ "$FE" == "1|withdrawn" ]] && ok "feed: one withdrawn entry" || bad "feed entries for withdrawn item: $FE"
$WP blyg pin "$P" 4 --yes >/dev/null 2>&1 && bad "endcap pin accepted" || ok "endcap pin refused"

step "AC-8 restore -> publish returns under the same id"
$WP eval "wp_untrash_post($P);" >/dev/null
expect_version "$P" 4 withdrawn "untrash to draft is not an event"
$WP post update "$P" --post_status=publish >/dev/null
expect_version "$P" 5 thread "republish"
[[ "$(field "$P" id)" == "$ID" ]] && ok "same id after return" || bad "id changed on return"
FE=$(feed_entries_for "$ID")
[[ "$FE" != *withdrawn* ]] && ok "withdrawal entry gone after return" || bad "feed still shows withdrawal: $FE"

step "password protection withdraws"
$WP post update "$P" --post_password=secret >/dev/null
expect_version "$P" 6 withdrawn "password added"
$WP post update "$P" --post_password= >/dev/null
expect_version "$P" 7 thread "password removed"

step "scheduled publish"
S=$(new_post "Blyg scenario scheduled" "<p>Scheduled.</p>")
$WP eval "wp_publish_post($S);" >/dev/null
expect_version "$S" 1 thread "wp_publish_post (the cron path)"

step "AC-9 permanent delete"
Q=$(new_post "Blyg scenario B" "<p>To be deleted.</p>")
$WP post update "$Q" --post_status=publish >/dev/null
QID=$(field "$Q" id)
$WP blyg pin "$Q" 1 --yes >/dev/null
$WP post delete "$Q" --force >/dev/null
KIND=$(body "${ORIGIN}items/$QID.json" | jget '["kind"]')
[[ "$KIND" == "withdrawn" ]] && ok "deleted post's item is withdrawn" || bad "deleted post's item kind: $KIND"
expect_http "${ORIGIN}items/$QID/v1.json" 200 "pin survives permanent delete"

step "AC-13 discovery"
N=$(body "$SITE/" | grep -o '<link[^>]*rel="blyg"[^>]*>' | wc -l | tr -d ' ')
[[ "$N" == "1" ]] && ok "home has one rel=blyg" || bad "home has $N rel=blyg links"
body "$SITE/" | grep -o '<link[^>]*rel="blyg"[^>]*>' | grep -q "href=\"$ORIGIN\"" && ok "rel=blyg -> $ORIGIN" || bad "rel=blyg does not point at $ORIGIN"
body "$SITE/feed/" | grep -q "<blyg:manifest>${ORIGIN}blyg.json</blyg:manifest>" && ok "/feed/ upgrade hook" || bad "/feed/ lacks blyg:manifest"

step "AC-16 fixture origin"
SEED=$($WP eval-file "$HERE/fixture-seed.php" 2>&1)
echo "$SEED" | grep -E '^(ok|Error)' | sed 's/^/       /'
if echo "$SEED" | tail -1 | python3 -c "import json,sys; json.load(sys.stdin)" 2>/dev/null; then
  ok "fixture seed ran"
  FA=$(echo "$SEED" | tail -1 | jget '["a"]')
  expect_http "${FIXTURE}items/$FA.json" 200 "fixture item under fixture origin"
  expect_http "${ORIGIN}items/$FA.json" 404 "fixture item under posts origin"
  expect_http "${FIXTURE}items/$ID.json" 404 "posts item under fixture origin"
  body "${ORIGIN}items/index.json" | grep -q "$FA" && bad "fixture id leaked into posts index" || ok "posts index is isolated"
  $CHECK "$FIXTURE" --all --no-discovery >/tmp/blyg-fixture-check.txt 2>&1 && ok "blyg-check fixture origin" || { bad "blyg-check fixture origin"; grep -E 'FAIL' /tmp/blyg-fixture-check.txt | head -10; }
else
  bad "fixture seed failed"; echo "$SEED" | tail -5
fi

step "AC-14 ledger integrity"
$WP blyg verify >/dev/null 2>&1 && ok "wp blyg verify" || bad "wp blyg verify failed"

step "posts origin conformance"
$CHECK "$ORIGIN" --home "$SITE/" >/tmp/blyg-posts-check.txt 2>&1 && ok "blyg-check posts origin" || { bad "blyg-check posts origin"; grep -E 'FAIL' /tmp/blyg-posts-check.txt | head -10; }

# ---- Later additions, R1 to R5 in the steps' names. ------------------------------------------------

step "R4 no author, no author key"
body "${ORIGIN}items/$ID.json" | python3 -c "import json,sys; sys.exit('author' in json.load(sys.stdin))" \
  && ok "post with no author (user 0) omits author" || bad "item for a post with no author carries an author key"

step "R1 featured image"
# Two image attachments registered with metadata but no file on disk: the engine needs only an
# attachment's URL, size and alt text, and the CLI user may not be able to write to uploads/.
attachment() {
  $WP eval '
    $id = wp_insert_attachment( array( "post_title" => "'"$1"'", "post_mime_type" => "image/png", "post_status" => "inherit" ) );
    update_post_meta( $id, "_wp_attached_file", "blyg-scenario/'"$1"'.png" );
    wp_update_attachment_metadata( $id, array( "width" => 40, "height" => 30, "file" => "blyg-scenario/'"$1"'.png", "sizes" => array() ) );
    update_post_meta( $id, "_wp_attachment_image_alt", "'"$1"' alt" );
    echo $id;'
}
A1=$(attachment blyg-featured-a)
A2=$(attachment blyg-featured-b)
if [[ ! "$A1" =~ ^[0-9]+$ || ! "$A2" =~ ^[0-9]+$ ]]; then bad "could not create test attachments ($A1 / $A2)"; else
F=$($WP post create --post_type=post --post_status=draft --post_title="Blyg scenario featured" \
      --post_content="<p>Body without the image.</p>" --_thumbnail_id="$A1" --porcelain)
$WP post update "$F" --post_status=publish >/dev/null
expect_version "$F" 1 thread "publish with a featured image"
FID=$(field "$F" id)
first_media() { body "${ORIGIN}items/$FID.json" | python3 -c "import json,sys; d=json.load(sys.stdin); m=d['media']; h=d['content_html']; print((m[0]['url'] if m else '') + ' ' + str('blyg-featured-a' in h) + ' ' + str('blyg-featured-b' in h) + ' ' + str(h.split(chr(10))[1].startswith('<figure><img')))"; }
M=$(first_media)
[[ "$M" == *blyg-featured-a*" True False True" ]] && ok "featured image leads content_html and media" || bad "v1 featured image: $M"
body "${ORIGIN}items/$FID.json" | python3 -c "import json,sys; d=json.load(sys.stdin); sys.exit(0 if d['media'][0]['alt']=='blyg-featured-a alt' and 'alt=\"blyg-featured-a alt\"' in d['content_html'] else 1)" && ok "featured figure uses the attachment's alt text" || bad "featured figure lacks the attachment's alt text"
$WP post update "$F" --_thumbnail_id="$A2" >/dev/null
expect_version "$F" 2 thread "featured image changed"
M=$(first_media)
[[ "$M" == *blyg-featured-b*" False True True" ]] && ok "v2 carries the new featured image only" || bad "v2 featured image: $M"
$WP post update "$F" --_thumbnail_id="$A2" >/dev/null
expect_version "$F" 2 thread "same featured image again"
$WP post delete "$F" --force >/dev/null
fi

step "R5 featured image left out when the body opens with an image"
if [[ "$A1" =~ ^[0-9]+$ ]]; then
  L=$($WP post create --post_type=post --post_status=draft --post_title="Blyg scenario lead image" \
        --post_content='<p><a href="/x/"><img src="/wp-content/uploads/lead.jpg" alt="lead"></a> Opening text.</p>' \
        --_thumbnail_id="$A1" --porcelain)
  $WP post update "$L" --post_status=publish >/dev/null
  LID=$(field "$L" id)
  body "${ORIGIN}items/$LID.json" | python3 -c "
import json,sys; d=json.load(sys.stdin); h=d['content_html']
sys.exit(0 if 'blyg-featured-a' not in h and [m['url'].rsplit('/',1)[1] for m in d['media']]==['lead.jpg'] else 1)" \
    && ok "body opening with an image gets no featured figure" || bad "featured image added above the body's own lead image"
  $WP post delete "$L" --force >/dev/null
  $WP post delete "$A1" "$A2" --force >/dev/null 2>&1
else
  bad "R5 needs the R1 attachments"
fi

step "R7 / R8 break runs and code with breaks"
C=$($WP post create --post_type=post --post_status=draft --post_title="Blyg scenario breaks" --porcelain \
      --post_content='<p>One<br /><br />Two<br />
<br />
Three<br />
<!--more--><br />
Four</p><p>&nbsp;</p><p>&nbsp;<br />
<strong>Five</strong></p><p>&nbsp;&nbsp;indented line</p><p><code><font color="#000000">&lt;a&gt;<br />&nbsp;&nbsp;&lt;b&gt;<br /></font></code></p>')
$WP post update "$C" --post_status=publish >/dev/null
CID=$(field "$C" id)
body "${ORIGIN}items/$CID.json" | python3 -c "
import json,sys; d=json.load(sys.stdin); md=d['content_md']; lines=md.split(chr(10))
bad=[]
if any(l.strip()=='\\\\' for l in lines): bad.append('lone backslash line')
if '<br></br>' in md or '<br>' in md: bad.append('br in markdown')
import html as H, re
m=re.search(r'<pre><code>(.*?)</code></pre>', md)
fenced='\`\`\`' in md and '  <b>' in md
refs=bool(m) and H.unescape(m.group(1)).rstrip(chr(10))=='<a>'+chr(10)+'  <b>'
if not (fenced or refs): bad.append('code block lost its lines or indentation')
if not all(p in md for p in ('One\n\nTwo','Two\n\nThree','Three\n\nFour','Four\n\n**Five**')): bad.append('break runs are not paragraphs')
if '\u00a0\u00a0indented line' not in d['content_html']: bad.append('nbsp indentation lost from content_html')
print(', '.join(bad)); sys.exit(1 if bad else 0)" >/tmp/blyg-r78.txt \
  && ok "R7 paragraphs from <br> runs, R8 code keeps its lines and indentation" || bad "R7/R8: $(cat /tmp/blyg-r78.txt)"
$WP post delete "$C" --force >/dev/null

step "R2 verify --render"
$WP blyg verify --render >/dev/null 2>&1 && ok "verify --render exits 0 on a clean ledger" || bad "verify --render fails on a clean ledger"
# Scratch step: remove one live post's item row by hand, check it is caught, then put it back.
PFX=$($WP db prefix)
SCRATCH="${PFX}soapbox_blyg_items_scenario_scratch"
$WP db query "DROP TABLE IF EXISTS $SCRATCH; CREATE TABLE $SCRATCH AS SELECT * FROM ${PFX}soapbox_blyg_items WHERE blyg_id = '$ID'" >/dev/null
$WP db query "DELETE FROM ${PFX}soapbox_blyg_items WHERE blyg_id = '$ID'" >/dev/null
OUT=$($WP blyg verify --render 2>&1); RC=$?
[[ $RC -ne 0 && "$OUT" == *"post $P is public but has no blyg item"* ]] \
  && ok "verify --render exits non-zero and names post $P" || bad "verify --render missed a deleted item row (exit $RC)"
$WP db query "INSERT INTO ${PFX}soapbox_blyg_items SELECT * FROM $SCRATCH; DROP TABLE $SCRATCH" >/dev/null
$WP blyg verify --render >/dev/null 2>&1 && ok "verify --render exits 0 once the row is restored" || bad "ledger not clean after restoring the row"

step "cleanup"
$WP post delete "$P" "$S" --force >/dev/null && ok "scenario posts deleted (their items stay, withdrawn)"

echo; echo "$pass ok, $fails FAIL"
[[ $fails -eq 0 ]]
