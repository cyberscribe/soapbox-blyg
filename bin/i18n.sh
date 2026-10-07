#!/usr/bin/env bash
#
# Translation files. Uses wp-env's development container, which has WP-CLI.
#
#   bin/i18n.sh pot      Regenerate languages/soapbox-blyg.pot from the code.
#   bin/i18n.sh build    From every languages/*.po, make the .mo, the .l10n.php and the JSON files
#                        the scripts load. Run after copying in or changing a .po.
#   bin/i18n.sh check    msgfmt's checks on every .po, and that each translation has exactly
#                        the placeholders of its original.
#   bin/i18n.sh pseudo   Install a pseudo-locale (xx_XX) on the development site, switch the
#                        site to it, and fail if any text the plugin shows was not translated.
#                        Leaves the site in xx_XX for a look in the browser; `bin/i18n.sh
#                        english` puts it back.
#
# The .po files are written by translators and are the source. Everything else in languages/
# is derived from them and from the code, and is rebuilt, never edited.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="$(basename "$ROOT")"
WP="$ROOT/bin/wp-env-wp"
IN="wp-content/plugins/${SLUG}"
EXCLUDE="vendor,vendor-prefixed,node_modules,tests,assets/src,bin,docs"

case "${1:-}" in
pot)
	$WP i18n make-pot "$IN" "$IN/languages/${SLUG}.pot" --exclude="$EXCLUDE"
	;;
build)
	ls "$ROOT"/languages/*.po >/dev/null 2>&1 || { echo "no .po files in languages/"; exit 0; }
	# Derived files are made afresh, so that one whose source string has gone does not linger.
	rm -f "$ROOT"/languages/*.mo "$ROOT"/languages/*.json "$ROOT"/languages/*.l10n.php
	$WP i18n make-mo "$IN/languages"
	$WP i18n make-php "$IN/languages"
	$WP i18n make-json "$IN/languages" --no-purge
	# WordPress looks for a script's translations by the md5 of the script's path. Every built
	# script must have its file, in every locale, or that script stays in English and nothing says so.
	for po in "$ROOT"/languages/*.po; do
		for js in "$ROOT"/build/*.js; do
			want="${po%.po}-$(printf 'build/%s' "$(basename "$js")" | md5 2>/dev/null || printf 'build/%s' "$(basename "$js")" | md5sum | cut -d' ' -f1).json"
			[ -e "$want" ] || { echo "FAIL: no translations for $(basename "$js") in $(basename "$po"): expected $(basename "$want")" >&2; exit 1; }
		done
	done
	echo "$(ls "$ROOT"/languages/*.po | wc -l | tr -d ' ') locales: $(ls "$ROOT"/languages/*.mo | wc -l | tr -d ' ') .mo, $(ls "$ROOT"/languages/*.l10n.php | wc -l | tr -d ' ') .l10n.php, $(ls "$ROOT"/languages/*.json | wc -l | tr -d ' ') .json"
	;;
check)
	fails=0
	for po in "$ROOT"/languages/*.po; do
		[ -e "$po" ] || { echo "no .po files in languages/"; exit 0; }
		msgfmt -c --check-format --statistics -o /dev/null "$po" 2>&1 | sed "s|^|$(basename "$po"): |" || fails=1
		python3 "$ROOT/tests/i18n/placeholders.py" "$po" || fails=1
	done
	exit $fails
	;;
pseudo)
	python3 "$ROOT/tests/i18n/pseudo.py" "$ROOT/languages/${SLUG}.pot" > "/tmp/${SLUG}-xx_XX.po"
	CONTAINER="$(docker ps --filter "label=com.docker.compose.service=cli" --format '{{.Names}}' | grep "^wp-env-${SLUG}-" | head -n 1)"
	# Into WordPress's own languages directory, where a language pack would go: never into the plugin.
	docker exec -u root "$CONTAINER" sh -c 'mkdir -p /var/www/html/wp-content/languages/plugins && chmod -R 777 /var/www/html/wp-content/languages'
	docker cp "/tmp/${SLUG}-xx_XX.po" "$CONTAINER:/var/www/html/wp-content/languages/plugins/${SLUG}-xx_XX.po" >/dev/null
	docker exec -u root "$CONTAINER" sh -c "chmod 666 /var/www/html/wp-content/languages/plugins/${SLUG}-xx_XX.po; rm -f /var/www/html/wp-content/languages/plugins/${SLUG}-xx_XX-*.json"
	$WP i18n make-mo wp-content/languages/plugins >/dev/null
	$WP i18n make-php wp-content/languages/plugins >/dev/null
	# make-json finds each script by the path in the .po's references, which are relative to the plugin.
	$WP i18n make-json wp-content/languages/plugins --no-purge >/dev/null
	# WordPress will only be switched to a language it has a core file for. An empty one will do:
	# core's own strings stay in English, which is what the check expects of them.
	docker exec -u root "$CONTAINER" sh -c "printf '%s' '<?php return array( \"messages\" => array() );' > /var/www/html/wp-content/languages/xx_XX.l10n.php; chmod 644 /var/www/html/wp-content/languages/xx_XX.l10n.php"
	$WP option update WPLANG xx_XX >/dev/null
	[ "$($WP eval 'echo get_locale();')" = "xx_XX" ] || { echo "the site did not switch to xx_XX" >&2; exit 2; }
	$WP eval-file "$IN/tests/i18n/check-pseudo.php"
	;;
english)
	$WP option update WPLANG '' >/dev/null
	echo "The development site is back in English."
	;;
*)
	sed -n '3,16p' "$0"; exit 2
	;;
esac
