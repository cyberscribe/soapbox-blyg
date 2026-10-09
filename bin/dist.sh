#!/usr/bin/env bash
#
# Builds the release archive with `wp dist-archive` and checks it, then runs Plugin Check on what
# is in it. Uses wp-env's development container, which has WP-CLI; the archive lands in the
# checkout as <slug>.zip (ignored by git).
#
#   npx wp-env start && bin/dist.sh

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# Two names. DIR is this checkout's directory, which is where wp-env mounts it and what it names
# its containers after. SLUG is the plugin's slug on WordPress.org, read from the header: the
# archive, its one top folder and the main file all carry it, whatever the checkout is called.
DIR="$(basename "$ROOT")"
SLUG="$(sed -n 's/^ \* Text Domain: *//p' "$ROOT"/*.php | head -n 1)"
[ -n "$SLUG" ] && [ -f "$ROOT/${SLUG}.php" ] || { echo "FAIL: no main file named after the header's Text Domain ('${SLUG}')" >&2; exit 1; }
# What is built is what is committed, at one version. A dirty tree builds an archive no commit
# describes, and the version is written in three places that must agree.
"$ROOT/bin/check-tree.sh" "$ROOT" >/dev/null || exit 1
[ -z "$(git -C "$ROOT" status --porcelain)" ] || { echo "FAIL: the working tree has uncommitted changes; commit or stash them first" >&2; exit 1; }
# A file git ignores is not in the commit, but `wp dist-archive` zips the directory, not the
# commit. So every ignored file must be one .distignore leaves out as well.
STRAY=""
while IFS= read -r path; do
	path="${path%/}"
	case "$path" in
		vendor|node_modules|.wp-env.override.json|.phpunit.result.cache|.claude|release|*.zip|.DS_Store|*/.DS_Store) ;;
		*) STRAY="${STRAY}${path}"$'\n' ;;
	esac
done < <(git -C "$ROOT" status --porcelain --ignored | sed -n 's/^!! //p')
[ -z "$STRAY" ] || { echo "FAIL: files git ignores would be shipped:" >&2; printf '%s' "$STRAY" >&2; exit 1; }
HEADER="$(sed -n 's/^ \* Version: *//p' "$ROOT/${SLUG}.php")"
CONSTANT="$(sed -n "s/^const SOAPBOX_BLYG_VERSION *= *'\(.*\)';/\1/p" "$ROOT/${SLUG}.php")"
STABLE="$(sed -n 's/^Stable tag: *//p' "$ROOT/readme.txt")"
[ -n "$HEADER" ] && [ "$HEADER" = "$CONSTANT" ] && [ "$HEADER" = "$STABLE" ] || { echo "FAIL: versions disagree (header '$HEADER', constant '$CONSTANT', readme '$STABLE')" >&2; exit 1; }

CONTAINER="$(docker ps --filter "label=com.docker.compose.service=cli" --format '{{.Names}}' | grep "^wp-env-${DIR}-" | head -n 1 || true)"
[ -n "$CONTAINER" ] || { echo "wp-env is not running (npx wp-env start)" >&2; exit 2; }
run() { docker exec --user "$(id -u):$(id -g)" -e HOME=/tmp --workdir /var/www/html "$CONTAINER" "$@"; }

# The container image has neither zip nor the dist-archive command, and installing the command
# needs git when GitHub's download API is rate-limited. All three installs are idempotent.
#
# The tools that make the archive are pinned, and the build stops if what is installed is
# anything else: the same commit must give the same bytes next month as it does today. The
# container itself comes from @wordpress/env, pinned to an exact version in package.json.
ZIP_PACKAGE="zip=3.0-r13"
ARCHIVER="wp-cli/dist-archive-command:3.1.0"
docker exec -u root "$CONTAINER" sh -c "command -v git >/dev/null || apk add --no-cache git >/dev/null; apk info -e '${ZIP_PACKAGE%%=*}' >/dev/null 2>&1 || apk add --no-cache '${ZIP_PACKAGE}' >/dev/null"
[ "$(run sh -c 'apk info -v 2>/dev/null | grep "^zip-"')" = "zip-${ZIP_PACKAGE#*=}" ] || { echo "FAIL: the container's zip is not ${ZIP_PACKAGE}" >&2; exit 2; }
run git config --global url."https://github.com/".insteadOf "git@github.com:"   # No ssh in the container.
run sh -c "wp dist-archive --help >/dev/null 2>&1 || wp package install '${ARCHIVER}' >/dev/null 2>&1" \
	|| { echo "could not install ${ARCHIVER} in the container" >&2; exit 2; }
[ "$(run sh -c 'wp package list --fields=name,version --format=csv 2>/dev/null | grep "^wp-cli/dist-archive-command,"')" = "wp-cli/dist-archive-command,v${ARCHIVER#*:}" ] || { echo "FAIL: dist-archive-command is not ${ARCHIVER#*:}" >&2; exit 2; }
run sh -c 'wp plugin is-installed plugin-check || wp plugin install plugin-check >/dev/null; wp plugin activate plugin-check >/dev/null'

run rm -f "/tmp/${SLUG}.zip"
run wp dist-archive "wp-content/plugins/${DIR}" "/tmp/${SLUG}.zip" --plugin-dirname="${SLUG}"

# Repack, so that the same commit always gives the same bytes. `wp dist-archive` zips a fresh copy
# of the tree, and every entry then carries the time of the copy and whatever modes the checkout
# happened to have. Here each entry gets the time of the commit, directories 755 and files 644,
# and the entries go in in sorted order with no extra fields.
STAMP="$(TZ=UTC git -C "$ROOT" log -1 --format=%cd --date=format-local:%Y%m%d%H%M.%S)"
run sh -c "rm -rf /tmp/repack && mkdir /tmp/repack && cd /tmp/repack && unzip -q /tmp/${SLUG}.zip \
	&& find ${SLUG} -type d -exec chmod 755 {} + && find ${SLUG} -type f -exec chmod 644 {} + \
	&& find ${SLUG} -exec env TZ=UTC touch -t ${STAMP} {} + \
	&& rm /tmp/${SLUG}.zip && find ${SLUG} | LC_ALL=C sort | TZ=UTC zip -X -q /tmp/${SLUG}.zip -@"
docker cp "$CONTAINER:/tmp/${SLUG}.zip" "$ROOT/${SLUG}.zip" >/dev/null

BYTES=$(wc -c < "$ROOT/${SLUG}.zip" | tr -d ' ')
echo "Version ${HEADER} at $(git -C "$ROOT" rev-parse --short HEAD), packed with $(run zip -v | sed -n '2p' | sed 's/^This is //; s/,.*//')"
echo "Archive: ${SLUG}.zip, ${BYTES} bytes, $(unzip -l "$ROOT/${SLUG}.zip" | tail -1 | awk '{print $2}') files"
[ "$BYTES" -lt 10485760 ] || { echo "FAIL: the archive is over 10 MB" >&2; exit 1; }

# WordPress.org installs a plugin into a folder named for its slug, and takes that from the archive.
ENTRIES="$(unzip -Z1 "$ROOT/${SLUG}.zip")"
TOP="$(cut -d/ -f1 <<<"$ENTRIES" | sort -u)"
[ "$TOP" = "$SLUG" ] && grep -qx "${SLUG}/${SLUG}.php" <<<"$ENTRIES" || { echo "FAIL: the archive's top folder is '${TOP}', not one folder '${SLUG}' holding ${SLUG}.php" >&2; exit 1; }
echo "One top folder, ${SLUG}/, holding ${SLUG}.php."

UNWANTED=$(unzip -Z1 "$ROOT/${SLUG}.zip" | grep -E '(^|/)(\.[^/]+|tests|docs|bin|languages|node_modules|vendor)(/|$)|\.md$|composer\.(json|lock)$|package(-lock)?\.json$|\.dist$' || true)
if [ -n "$UNWANTED" ]; then
	echo "FAIL: the archive contains files that should not ship:"; echo "$UNWANTED"; exit 1
fi
echo "No tests, docs, translations, dotfiles or dev tooling in the archive."

# Plugin Check, on exactly what ships.
docker exec -u root "$CONTAINER" sh -c 'rm -rf /tmp/pcp && mkdir -p /tmp/pcp && chmod 777 /tmp/pcp'
run sh -c "cd /tmp/pcp && unzip -q /tmp/${SLUG}.zip"
echo; echo "Plugin Check $(run wp plugin get plugin-check --field=version):"
REPORT="$(run wp plugin check "/tmp/pcp/${SLUG}" --format=csv 2>&1 || true)"
ERRORS=$(echo "$REPORT" | grep -c ',ERROR,' || true)
WARNINGS=$(echo "$REPORT" | grep -c ',WARNING,' || true)
[ -z "$(echo "$REPORT" | grep -E ',(ERROR|WARNING),' || true)" ] || echo "$REPORT"
echo "${ERRORS} error(s), ${WARNINGS} warning(s)"
[ "$ERRORS" -eq 0 ]
