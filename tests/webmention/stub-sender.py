#!/usr/bin/env python3
"""A stand-in for another Blygger client, for rehearsing the Webmention receiver locally.

It serves one blyg at http://host.docker.internal:8080/blyg/ (which is how a wp-env container
reaches this machine) containing one thread whose document refers to an item of the site under
test. Standard library only. Not part of the plugin.

    stub-sender.py <target origin> <target item id> [transclusion|stub|none|withdrawn]

The mode decides what the thread's document says about the target:
  transclusion  a transclusions[] entry naming it (the default; what the reference client sends)
  stub          a stub_of naming it
  none          a real item document that does not reference it
  withdrawn     the thread has been withdrawn

Two hostile documents are served in every mode:
  /blyg/bomb.json     64 MB of zeros, gzipped to about 64 KB, sent with Content-Encoding: gzip
                      whatever the request's Accept-Encoding said
  /blyg/endless.json  64 MB sent uncompressed, with no Content-Length
The request headers of each are written to the log, so a test can see what was asked for.

And, in every mode, three more blygs on the same host (blygger-spec decision #61: a document
speaks for an origin only from that origin's own address for it):
  /alice/blyg/   a fixed-layout blyg with a manifest and nothing else
  /bob/blyg/     whose one item document says its origin is Alice's
  /tpl/blyg/     a templated blyg: its manifest's item template is /tpl/api/items/{id}, and the
                 document is served there
"""

import gzip
import json
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer

HOST = "http://host.docker.internal:8080"
ORIGIN = HOST + "/blyg/"
ITEM = "1vgtgz0gq5b2c9k7d3m8r4n6xy"

target_origin, target_id = sys.argv[1], sys.argv[2]
mode = sys.argv[3] if len(sys.argv) > 3 else "transclusion"
reference = {"origin": target_origin, "id": target_id, "version": 1}


def document():
    doc = {
        "blyg": "0.3", "id": ITEM, "kind": "thread", "origin": ORIGIN, "page": "t/%s/" % ITEM,
        "author": {"name": "Stub Sender"}, "created": "2026-10-01T00:00:00Z",
        "updated": "2026-10-01T00:00:00Z", "version": 1,
        "content_md": "A response.\n", "content_html": "<p>A response.</p>",
        "content_hash": "sha256:0", "media": [], "transclusions": [],
        "changelog": [{"version": 1, "at": "2026-10-01T00:00:00Z", "note": None}],
    }
    if mode == "transclusion":
        doc["transclusions"] = [reference]
    elif mode == "stub":
        doc["stub_of"] = reference
    elif mode == "withdrawn":
        doc.update(kind="withdrawn", content_md="", content_html="")
    return doc


PAGE = ('<!doctype html><title>A response</title>'
        '<link rel="alternate" type="application/json" href="%sitems/%s.json">'
        '<p>A response.</p>' % (ORIGIN, ITEM))


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path == "/blyg/bomb.json":
            sys.stderr.write("stub-sender bomb: accept-encoding=%s\n" % self.headers.get("Accept-Encoding"))
            body = gzip.compress(b"\0" * (64 * 1024 * 1024))
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Encoding", "gzip")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return
        if self.path == "/blyg/endless.json":
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            sent = 0
            try:
                for _ in range(64 * 16):
                    self.wfile.write(b" " * 65536)
                    sent += 65536
            except (BrokenPipeError, ConnectionResetError):
                pass
            sys.stderr.write("stub-sender endless: sent=%d\n" % sent)
            return
        claimed = {"/bob/blyg/items/%s.json" % ITEM: "/alice/blyg/", "/tpl/api/items/%s" % ITEM: "/tpl/blyg/"}
        manifests = {"/alice/blyg/blyg.json": {}, "/tpl/blyg/blyg.json": {"item": HOST + "/tpl/api/items/{id}"}}
        if self.path in claimed:
            doc = document()
            doc.update(origin=HOST + claimed[self.path], transclusions=[reference])
            body, ctype = json.dumps(doc).encode(), "application/json"
        elif self.path in manifests:
            manifest = {"blyg": "0.3", "level": 2, "site": HOST + self.path[:-len("blyg.json")], "title": "Stub"}
            manifest.update(manifests[self.path])
            body, ctype = json.dumps(manifest).encode(), "application/json"
        elif self.path == "/blyg/t/%s/" % ITEM:
            body, ctype = PAGE.encode(), "text/html; charset=utf-8"
        elif self.path == "/blyg/items/%s.json" % ITEM:
            body, ctype = json.dumps(document()).encode(), "application/json"
        elif self.path == "/blyg/blyg.json":
            body, ctype = json.dumps({"blyg": "0.3", "level": 2, "site": ORIGIN, "title": "Stub"}).encode(), "application/json"
        else:
            self.send_error(404)
            return
        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, fmt, *args):
        sys.stderr.write("stub-sender %s: %s\n" % (mode, fmt % args))


print("%st/%s/" % (ORIGIN, ITEM), flush=True)
HTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
