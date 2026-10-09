#!/usr/bin/env bash
#
# Fails if the repository tracks something that does not belong in it. The repository is public:
# it holds what a user, a contributor or a reviewer of the plugin needs, and nothing else.
#
#   bin/check-tree.sh [directory]     (default: this checkout)
#
# Two kinds of file stay out. Working papers of the people and agents who build the plugin
# (handoffs, runbooks, deployment notes for one site, reviews, the backlog): they live beside the
# checkout, not in it. And anything a build or a tool leaves behind: archives, releases, patches,
# caches, keys and environment files, a tool's own state. A few strings stay out too, which name
# one site's servers.
#
# bin/dist.sh runs this before it builds.

set -euo pipefail

ROOT="$(cd "${1:-$(dirname "$0")/..}" && pwd)"
TRACKED="$(git -C "$ROOT" ls-files)"

# Paths, as extended regular expressions matched against each tracked path, in any case.
PAPERS='(^|/)(HANDOFF|DEPLOY|GATE-RUNBOOK|RUNBOOK|BACKLOG|SPEC|PLUGIN-SPEC|SECURITY-REVIEW|DECISIONS|CLAUDE|AGENTS|TO-PM|TO-CC|MORNING|review-)[^/]*\.(md|markdown|txt|rst|pdf|docx?)$'
LEFTOVERS='\.(zip|tar|gz|tgz|bz2|xz|7z|rar|log|sql|orig|rej|patch|diff|pem|key|p12|pfx)$|(^|/)\.env(\.[^/]*)?$|^release/|^dist/|^vendor/|(^|/)node_modules/|(^|/)\.claude/|(^|/)\.DS_Store$|\.result\.cache$|^\.wp-env\.override\.json$|(^|/)[^/]*_tasks\.lock$|^languages/.*\.(mo|json)$|\.l10n\.php$'
# Strings, matched in any case against the contents of every tracked text file but this one: a
# host's numbered name, an ssh alias, and paths that exist on one machine or one server.
PRIVATE='[0-9]+\.cloudwaysapps\.com|peake[0-9]|/Users/|/home/[a-z]|/root/|/mnt/user-data|applications/[a-z0-9_]+/'

fails=0
bad() { echo "FAIL: $1" >&2; printf '%s\n' "$2" | sed 's/^/  /' >&2; fails=1; }

found="$(grep -iE "$PAPERS" <<<"$TRACKED" || true)"
[ -z "$found" ] || bad "working papers are tracked; they belong outside the repository" "$found"
found="$(grep -iE "$LEFTOVERS" <<<"$TRACKED" || true)"
[ -z "$found" ] || bad "something a build or a tool left behind is tracked" "$found"
found="$(cd "$ROOT" && git grep -niIE "$PRIVATE" -- . ':!bin/check-tree.sh' || true)"
[ -z "$found" ] || bad "a tracked file names a private host, alias or path" "$found"

# The name the site's data was first written under. It is the table prefix and the REST namespace
# of every published document, and renaming the plugin must never rename it.
grep -qE "^const SOAPBOX_BLYG_SLUG +=  *'soapbox-blyg';" "$ROOT"/*.php 2>/dev/null \
	|| bad "SOAPBOX_BLYG_SLUG is not 'soapbox-blyg'" "It names the tables and the addresses of everything a site has published. It does not change."

[ "$fails" = "0" ] && echo "ok   the tree holds nothing that should stay out of a public repository ($(grep -c . <<<"$TRACKED") files)"
exit "$fails"
