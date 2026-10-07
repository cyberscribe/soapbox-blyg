#!/usr/bin/env bash
#
# Renames the plugin, as one mechanical change to commit on its own.
#
# SOAPBOX_BLYG_SLUG in the main file drives everything PHP can derive at runtime. This script
# covers what a constant cannot reach: the text domain literals that gettext tooling requires,
# function, hook, option and table names, the PHP namespace, the constant prefix, and file names.
#
# Only before the first release. Once anything is installed, option and table names are data.
#
#   bin/rename.sh <new-slug> "<New Display Name>"      e.g. bin/rename.sh quill-blyg "Quill Blyg"

set -euo pipefail

[ $# -eq 2 ] || { echo "usage: bin/rename.sh <new-slug> \"<New Display Name>\"" >&2; exit 2; }
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

NEW_SLUG="$1"; NEW_NAME="$2"
[[ "$NEW_SLUG" =~ ^[a-z][a-z0-9]*(-[a-z0-9]+)*$ ]] || { echo "the slug must be lowercase words joined by hyphens" >&2; exit 2; }

OLD_SLUG="soapbox-blyg"; OLD_NAME="Soapbox Blyg"
OLD_SNAKE="${OLD_SLUG//-/_}"; NEW_SNAKE="${NEW_SLUG//-/_}"
OLD_CONST="$(echo "$OLD_SNAKE" | tr '[:lower:]' '[:upper:]')"; NEW_CONST="$(echo "$NEW_SNAKE" | tr '[:lower:]' '[:upper:]')"
pascal() { echo "$1" | awk -F- '{ for (i = 1; i <= NF; i++) printf "%s%s", toupper(substr($i, 1, 1)), substr($i, 2) }'; }
OLD_NS="$(pascal "$OLD_SLUG")"; NEW_NS="$(pascal "$NEW_SLUG")"
camel() { local p; p="$(pascal "$1")"; echo "$(echo "${p:0:1}" | tr '[:upper:]' '[:lower:]')${p:1}"; }  # The scripts' global.
OLD_CAMEL="$(camel "$OLD_SLUG")"; NEW_CAMEL="$(camel "$NEW_SLUG")"

FILES=$(git ls-files | grep -v -E '^(docs/|package-lock\.json$|composer\.lock$|build/.*\.js$)')
# Longest and most specific forms first, so that none is half-replaced by another.
# shellcheck disable=SC2086
perl -pi -e "s/\Q$OLD_NAME\E/$NEW_NAME/g; s/\Q$OLD_CONST\E/$NEW_CONST/g; s/\Q$OLD_NS\E/$NEW_NS/g; s/\Q$OLD_CAMEL\E/$NEW_CAMEL/g; s/\Q$OLD_SNAKE\E/$NEW_SNAKE/g; s/\Q$OLD_SLUG\E/$NEW_SLUG/g" $FILES

for f in $(git ls-files | grep "$OLD_SLUG"); do
	git mv "$f" "${f//$OLD_SLUG/$NEW_SLUG}"
done

composer run prefix-namespaces >/dev/null   # vendor-prefixed/ carries the namespace prefix.
npm run build >/dev/null                    # build/ carries the text domain and the script handles.

LEFT=$(git grep -n -i -E "soapbox.?blyg" -- . ':!docs' ':!package-lock.json' ':!composer.lock' | grep -v "bin/rename.sh" || true)
if [ -n "$LEFT" ]; then
	echo "These still carry the old name:"; echo "$LEFT"; exit 1
fi
echo "Renamed to $NEW_SLUG ($NEW_NAME). Rename the directory to match, run the tests, and commit."
