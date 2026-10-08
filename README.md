# Soapbox Blyg

A WordPress plugin that publishes a site's posts as a blyg under the
[Blygger protocol](https://blygger.org/spec/0.3/): permanent ids, versions, withdrawal, pins, and a
surface other Blygger clients can subscribe to and quote. An independent implementation, not
affiliated with the protocol's authors.

What it does for a site owner is in [`readme.txt`](readme.txt), which is also the WordPress.org
listing. This file is for working on the plugin. The repository is
<https://github.com/cyberscribe/soapbox-blyg>; issues and pull requests go there.

## Layout

| Path | What it is |
|---|---|
| `soapbox-blyg.php` | Plugin header, constants, autoloader, boot |
| `includes/functions.php` | The public API: the only stable interface |
| `src/` | Internal classes, PSR-4 under `SoapboxBlyg\` |
| `src/Ledger/` | Schema, ids, snapshots, the event table, verify |
| `src/Origins/` | Origin registry with the mount lock; the posts source |
| `src/Render/` | `the_content` in a clean context, the sanitiser, Markdown |
| `src/Surface/` | Rewrite routing, REST document routes, the documents, the one HTTP sender |
| `src/Mentions/` | Webmention receiver, verifier, address check, store |
| `src/Admin/`, `src/Rest/`, `src/Cli/` | Screens, admin REST routes, `wp blyg` |
| `templates/` | The withdrawn page and the origin page; a theme can override both |
| `assets/src/` → `build/` | Editor panel and Tools script (`npm run build`) |
| `vendor-prefixed/` | league/html-to-markdown, prefixed by Strauss (`composer install`) |
| `tests/` | PHPUnit, the conformance checks, test environments |
| `bin/` | The acceptance runs, the release build, and the checks they share |

`build/` and `vendor-prefixed/` are committed, so a clone runs as it is and a release needs no
build step.

## Setting up

```bash
composer install        # dev tools, and re-prefixes the bundled library
npm install
npx wp-env start        # http://localhost:8888, admin / password
```

## Tests

| What | Command |
|---|---|
| PHPUnit, current WordPress and PHP | `npx wp-env run tests-cli --env-cwd=wp-content/plugins/soapbox-blyg vendor/bin/phpunit` |
| PHPUnit, oldest supported (WordPress 6.6, PHP 7.4) | `npx wp-env start --config tests/env/min.json`, then the same command with `--config tests/env/min.json` |
| Black-box acceptance, both layouts | `bin/acceptance.sh` (resets the development site) |
| Webmention, end to end | `npx wp-env start --config tests/env/webmention.json && bin/rehearse-mention.sh` |
| Coding standards | `vendor/bin/phpcs` |
| Nothing tracked that should not be | `bin/check-tree.sh` |

In `tests/conformance/`, `blyg-check.py` checks a live origin against the protocol from outside,
and `scenario.sh` drives a post through its whole life. They know nothing of the plugin's
internals, and should stay that way.

## Rules worth knowing before changing anything

- **Rendering is hashed.** `content_md` is what the content hash covers, so a change to
  `src/Render/` that alters one byte of output gives every affected post a new version at its next
  save. Run `wp blyg verify --render` on a real site, before and after, and compare a dry run
  of the backfill (`wp blyg backfill --dry-run --dump=<dir>`) from each side.
- **The ledger is append-only.** Version rows are never updated, apart from the one-way pin flag,
  and nothing is deleted.
- **Published addresses do not move.** An origin's mount and layout are locked at its first item,
  in `Origins\Registry`, and that lock overrules settings and code alike.
- **One sender.** Every response of the public surface goes through `Surface\Http::send()`,
  whichever route it arrived by.
- **No outbound requests**, except `Mentions\Verifier`, which runs only from the queue and only
  while the receiver is switched on.

## What is not in this repository

Working papers (specifications in draft, handoffs, reviews, runbooks, the backlog) and anything a
build leaves behind (archives, releases, caches) are kept out. `bin/check-tree.sh` fails if one is
tracked, and `bin/dist.sh` runs it before it builds.

## Releasing

`wp dist-archive .` builds the zip, leaving out everything in `.distignore`. Releases to
WordPress.org are made by hand from that zip.

## Renaming

The slug is permanent once WordPress.org approves the plugin. Until then,
`bin/rename.sh <new-slug> "<New Name>"` renames everything in one pass: the constant in the main
file drives what PHP can derive at runtime, and the script handles what it cannot (text domain
literals, function, hook and option names, the namespace, file names).

## Licence

GPL-2.0-or-later. The full text is in [`LICENSE`](LICENSE). The bundled
league/html-to-markdown is MIT, and its licence is kept beside it in `vendor-prefixed/`.
