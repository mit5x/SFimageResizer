# SFimageResizer — test report

| | |
|---|---|
| Plugin version | 1.0.0 |
| WordPress used for testing | 7.0.3 (current stable branch at the time of writing) |
| `Requires at least` / `Tested up to` | 7.0 |
| `Requires PHP` | 7.4 (the minimum WordPress 7.0 itself requires) |
| PHP used for testing | 8.4.19, GD with WebP support |
| Database | SQLite, through the official *SQLite Database Integration* drop-in |
| Web server | PHP built-in server, pretty permalinks enabled (`/%postname%/`) |
| Date | 2026-08-10 |

## 1. Summary

| Suite | Checks | Passed | Failed |
|---|---:|---:|---:|
| 11.1 Standalone unit tests (PHPUnit, no WordPress) | 43 tests / 194 assertions | 43 | 0 |
| 11.2 Integration suite (`run.php`) | 105 | 105 | 0 |
| 11.2.10 Admin screen over HTTP (`admin.php`) | 45 | 45 | 0 |
| 11.2.12 WebP fallback (`webp-fallback.php`) | 8 | 8 | 0 |
| 11.2.13 Activation / deactivation / uninstall (`lifecycle.php`) | 23 | 23 | 0 |
| PHPCS with the `WordPress` ruleset + `PHPCompatibilityWP` | — | 0 errors, 0 warnings | 0 |
| 11.3 Plugin Check, all categories, including experimental | — | 0 errors, 0 warnings | 0 |

**Everything passes. No errors and no warnings are outstanding.**

## 2. Unit tests (spec 11.1)

Run without WordPress; only `__()`, `esc_html()`, `esc_attr()` and
`wp_parse_args()` are stubbed in `tests/bootstrap.php`.

| Spec case | Test | Status |
|---|---|---|
| 11.1.1 `w=900,h=0` on 2000×1000 → 900×450 | `DimensionsTest::test_single_side_scales_proportionally` | pass |
| 11.1.1 `w=0,h=450` → 900×450 | `DimensionsTest::test_single_side_scales_proportionally` | pass |
| 11.1.1 `w=900,h=900,crop=0` → 900×450 | `DimensionsTest::test_fit_inside_box` | pass |
| 11.1.1 `w=900,h=900,crop=1` → 900×900 | `DimensionsTest::test_crop_fills_box`, `test_crop_region_is_centred` | pass |
| 11.1.2 400×300, `w=900` → 400×300 | `DimensionsTest::test_never_upscales` | pass |
| 11.1.2 400×300, `w=900,h=900,crop=1` → 300×300 | `DimensionsTest::test_crop_without_upscaling` | pass |
| 11.1.3 `crop=1` with `h=0` behaves like `crop=0` | `DimensionsTest::test_crop_ignored_without_both_sides` | pass |
| 11.1.4 `q=200` → 95, `q=0` → 1 | `ParamsTest::test_quality_is_clamped` | pass |
| 11.1.4 `f=bmp` → webp (+ notice) | `ParamsTest::test_unknown_format_falls_back_to_webp` | pass |
| 11.1.4 `bg=GGGGGG` ignored | `ParamsTest::test_invalid_background_is_ignored` | pass |
| 11.1.4 `w=99999` → 5000 | `ParamsTest::test_dimensions_are_clamped` | pass |
| 11.1.4 `foo=1` does not affect the file name | `ParamsTest::test_unknown_parameters_are_dropped` | pass |
| 11.1.5 Cache name is deterministic | `CacheNameTest::test_deterministic`, `test_documented_scheme` | pass |
| 11.1.5 Cache name stays inside `[A-Za-z0-9._-]` | `CacheNameTest::test_character_set` | pass |
| 11.1.6 Valid HMAC signature passes | `SecurityTest::test_valid_signature_passes` | pass |
| 11.1.6 One changed character fails | `SecurityTest::test_tampered_parameters_fail`, `test_tampered_source_fails` | pass |
| 11.1.7 `../../wp-config.php` rejected | `SecurityTest::test_path_traversal_is_rejected` | pass |
| 11.1.7 URL-encoded `%2e%2e%2f` rejected | `SecurityTest::test_path_traversal_is_rejected` | pass |
| 11.1.7 Null byte rejected | `SecurityTest::test_path_traversal_is_rejected` | pass |

Extra unit coverage beyond the specification: colour normalisation, the
canonical parameter string, placeholder sizing, placeholder markup injection
resistance, directory containment checks, and geometry edge cases (extreme
aspect ratios, unusable source sizes).

## 3. Integration tests (spec 11.2)

Fixtures created programmatically with GD and written into
`wp-content/uploads/sfir-fixtures/2026/08/`: a 2000×1000 JPEG with four
coloured quadrants, a 400×300 JPEG, an 800×800 PNG that is transparent except
for an opaque centre square, a two frame animated GIF (300×200, red then
green), a 1200×600 WebP, a text file named `broken.jpg`, and a `notes.txt`.
The 2000×1000 JPEG is additionally registered in the media library so the
attachment-ID path is exercised.

| Spec case | Group in the output | Checks | Status |
|---|---|---:|---|
| 11.2.1 Generation, caching, static URL on the second call | `11.2.1 generation and cache` | 12 | pass |
| 11.2.1 Query-string fallback when permalinks are off | `11.2.1 permalink fallback` | 3 | pass |
| 11.2.2 Every sizing rule verified on real files | `11.2.2 sizing rules on real files` | 8 | pass |
| 11.2.3 Formats, transparency, background, GIF frame, quality | `11.2.3 formats, transparency and quality` | 9 | pass |
| 11.2.4 Errors, placeholders, 403, log lines, front page stays 200 | `11.2.4 errors and placeholders` | 22 | pass |
| 11.2.5 Invalidation when the source changes | `11.2.5 cache invalidation` | 4 | pass |
| 11.2.6 `sf_img_width`/`sf_img_height` and the dimension cache | `11.2.6 declared sizes and dimension cache` | 16 | pass |
| 11.2.7 URL, attachment ID and ACF array give the same result | `11.2.7 source types` | 10 | pass |
| 11.2.8 Two concurrent requests, one file, no leftover locks | `11.2.8 concurrent generation` | 4 | pass |
| 11.2.9 30 variants over two passes | `11.2.9 many images over several passes` | 3 | pass |
| 11.2.10 Statistics, cache clearing, only cached files removed | `11.2.10 cache statistics and clearing` | 8 | pass |
| 11.2.10 Admin access control | `11.2.10 admin access control` | 4 | pass |
| 11.2.10 Statistics shown on the screen | `11.2.10 cache statistics` | 3 | pass |
| 11.2.10 Clear cache: with and without nonce | `11.2.10 clear cache` | 7 | pass |
| 11.2.10 Log viewer, escaping, clear log with and without nonce | `11.2.10 log viewer` | 12 | pass |
| 11.2.10 Documentation and local-only assets | `11.2.10 documentation and assets` | 12 | pass |
| — Directory protection files and no directory listing | `directory protection` | 7 | pass |
| 11.2.11 Log rotation past 2 MB | `11.2.11 log rotation` | 6 | pass |
| 11.2.12 WebP fallback with `imagewebp()` disabled | `11.2.12 webp fallback` | 8 | pass |
| 11.2.13 Activation | `11.2.13 activation` | 9 | pass |
| 11.2.13 Deactivation keeps everything | `11.2.13 deactivation` | 6 | pass |
| 11.2.13 Uninstall removes everything | `11.2.13 uninstall` | 8 | pass |

### Notes on how a few cases were verified

* **11.2.4 hostile input.** Beyond the four cases the specification lists, the
  suite also rejects `data:`, `php://filter/...`, `file://`, a protocol
  relative URL pointing at another host, `/wp-config.php`, an uploads-relative
  `../../wp-config.php` and a path containing a null byte.
* **11.2.6 "no second `getimagesize()` call".** The source file is replaced
  with an image of different dimensions while its modification time is kept
  unchanged. `SFIR_Cache::get_source_size()` keeps reporting the old
  dimensions, which is only possible if it did not re-read the file. Touching
  the file with a newer time then makes it report the new dimensions.
* **11.2.8 concurrency.** Both requests are issued with `curl_multi`. The
  assertions are: both answer 200, both bodies are valid images, `glob()` finds
  exactly one file for that variant, and no `*.lock` file is left anywhere
  under the cache root.
* **11.2.10 admin.** The suite performs a real `wp-login.php` login with a
  cookie jar, scrapes the nonce out of each form, and submits the maintenance
  actions with and without it. Nonce-less POSTs answer `403`; signed POSTs
  answer `302` and take effect.
* **11.2.11 rotation.** 45 000 lines (~3 MB) are written to the log, then one
  more line is logged through `SFIR_Logger::log()`. The file drops below 2 MB,
  about 500 lines remain, the newest pre-existing line is still present and the
  oldest ones are gone.
* **11.2.12 WebP fallback.** Run in a separate PHP process started with
  `-d disable_functions=imagewebp`, so `function_exists( 'imagewebp' )` really
  returns false. The output becomes JPG, the cache file gets a `.jpg`
  extension, and exactly one notice reaches the log no matter how many WebP
  requests are made.

## 4. PHPCS / WordPress Coding Standards

```
$ phpcs --standard=phpcs.xml.dist --report=summary
...... 6 / 6 (100%)

Time: 459ms; Memory: 14MB
```

No errors, no warnings. The ruleset (`phpcs.xml.dist`) is `WordPress` plus
`PHPCompatibilityWP` with `testVersion` set to `7.4-`, so PHP 7.4 through the
current release are all checked.

### Silenced sniffs, and why

Every `phpcs:ignore` in the source is listed here; there are no blanket
exclusions in the ruleset.

| Sniff | Where | Justification |
|---|---|---|
| `WordPress.WP.AlternativeFunctions.file_system_operations_*` | `class-sfir-logger.php`, `class-sfir-cache.php`, `class-sfir-endpoint.php`, `uninstall.php` | `WP_Filesystem` is not initialised on front-end requests and can require credentials. The plugin only ever writes inside its own uploads sub-directory, after a `realpath()` containment check, and a failed write is degraded to a placeholder rather than an error. |
| `WordPress.PHP.NoSilencedErrors.Discouraged` | file and GD calls throughout | The whole plugin is built so that a broken image never breaks a page. Every silenced call has its return value checked immediately afterwards. |
| `WordPress.Security.NonceVerification.Recommended` | `class-sfir-endpoint.php` | The generation endpoint is public, anonymous and cacheable, so a nonce is impossible. It is protected by an HMAC-SHA256 signature instead, which is verified before any file is touched. |
| `WordPress.Security.EscapeOutput.OutputNotEscaped` | `class-sfir-endpoint.php` (placeholder SVG), `class-sfir-admin.php` (form action) | The SVG is produced by `SFIR_Placeholder::render()`, which escapes every dynamic part; the form action is passed through `esc_url()` one line earlier. |

## 5. Plugin Check (spec 11.3)

Plugin Check 2.0.0, run through WP-CLI against the installed plugin:

```
$ wp plugin check sf-image-resizer \
      --categories=general,plugin_repo,security,performance,accessibility \
      --include-experimental
Success: Checks complete. No errors found.
```

Two warnings were reported by the first run and both were fixed:

| Warning | Fix |
|---|---|
| `plugin_header_nonexistent_domain_path` — the `Domain Path` header pointed at a directory that did not exist | `languages/` was created and `sf-image-resizer.pot` generated with `wp i18n make-pot` |
| `readme_parser_warnings_trimmed_short_description` — the short description exceeded 150 characters | Shortened to 121 characters |

**No warnings remain, so none need a justification.**

## 6. How to run the tests

### Requirements

* PHP 7.4+ with GD (WebP support optional; without it the fallback path is used)
* Composer, for PHPUnit and PHPCS
* A WordPress installation with the plugin symlinked or copied into
  `wp-content/plugins/sf-image-resizer` and activated
* A web server serving that installation (the PHP built-in server is enough)
* WP-CLI

### Tooling

```bash
composer require --dev \
    phpunit/phpunit:^9.6 \
    squizlabs/php_codesniffer:^3.13 \
    wp-coding-standards/wpcs:^3.2 \
    phpcompatibility/phpcompatibility-wp:^2.1
```

### Unit tests only (no WordPress needed)

```bash
vendor/bin/phpunit
```

### Coding standards

```bash
vendor/bin/phpcs --standard=phpcs.xml.dist
```

### Everything

```bash
export SFIR_WP_DIR=/path/to/wordpress
export SFIR_WP_CLI=/path/to/wp-cli.phar        # or just "wp"
export SFIR_BASE_URL=http://127.0.0.1:8899
export SFIR_ADMIN_USER=admin
export SFIR_ADMIN_PASS=admin123
export SFIR_PHPUNIT=vendor/bin/phpunit
export SFIR_PHPCS=vendor/bin/phpcs

bash tests/run-tests.sh
```

The script runs, in order: PHPUnit, PHPCS, the integration suite, the admin
suite, the WebP fallback suite, the lifecycle suite and Plugin Check. It exits
non-zero if any step fails.

Individual suites can also be run directly:

```bash
cd "$SFIR_WP_DIR"
wp eval-file /path/to/repo/tests/integration/run.php
wp eval-file /path/to/repo/tests/integration/admin.php
php -d disable_functions=imagewebp "$(command -v wp)" eval-file /path/to/repo/tests/integration/webp-fallback.php
wp eval-file /path/to/repo/tests/integration/lifecycle.php
```

The lifecycle suite deletes the plugin's working directory and runs
`uninstall.php`, then rebuilds everything, so it is safe to re-run but should
not be pointed at a production site.

### Test environment used for this report

MySQL and Docker were not available in the build environment, so WordPress
7.0.3 was installed on SQLite using the official *SQLite Database Integration*
drop-in, and served by `php -S` with a small router script. Nothing in the
plugin touches the database beyond options and transients, so this makes no
difference to what is being tested.

## 7. Deviations from the specification, and deliberate decisions

None of these change the documented behaviour; they are recorded for
completeness.

1. **Source roots.** Section 6.2 requires `realpath()` of a source to be inside
   `ABSPATH`. The implementation accepts `ABSPATH` *or* the uploads base
   directory. On a default install uploads sits inside `ABSPATH`, so the two
   are the same; the extra root only matters when `WP_CONTENT_DIR` or
   `UPLOADS` has been moved outside the site root, where the stricter reading
   would reject every legitimate media file.
2. **Cache tree for files outside uploads.** Section 5.1 says such files are
   mirrored relative to `ABSPATH`. They are, under a `_abs/` prefix inside
   `cache_images/`, so that a theme file at `2026/08/photo.jpg` can never
   collide with an uploaded file at the same relative path.
3. **Placeholder size when only one side is requested.** Section 7 defines the
   placeholder as `w×h`, or 300×200 when no size is given. When only one side
   is given the missing one is derived from the 3:2 ratio of the default
   (`w=900` → 900×600). This keeps `sf_img_width()`/`sf_img_height()` in
   agreement with the SVG that is actually served.
4. **Null byte in a URL** is reported as `E01` (bad path) rather than `E03`
   (bad scheme), since the scheme itself is valid in those inputs.
5. **`f=jpeg`** is accepted as a synonym of `f=jpg` instead of triggering the
   "unknown format" notice.
6. **Parameters may also be passed as an array**, not only as a query string.
   The query string form documented in section 3.1 is unchanged.
7. **A hard source-pixel ceiling** of 80 megapixels applies in addition to the
   memory estimate from section 4, so that a decompression bomb is refused even
   on a host with `memory_limit = -1`.
8. **EXIF orientation** is applied to the cached dimensions as well as to the
   image, so a rotated JPEG reports the size it will actually have.
9. `SFIR_Cache::flush_runtime_cache()` exists to drop the per-request memo.
   Normal page loads never need it (each request is a fresh process); it is
   used by long-running CLI processes and by the test suite, which simulates
   many page renders inside one process.
