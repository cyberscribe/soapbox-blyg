#!/usr/bin/env bash
#
# Translation files. Uses wp-env's development container, which has WP-CLI.
#
#   bin/i18n.sh pot      Regenerate languages/<text domain>.pot from the code.
#   bin/i18n.sh check    msgfmt's checks on every .po, and that each translation has exactly
#                        the placeholders of its original.
#   bin/i18n.sh pseudo   Install a pseudo-locale (xx_XX) on the development site, switch the
#                        site to it, and fail if any text the plugin shows was not translated.
#                        Leaves the site in xx_XX for a look in the browser; `bin/i18n.sh
#                        english` puts it back.
#
# Nothing in languages/ ships with the plugin, and nothing in it is compiled. The .pot is made
# from the code; the .po files are the translators', kept here as the source to import into
# translate.wordpress.org, which is where a site's language pack comes from. The pseudo-locale is
# installed where a language pack goes, in WordPress's own languages directory, so it tests the
# path a real translation takes.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# Where wp-env mounts this checkout, which is named after its directory, and the text domain,
# which is the plugin's slug on WordPress.org and is read from its header. They differ.
DIR="$(basename "$ROOT")"
SLUG="$(sed -n 's/^ \* Text Domain: *//p' "$ROOT"/*.php | head -n 1)"
[ -n "$SLUG" ] || { echo "no Text Domain in the plugin's header" >&2; exit 2; }
WP="$ROOT/bin/wp-env-wp"
IN="wp-content/plugins/${DIR}"
EXCLUDE="vendor,vendor-prefixed,node_modules,tests,assets/src,bin,docs"

case "${1:-}" in
pot)
	$WP i18n make-pot "$IN" "$IN/languages/${SLUG}.pot" --exclude="$EXCLUDE"
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
	CONTAINER="$(docker ps --filter "label=com.docker.compose.service=cli" --format '{{.Names}}' | grep "^wp-env-${DIR}-" | head -n 1)"
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
