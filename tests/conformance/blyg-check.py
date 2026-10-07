#!/usr/bin/env python3
"""Black-box conformance check for one blyg origin: Blygger Protocol 0.3, publish side, with the
0.4 templated surface (spec 0.3 §16.6e, decision #51).

Owned by QA. Implements the "check" column of SPEC.md §11 and PLUGIN-SPEC §13.3 (G1, G2), with the
QA rulings of 2026-10-06 (level 2, absolute `page`). Standard library only.

The checker never assumes a path past `blyg.json`: it reads `feed`, `items`, `item` and `pin` from
the manifest (defaults `feed.xml`, `items/index.json`, `items/{id}.json`, `items/{id}/v{n}.json`)
and expands the RFC 6570 level-1 templates. A manifest that templates `item` is a **templated**
layout, and its fixed default paths must 404 (one copy of each document, off the static paths).

    blyg-check.py https://www.robertpeake.com/blyg/
    blyg-check.py http://wordpress.oz/blyg/ --home http://wordpress.oz/ --all
    blyg-check.py http://wordpress.oz/blyg-fixture/ --no-discovery
    blyg-check.py http://localhost:8888/blyg/ --level 2 --check-webmention

By default it deep-checks the 25 most recently updated items plus every item that appears in the
feed. --all deep-checks every item in the archive index (slow on 850+ items, which is the point of
running it once after a backfill).

Exit status: 0 = no FAILs (WARNs allowed), 1 = at least one FAIL, 2 = could not run.
"""

import argparse
import hashlib
import json
import re
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from email.utils import parsedate_to_datetime
from html.parser import HTMLParser

NS_BLYG = "https://blygger.org/ns/0.1"
NS_DC = "http://purl.org/dc/elements/1.1/"
ID_RE = re.compile(r"^[0-9a-hjkmnp-tv-z]{26}$")
ISO_Z_RE = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$")
FEED_WINDOW = 50
PROTOCOL_VERSIONS = {"0.3"}
DEFAULTS = {"feed": "feed.xml", "items": "items/index.json",
            "item": "items/{id}.json", "pin": "items/{id}/v{n}.json"}

# Where this origin's documents live, from its manifest (filled by check_manifest).
LOC = {"manifest": None, "feed": None, "items": None, "item": None, "pin": None, "templated": False}
EXPECT_LEVEL = 2


def expand(template, **values):
    """RFC 6570 level 1: simple string expansion, every value percent-encoded."""
    out = template
    for k, v in values.items():
        out = out.replace("{" + k + "}", urllib.parse.quote(str(v), safe=""))
    return out


def item_url(iid):
    return expand(LOC["item"], id=iid)


def pin_url(iid, n):
    return expand(LOC["pin"], id=iid, n=n)

failures = []
warnings = []


def fail(ac, msg):
    failures.append(f"{ac}: {msg}")
    print(f"  FAIL {ac}: {msg}")


def warn(ac, msg):
    warnings.append(f"{ac}: {msg}")
    print(f"  WARN {ac}: {msg}")


def ok(msg):
    print(f"  ok   {msg}")


class Response:
    def __init__(self, status, headers, body, url):
        self.status, self.headers, self.body, self.url = status, headers, body, url

    def header(self, name):
        return self.headers.get(name.lower())

    def text(self):
        return self.body.decode("utf-8")

    def json(self):
        return json.loads(self.body.decode("utf-8"))


# Local dev sites often run self-signed certificates; this is a checker, not a client.
_ctx = ssl.create_default_context()
_ctx.check_hostname = False
_ctx.verify_mode = ssl.CERT_NONE


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


_opener_follow = urllib.request.build_opener(urllib.request.HTTPSHandler(context=_ctx))
_opener_nofollow = urllib.request.build_opener(urllib.request.HTTPSHandler(context=_ctx), _NoRedirect())
_cache = {}


def fetch(url, headers=None, follow=True, use_cache=True):
    key = (url, tuple(sorted((headers or {}).items())), follow)
    if use_cache and key in _cache:
        return _cache[key]
    req = urllib.request.Request(url, headers={"User-Agent": "blyg-check/0.3 (Soapbox QA)", **(headers or {})})
    opener = _opener_follow if follow else _opener_nofollow
    try:
        with opener.open(req, timeout=30) as r:
            resp = Response(r.status, {k.lower(): v for k, v in r.headers.items()}, r.read(), r.geturl())
    except urllib.error.HTTPError as e:
        resp = Response(e.code, {k.lower(): v for k, v in e.headers.items()}, e.read() or b"", url)
    _cache[key] = resp
    return resp


def check_cors(resp, label):
    if resp.header("access-control-allow-origin") != "*":
        fail("AC-11", f"{label}: Access-Control-Allow-Origin is {resp.header('access-control-allow-origin')!r}, want '*'")


def check_conditional(url, label):
    first = fetch(url)
    etag = first.header("etag")
    if not etag:
        fail("AC-11", f"{label}: no ETag")
        return
    again = fetch(url, headers={"If-None-Match": etag}, use_cache=False)
    if again.status != 304:
        fail("AC-11", f"{label}: If-None-Match with the current ETag returned {again.status}, want 304")


def is_iso_z(v):
    return isinstance(v, str) and bool(ISO_Z_RE.match(v))


def sha256_md(md):
    return "sha256:" + hashlib.sha256(md.encode("utf-8")).hexdigest()


class HtmlAudit(HTMLParser):
    """Collects what AC-12 forbids in content_html."""

    FORBIDDEN = {"script", "style", "link", "iframe", "object", "embed", "form"}
    WIRE_CLASSES = {"blyg-transclusion", "blyg-tk-gen"}

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.problems = []

    def handle_starttag(self, tag, attrs):
        if tag in self.FORBIDDEN:
            self.problems.append(f"<{tag}> element")
        for name, value in attrs:
            value = value or ""
            if name == "style":
                self.problems.append(f"style attribute on <{tag}>")
            if name == "class" and set(value.split()) - self.WIRE_CLASSES:
                self.problems.append(f"class={value!r} on <{tag}>")
            if name.startswith("on"):
                self.problems.append(f"{name} handler on <{tag}>")
            if name in ("href", "src"):
                if value.startswith(("#", "mailto:", "tel:")):
                    continue
                if not re.match(r"^https?://", value):
                    self.problems.append(f"non-absolute {name}={value[:80]!r}")


def audit_html(html, label):
    if not html:
        return
    p = HtmlAudit()
    p.feed(html)
    for problem in sorted(set(p.problems))[:5]:
        fail("AC-12", f"{label}: {problem}")


def check_manifest(origin):
    print("\n[manifest]")
    r = fetch(origin + "blyg.json")
    if r.status != 200:
        fail("AC-1", f"blyg.json returned {r.status}")
        return None
    check_cors(r, "blyg.json")
    check_conditional(origin + "blyg.json", "blyg.json")
    try:
        m = r.json()
    except ValueError as e:
        fail("AC-1", f"blyg.json does not parse: {e}")
        return None
    if not isinstance(m, dict) or "blyg" not in m:
        fail("AC-1", "blyg.json is not an object with a 'blyg' key")
        return None
    if m.get("blyg") not in PROTOCOL_VERSIONS:
        fail("AC-1", f"blyg = {m.get('blyg')!r}, want one of {sorted(PROTOCOL_VERSIONS)}")
    if m.get("level") != EXPECT_LEVEL:
        fail("AC-1", f"level = {m.get('level')!r}, want {EXPECT_LEVEL}")
    if m.get("site") != origin:
        fail("AC-1", f"site = {m.get('site')!r}, fetch origin is {origin!r}")
    murl = origin + "blyg.json"
    LOC["manifest"] = murl
    for key, default in DEFAULTS.items():
        value = m.get(key, default)
        if not isinstance(value, str) or not value:
            fail("G1", f"manifest {key} is {value!r}, want a URL or template string")
            value = default
        # Templates resolve like any URL reference; the braces survive urljoin unchanged.
        LOC[key] = urllib.parse.urljoin(murl, value)
    for key, var in (("item", "{id}"), ("pin", "{n}")):
        if var not in LOC[key] or "{id}" not in LOC[key]:
            fail("G1", f"{key} template {LOC[key]!r} lacks {var} / {{id}}")
    LOC["templated"] = LOC["item"] != urllib.parse.urljoin(murl, DEFAULTS["item"])
    ok(f"layout: {'templated' if LOC['templated'] else 'fixed'}; item -> {LOC['item']}")
    cc = (r.header("cache-control") or "").lower()
    sm = re.search(r"s-maxage=(\d+)", cc)
    if sm and int(sm.group(1)) > 300:
        fail("G2", f"manifest Cache-Control {cc!r}: s-maxage over 300 (edge cache would hold withdrawals)")
    if "blogroll" in m:
        if fetch(origin + m["blogroll"]).status != 200:
            fail("AC-1", "manifest names a blogroll that does not resolve")
    elif fetch(origin + "blogroll.opml").status != 404:
        fail("AC-1", "no blogroll key, but blogroll.opml does not 404 (spec §11)")
    if not is_iso_z(m.get("updated")):
        fail("AC-5", f"manifest updated {m.get('updated')!r} is not ISO 8601 Z")
    author = m.get("author")
    if not isinstance(author, dict) or not author.get("name"):
        warn("AC-1", "manifest author.name missing (RECOMMENDED)")
    elif "bio" in author and author["bio"] == "":
        fail("AC-1", "author.bio is an empty string; omit the key instead (SPEC §7.1)")
    if r.header("content-type", ) and "json" not in r.header("content-type"):
        warn("AC-1", f"blyg.json Content-Type {r.header('content-type')!r}")
    ok(f"manifest: {m.get('title')!r}, updated {m.get('updated')}")
    return m


def check_index(origin):
    print("\n[archive index]")
    r = fetch(LOC["items"])
    if r.status != 200:
        fail("AC-4", f"archive index {LOC['items']} returned {r.status}")
        return None
    check_cors(r, "index.json")
    check_conditional(LOC["items"], "index.json")
    idx = r.json()
    items = idx.get("items")
    if not isinstance(items, list):
        fail("AC-4", "index has no items array")
        return None
    ups = [e.get("updated", "") for e in items]
    if ups != sorted(ups, reverse=True):
        fail("AC-4", "index is not ordered by updated descending")
    ids = [e.get("id") for e in items]
    if len(ids) != len(set(ids)):
        fail("AC-4", "index lists an id more than once")
    if items and idx.get("updated") != items[0].get("updated"):
        warn("AC-4", f"index updated {idx.get('updated')} != newest item {items[0].get('updated')}")
    for e in items:
        if not ID_RE.match(e.get("id") or ""):
            fail("AC-5", f"index id {e.get('id')!r} is not a 26-char Crockford id")
    ok(f"index: {len(items)} items, {sum(1 for e in items if e.get('kind') == 'withdrawn')} withdrawn")
    return items


def check_item(origin, entry):
    iid = entry["id"]
    label = f"item {iid}"
    r = fetch(item_url(iid))
    if r.status != 200:
        fail("AC-4", f"{label}: {item_url(iid)} returned {r.status}")
        return None
    check_cors(r, label)
    try:
        d = r.json()
    except ValueError:
        fail("AC-5", f"{label}: does not parse")
        return None

    for k in ("version", "kind", "updated"):
        if d.get(k) != entry.get(k):
            fail("AC-4", f"{label}: {k} {d.get(k)!r} disagrees with index {entry.get(k)!r}")
    if d.get("blyg") not in PROTOCOL_VERSIONS:
        fail("AC-5", f"{label}: blyg = {d.get('blyg')!r}")
    check_page(origin, d, label)
    if d.get("id") != iid:
        fail("AC-5", f"{label}: id field {d.get('id')!r}")
    if d.get("origin") != origin:
        fail("AC-5", f"{label}: origin {d.get('origin')!r} != {origin!r}")
    for k in ("created", "updated"):
        if not is_iso_z(d.get(k)):
            fail("AC-5", f"{label}: {k} {d.get(k)!r} not ISO 8601 Z")
    if "author" in d and not isinstance(d["author"], dict):
        fail("AC-5", f"{label}: author is not an object")

    md = d.get("content_md")
    if not isinstance(md, str) or d.get("content_hash") != sha256_md(md):
        fail("AC-5", f"{label}: content_hash does not match sha256(content_md)")

    cl = d.get("changelog") or []
    if [c.get("version") for c in cl] != list(range(1, len(cl) + 1)):
        fail("AC-5", f"{label}: changelog versions are not 1..N without gaps")
    if cl and cl[-1].get("version") != d.get("version"):
        fail("AC-5", f"{label}: version {d.get('version')} != last changelog entry")
    if cl and cl[-1].get("at") != d.get("updated"):
        fail("AC-5", f"{label}: updated != last changelog at")
    for c in cl:
        if not is_iso_z(c.get("at")):
            fail("AC-5", f"{label}: changelog v{c.get('version')} at {c.get('at')!r} not ISO Z")
        if "pinned" in c and c["pinned"] is not True:
            fail("AC-5", f"{label}: changelog pinned must be true or absent")
        if any(k not in ("version", "at", "note", "pinned") for k in c):
            fail("AC-5", f"{label}: changelog carries content or diffs ({sorted(c)})")
    ats = [c.get("at", "") for c in cl]
    if ats != sorted(ats):
        warn("AC-5", f"{label}: changelog times are not monotonic")

    kind = d.get("kind")
    if kind == "withdrawn":
        if d.get("content_md") != "" or d.get("content_html") != "" or d.get("media") != []:
            fail("AC-5", f"{label}: withdrawn endcap is not empty")
        if "transclusions" in d and d["transclusions"] != []:
            fail("AC-5", f"{label}: withdrawn endcap transclusions not []")
        if "generated" in d:
            fail("AC-5", f"{label}: withdrawn endcap carries generated")
        if cl and cl[-1].get("pinned"):
            fail("AC-6", f"{label}: an endcap is pinned")
    elif kind == "thread":
        if not isinstance(d.get("transclusions"), list):
            fail("AC-5", f"{label}: thread without transclusions array")
    elif kind == "fragment":
        if "transclusions" in d:
            fail("AC-5", f"{label}: fragment carries transclusions")
        if len(md or "") > 2000:
            warn("AC-5", f"{label}: fragment content_md over 2,000 chars (SHOULD cap)")
    else:
        fail("AC-5", f"{label}: kind {kind!r} is not fragment/thread/withdrawn")

    for m in d.get("media", []):
        if not m.get("url") or not m.get("mime"):
            fail("AC-5", f"{label}: media entry missing url or mime")
        elif "alt" not in m:
            warn("AC-5", f"{label}: media entry without alt")

    audit_html(d.get("content_html", ""), label)
    check_pins(origin, d)
    return d


def check_pins(origin, d):
    iid, label = d["id"], f"item {d['id']}"
    cl = d.get("changelog") or []
    pinned = {c["version"] for c in cl if c.get("pinned")}
    for n in sorted(pinned):
        r = fetch(pin_url(iid, n))
        if r.status != 200:
            fail("AC-6", f"{label}: pinned v{n} returned {r.status}")
            continue
        check_cors(r, f"{label} v{n}")
        p = r.json()
        if p.get("blyg") not in PROTOCOL_VERSIONS:
            fail("AC-6", f"{label} v{n}: blyg = {p.get('blyg')!r}")
        if p.get("version") != n or p.get("id") != iid or p.get("pinned") is not True:
            fail("AC-6", f"{label} v{n}: id/version/pinned fields wrong")
        if p.get("content_hash") != sha256_md(p.get("content_md", "")):
            fail("AC-6", f"{label} v{n}: content_hash does not match")
        if p.get("kind") == "withdrawn":
            fail("AC-6", f"{label} v{n}: a pinned version is an endcap")
        if "media" in p:
            fail("AC-6", f"{label} v{n}: pinned document carries a media array (spec §8)")
        entry = next((c for c in cl if c.get("version") == n), {})
        if p.get("at") != entry.get("at"):
            fail("AC-6", f"{label} v{n}: at disagrees with changelog")
        audit_html(p.get("content_html", ""), f"{label} v{n}")
    unpinned = [c["version"] for c in cl if not c.get("pinned")]
    probes = unpinned[:1] + [d.get("version", 0) + 1]
    for n in probes:
        s = fetch(pin_url(iid, n)).status
        if s != 404:
            fail("AC-6", f"{label}: unpinned/nonexistent v{n} returned {s}, want 404")


def check_page(origin, d, label):
    """Spec §5.8, with the QA rulings: emitted, absolute, 200, and for withdrawn items too."""
    page = d.get("page")
    if page is None:
        warn("T1", f"{label}: no page (SHOULD; Soapbox always emits it)")
        return
    if not re.match(r"^https?://", page):
        warn("T1", f"{label}: page {page!r} is origin-relative; Soapbox emits absolute (QA Q4)")
    url = urllib.parse.urljoin(origin, page)
    s = fetch(url).status
    if s != 200:
        fail("T1", f"{label}: page {url} returned {s}, want 200 (withdrawn items included, spec §5.8)")


def post(url, data):
    body = urllib.parse.urlencode(data).encode()
    req = urllib.request.Request(url, data=body, method="POST", headers={
        "User-Agent": "blyg-check/0.3 (QA)", "Content-Type": "application/x-www-form-urlencoded"})
    try:
        with _opener_nofollow.open(req, timeout=30) as r:
            return r.status
    except urllib.error.HTTPError as e:
        return e.code


def check_webmention(origin, manifest):
    print("\n[webmention]")
    rel = manifest.get("webmention")
    if not rel:
        ok("no webmention key: receiver off (the Soapbox default)")
        return
    endpoint = urllib.parse.urljoin(LOC["manifest"], rel)
    checks = [
        ("missing params", {}, 400),
        ("source == target", {"source": origin, "target": origin}, 400),
        ("off-origin target", {"source": "https://example.org/x", "target": "https://example.org/y"}, 400),
        ("unknown target", {"source": "https://example.org/x", "target": item_url("0" * 26)}, 400),
    ]
    for name, data, want in checks:
        got = post(endpoint, data)
        if got != want:
            fail("G3", f"webmention {name}: {got}, want {want}")
    if fetch(endpoint).status not in (405, 400):
        fail("G3", "webmention GET should be 405")
    ok(f"webmention syntactic checks at {endpoint}")


def check_negative_paths(origin):
    print("\n[404 surface]")
    zero = "0" * 26
    probes = {
        "unknown id": item_url(zero),
        "malformed id": item_url("NOT-AN-ID"),
        "uppercase id": item_url("7C9WK2MHQ0V3XJ8TN5RZFD41BG"),
        "v0": pin_url(zero, 0),
        "unlisted path": origin + "nothing-here.json",
    }
    for name, url in probes.items():
        s = fetch(url).status
        if s != 404:
            fail("AC-6", f"{name} ({url}) returned {s}, want 404")
    if LOC["templated"]:
        # One copy of each document: a templated blyg serves nothing on the fixed static paths.
        for key in ("feed", "items"):
            default = origin + DEFAULTS[key]
            if LOC[key] != default and fetch(default).status != 404:
                fail("G1", f"templated layout also serves {default}, want 404")
        if fetch(origin + "items/" + zero + ".json").status != 404:
            fail("G1", "templated layout answers a fixed item path")
    ok("unknown and malformed paths 404")


def text_of(el, tag):
    found = el.find(tag)
    return found.text if found is not None else None


def check_feed(origin, index_by_id, docs):
    print("\n[feed]")
    r = fetch(LOC["feed"])
    if r.status != 200:
        fail("AC-2", f"feed {LOC['feed']} returned {r.status}")
        return []
    check_cors(r, "feed.xml")
    check_conditional(LOC["feed"], "feed.xml")
    ctype = r.header("content-type") or ""
    if "xml" not in ctype:
        fail("G2", f"feed Content-Type {ctype!r}, want an XML type (rss+xml)")
    try:
        root = ET.fromstring(r.body)
    except ET.ParseError as e:
        fail("AC-2", f"feed.xml does not parse: {e}")
        return []
    if root.tag != "rss" or root.get("version") != "2.0":
        fail("AC-2", "root is not <rss version=\"2.0\">")
    ch = root.find("channel")
    if ch is None:
        fail("AC-2", "no <channel>")
        return []
    if text_of(ch, f"{{{NS_BLYG}}}level") != str(EXPECT_LEVEL):
        fail("AC-2", f"channel blyg:level is not {EXPECT_LEVEL}")
    if text_of(ch, f"{{{NS_BLYG}}}manifest") != origin + "blyg.json":
        fail("AC-2", f"blyg:manifest {text_of(ch, f'{{{NS_BLYG}}}manifest')!r}, want {origin + 'blyg.json'!r}")
    for t in ("title", "link", "description"):
        if ch.find(t) is None:
            fail("AC-2", f"channel missing <{t}>")

    entries = ch.findall("item")
    if len(entries) > FEED_WINDOW:
        fail("AC-2", f"{len(entries)} entries, window is {FEED_WINDOW}")
    seen_guids, feed_ids, prev_key = set(), [], None
    per_id = {}
    for e in entries:
        guid_el = e.find("guid")
        guid = guid_el.text if guid_el is not None else None
        iid = text_of(e, f"{{{NS_BLYG}}}id")
        ver = text_of(e, f"{{{NS_BLYG}}}version")
        if guid_el is None or guid_el.get("isPermaLink") != "false":
            fail("AC-2", f"entry {guid}: guid missing or isPermaLink != false")
        if guid != f"blyg:{iid}:v{ver}":
            fail("AC-2", f"guid {guid!r} != blyg:{{id}}:v{{n}} for {iid} v{ver}")
        if guid in seen_guids:
            fail("AC-2", f"duplicate guid {guid}")
        seen_guids.add(guid)
        if text_of(e, f"{{{NS_BLYG}}}item") != item_url(iid):
            fail("AC-2", f"{guid}: blyg:item is not the absolute item URL {item_url(iid)}")
        if not is_iso_z(text_of(e, f"{{{NS_BLYG}}}created")):
            fail("AC-2", f"{guid}: blyg:created not ISO Z")
        try:
            when = parsedate_to_datetime(text_of(e, "pubDate"))
        except (TypeError, ValueError):
            fail("AC-2", f"{guid}: pubDate is not RFC 822")
            when = None
        if when is not None:
            key = (when, int(ver or 0))
            if prev_key is not None and key > prev_key:
                fail("AC-2", f"{guid}: not newest-first")
            prev_key = key
        if iid not in index_by_id:
            fail("AC-2", f"{guid}: id not in the archive index")
        per_id.setdefault(iid, []).append(e)
        feed_ids.append(iid)

    for iid, es in per_id.items():
        idx = index_by_id.get(iid, {})
        doc = docs.get(iid)
        kinds = {text_of(e, f"{{{NS_BLYG}}}kind") for e in es}
        if idx.get("kind") == "withdrawn":
            if len(es) != 1:
                fail("AC-3", f"withdrawn {iid} has {len(es)} feed entries, want exactly 1")
            e = es[0]
            if text_of(e, "title") != "withdrawn" or (text_of(e, "description") or "").strip():
                fail("AC-3", f"withdrawn {iid}: title must be 'withdrawn' and description empty")
            if text_of(e, f"{{{NS_BLYG}}}version") != str(idx.get("version")):
                fail("AC-3", f"withdrawn {iid}: the one entry is not the endcap version")
            continue
        if "withdrawn" in kinds:
            fail("AC-3", f"{iid}: a withdrawal entry remains for an item that has returned")
        if doc is not None:
            latest = doc.get("content_html", "")
            for e in es:
                if (text_of(e, "description") or "") != latest:
                    fail("AC-2", f"{iid} v{text_of(e, f'{{{NS_BLYG}}}version')}: description is not the latest content_html")
                    break
            creator = text_of(es[0], f"{{{NS_DC}}}creator")
            name = (doc.get("author") or {}).get("name")
            if name and creator != name:
                warn("AC-2", f"{iid}: dc:creator {creator!r} != author.name {name!r}")
    ok(f"feed: {len(entries)} entries across {len(per_id)} items")
    return list(dict.fromkeys(feed_ids))


class LinkFinder(HTMLParser):
    def __init__(self):
        super().__init__()
        self.blyg = []

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "link" and "blyg" in (a.get("rel") or "").split():
            self.blyg.append(a.get("href"))


def check_discovery(origin, home):
    print("\n[discovery]")
    r = fetch(home)
    if r.status != 200:
        fail("AC-13", f"home {home} returned {r.status}")
        return
    lf = LinkFinder()
    lf.feed(r.text())
    if len(lf.blyg) != 1:
        fail("AC-13", f"home carries {len(lf.blyg)} rel=blyg links, want exactly 1")
    else:
        # §12.1 step 4 as extended by §16.6e: the href may be the manifest itself, else an origin.
        href = urllib.parse.urljoin(home, lf.blyg[0])
        hr = fetch(href)
        manifest_url = None
        try:
            if hr.status == 200 and "blyg" in hr.json():
                manifest_url = hr.url
        except (ValueError, TypeError, UnicodeDecodeError):
            pass
        if manifest_url is None:
            manifest_url = (href if href.endswith("/") else href + "/") + "blyg.json"
        identity = manifest_url.rsplit("/", 1)[0] + "/"
        if identity != origin:
            fail("AC-13", f"home rel=blyg resolves to identity {identity!r}, want {origin!r}")
    fr = fetch(urllib.parse.urljoin(home, "feed/"))
    if fr.status != 200:
        fail("AC-13", f"/feed/ returned {fr.status}")
        return
    try:
        ch = ET.fromstring(fr.body).find("channel")
        if text_of(ch, f"{{{NS_BLYG}}}manifest") != origin + "blyg.json":
            fail("AC-13", "/feed/ does not carry blyg:manifest for this origin")
    except ET.ParseError as e:
        fail("AC-13", f"/feed/ does not parse: {e}")
    ok("discovery checked")


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("origin", help="origin base URL, e.g. https://www.robertpeake.com/blyg/")
    ap.add_argument("--home", help="site home URL for discovery checks (default: scheme://host/)")
    ap.add_argument("--all", action="store_true", help="deep-check every item in the index")
    ap.add_argument("--sample", type=int, default=25, help="items to deep-check besides feed items (default 25)")
    ap.add_argument("--no-discovery", action="store_true", help="skip AC-13 (fixture origins)")
    ap.add_argument("--level", type=int, default=2, help="expected conformance level (QA ruling: 2)")
    ap.add_argument("--check-webmention", action="store_true", help="exercise G3's syntactic checks")
    a = ap.parse_args()
    global EXPECT_LEVEL
    EXPECT_LEVEL = a.level

    origin = a.origin if a.origin.endswith("/") else a.origin + "/"
    parts = urllib.parse.urlsplit(origin)
    home = a.home or f"{parts.scheme}://{parts.netloc}/"
    print(f"blyg-check {origin}")

    try:
        manifest = check_manifest(origin)
        if manifest is None:
            raise SystemExit(1)
        items = check_index(origin)
        if items is None:
            raise SystemExit(1)
        index_by_id = {e["id"]: e for e in items}

        # Feed ids first so AC-2's latest-HTML check has documents to compare against.
        r = fetch(LOC["feed"])
        feed_ids = []
        if r.status == 200:
            try:
                ch = ET.fromstring(r.body).find("channel")
                feed_ids = [text_of(e, f"{{{NS_BLYG}}}id") for e in ch.findall("item")]
            except ET.ParseError:
                pass
        chosen = items if a.all else items[: a.sample]
        targets = list(dict.fromkeys([e["id"] for e in chosen] + [i for i in feed_ids if i in index_by_id]))

        print(f"\n[items] deep-checking {len(targets)} of {len(items)}")
        docs = {}
        for iid in targets:
            d = check_item(origin, index_by_id[iid])
            if d is not None:
                docs[iid] = d
        ok(f"{len(docs)} item documents fetched")

        check_feed(origin, index_by_id, docs)
        check_negative_paths(origin)
        if not a.no_discovery:
            check_discovery(origin, home)
        if a.check_webmention:
            check_webmention(origin, manifest)
    except (urllib.error.URLError, OSError) as e:
        print(f"could not reach {origin}: {e}")
        return 2

    print(f"\n{len(failures)} FAIL, {len(warnings)} WARN")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
