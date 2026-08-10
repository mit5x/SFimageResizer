# Working conventions for this repository

Rules that apply to every change here, for humans and for AI assistants alike.

## What ships

`sf-image-resizer/` is the plugin and the only thing that ships. Everything
else in the repository is development material.

## Never commit build artifacts

Do not add a `.zip` anywhere in the tree, and do not create a `download/`
directory for release files. Release archives are built by
`.github/workflows/release.yml` and attached to the GitHub Release; a binary
committed to the tree stays in the git history forever. `.gitignore` already
blocks `download/`, `*.zip` and `vendor/`.

If someone asks for "the archive", the answer is a link to the GitHub Release
asset, or a locally built zip that stays untracked.

## Releasing

Follow `BUILD.md`. In short: bump the version in the three places that must
agree, add the changelog entry, push, then push a `vX.Y.Z` tag. The workflow
does the rest and refuses to publish if the versions disagree or the archive
contains development files.

## Before pushing any change to the plugin

Both of these must be clean:

```bash
composer install          # add COMPOSER_ALLOW_SUPERUSER=1 when running as root
composer run test         # PHPUnit, spec 11.1
composer run lint         # PHPCS, WordPress Coding Standards
```

CI runs the same two commands on PHP 7.4 and 8.4, plus the full WordPress
integration suite and Plugin Check. Keep all of it green: the plugin is meant
to enter the WordPress.org directory, where Plugin Check errors are blocking.

The full local suite needs a WordPress installation and a web server; see
section 6 of `TEST-REPORT.md`, then run `tests/run-tests.sh`.

## Coding rules the plugin follows

* Minimum PHP is **7.4** and minimum WordPress is the current stable branch.
  Do not use syntax newer than PHP 7.4. `phpcs.xml.dist` enforces this through
  `PHPCompatibilityWP` with `testVersion` set to `7.4-`.
* Prefix everything global: `sfir_` for functions, `SFIR_` for classes and
  constants, `sfir_` for options and transients. The four documented template
  helpers `sf_img`, `sf_img_width`, `sf_img_height` and `sf_img_tag` are the
  only exception, and each is wrapped in `function_exists()`.
* Every PHP file starts with `defined( 'ABSPATH' ) || exit;`
  (`uninstall.php` uses `defined( 'WP_UNINSTALL_PLUGIN' ) || exit;`).
* Every user-visible string is translatable with the `sf-image-resizer` text
  domain. Regenerate `languages/sf-image-resizer.pot` when strings change.
* Escape on output: `esc_html()`, `esc_attr()`, `esc_url()`, `esc_textarea()`.
* Admin actions are POST + nonce + `current_user_can( 'manage_options' )`.
* No external HTTP requests, no tracking, no CDN assets, no bundled binaries.
* GD only. No Imagick, no AVIF, no S3/CDN, no REST API, no page builder
  integrations, no settings beyond the ones already on the admin screen.
* A broken image must never break a page: failures degrade to an SVG
  placeholder with an error code plus one log line.

## Every `phpcs:ignore` needs a reason

Suppressions are written inline with a justification, never as a blanket
exclusion in `phpcs.xml.dist`, and each one is listed in section 4 of
`TEST-REPORT.md`. Add new ones to that table.

## Keep the test report honest

`TEST-REPORT.md` states the counts and the environment of the last full run.
When you change behaviour, re-run the suite and update the numbers, the
deviations list in section 7, and the Plugin Check output.
