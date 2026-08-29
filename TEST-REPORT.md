# SFimageResizer — test report

| | |
|---|---|
| Plugin version | 1.3.0 |
| WordPress used for testing | 7.0.3 (current stable branch at the time of writing) |
| `Requires at least` / `Tested up to` | 7.0 |
| `Requires PHP` | 7.4 (the minimum WordPress 7.0 itself requires) |
| PHP used for testing | 8.4.19, GD with WebP support |
| Database | SQLite, through the official *SQLite Database Integration* drop-in |
| Web server | PHP built-in server, pretty permalinks enabled (`/%postname%/`) |
| Date | 2026-08-29 |
| Web server note | `PHP_CLI_SERVER_WORKERS=6`, so the admin self-check can call the site over loopback |

## 1. Summary

| Suite | Checks | Passed | Failed |
|---|---:|---:|---:|
| 11.1 Standalone unit tests (PHPUnit, no WordPress) | 56 tests / 281 assertions | 56 | 0 |
| 11.2 Integration suite (`run.php`) | 168 | 168 | 0 |
| 11.2.10 Admin screen and self-check over HTTP (`admin.php`) | 148 | 148 | 0 |
| 11.2.12 WebP fallback (`webp-fallback.php`) | 8 | 8 | 0 |
| 11.2.13 Activation / deactivation / uninstall (`lifecycle.php`) | 29 | 29 | 0 |
| PHPCS with the `WordPress` ruleset + `PHPCompatibilityWP` | — | 0 errors, 0 warnings | 0 |
| 11.3 Plugin Check, all categories, including experimental | — | 0 errors, 0 warnings | 0 |

**Everything passes. No errors and no warnings are outstanding.**

## 2. Unit tests (spec 11.1)

Run without WordPress; only `__()`, `esc_html()`, `esc_attr()`,
`wp_parse_args()`, `get_option()`, `update_option()` and `apply_filters()` are
stubbed in `tests/bootstrap.php`, along with a two-method stand-in for
`SFIR_Diagnostics` so the generation mode can be resolved without a database.

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
resistance, directory containment checks, geometry edge cases (extreme aspect
ratios, unusable source sizes), and the 1.3.0 generation mode — the three
values, the rejection of anything else, how "automatic" follows the
configuration check, that the answer is memoised for the whole request, and
that the rendering budget is a positive, filterable pair of limits
(`GeneratorTest`).

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
| 11.2.1 Plain cache URL from the first call, generation, static second request | `11.2.1 pretty cache URLs` | 15 | pass |
| 11.2.1 Tampered hash, unsigned name and traversal are refused | `11.2.1 signature in the file name` | 7 | pass |
| 11.2.1 The retired `sfir-generate` route is gone | `11.2.1 retired endpoint` | 3 | pass |
| 11.2.1 The URL does not depend on the permalink structure | `11.2.1 permalinks` | 3 | pass |
| 11.2.2 Every sizing rule verified on real files | `11.2.2 sizing rules on real files` | 8 | pass |
| 11.2.3 Formats, transparency, background, GIF frame, quality | `11.2.3 formats, transparency and quality` | 9 | pass |
| 11.2.4 Errors, placeholders, 403, log lines, front page stays 200 | `11.2.4 errors and placeholders` | 22 | pass |
| 11.2.5 Invalidation when the source changes | `11.2.5 cache invalidation` | 5 | pass |
| 11.2.6 `sf_img_width`/`sf_img_height` and the dimension cache | `11.2.6 declared sizes and dimension cache` | 16 | pass |
| 11.2.7 URL, attachment ID and ACF array give the same result | `11.2.7 source types` | 10 | pass |
| 11.2.8 Two concurrent requests, one file, no leftover locks | `11.2.8 concurrent generation` | 4 | pass |
| 11.2.9 30 variants over two passes | `11.2.9 many images over several passes` | 4 | pass |
| 11.2.10 Statistics, cache clearing, only cached files removed | `11.2.10 cache statistics and clearing` | 8 | pass |
| 11.2.10 Admin access control | `11.2.10 admin access control` | 4 | pass |
| 11.2.10 Statistics shown on the screen | `11.2.10 cache statistics` | 3 | pass |
| 11.2.10 Clear cache: with and without nonce | `11.2.10 clear cache` | 7 | pass |
| 11.2.10 Log viewer, escaping, clear log with and without nonce | `11.2.10 log viewer` | 12 | pass |
| 11.2.10 Documentation and local-only assets | `11.2.10 documentation and assets` | 14 | pass |
| — Self-check level 1: real traffic records the confirmation, throttled | `self-check level 1: real traffic` | 7 | pass |
| — Self-check level 1: the screen shows it and warns about nothing | `self-check level 1: what the screen shows` | 7 | pass |
| — Self-check level 2: the browser probe and its collapsed hints | `self-check level 2: the browser probe` | 11 | pass |
| — Self-check: the confirmation endpoint, capability and nonce | `self-check: the confirmation endpoint` | 9 | pass |
| — Self-check: "check again" resets the confirmation | `self-check: reset` | 5 | pass |
| — Directory protection files and no directory listing | `directory protection` | 7 | pass |
| 1.2.0 `sf_img_srcset()`: widths, descriptors, no upscaling, dedupe | `1.2.0 sf_img_srcset` | 27 | pass |
| 1.2.0 The three tabs and what each one carries | `1.2.0 tabs` | 12 | pass |
| 1.2.0 Language picker, seven locales, per-user memory | `1.2.0 language` | 28 | pass |
| 1.2.0 The Markdown reference, copy button and download | `1.2.0 markdown reference` | 7 | pass |
| 1.2.0 The "Settings" link in the plugins list | `1.2.0 plugins list` | 3 | pass |
| 1.3.0 Render-time generation, the budget and the fallback | `1.3.0 render-time generation` | 21 | pass |
| 1.3.0 The generation mode setting, nonce and validation | `1.3.0 generation mode` | 12 | pass |
| 11.2.11 Log rotation past 2 MB | `11.2.11 log rotation` | 6 | pass |
| 11.2.12 WebP fallback with `imagewebp()` disabled | `11.2.12 webp fallback` | 8 | pass |
| 11.2.13 Activation | `11.2.13 activation` | 12 | pass |
| 11.2.13 Deactivation keeps everything | `11.2.13 deactivation` | 6 | pass |
| 11.2.13 Uninstall removes everything | `11.2.13 uninstall` | 11 | pass |

### Notes on how a few cases were verified

* **Pretty URLs (1.1.0).** `sf_img()` returns
  `.../cache_images/{tree}/{name}-{w}x{h}-c{crop}-q{q}[-bg{BG}]-{hash6}.{ext}`
  on the very first call, with no query string. The suite asserts the file does
  not exist yet, requests the URL, checks that the plugin answered it (the
  `X-SFIR: generated` header), that the file appeared on disk, that a second
  `sf_img()` call returns a byte-identical URL, and that a second HTTP request
  is served by the web server without the plugin header.
* **Signature in the file name.** Changing one character of the six-character
  hash yields 403, an `E04` placeholder, a log line and no file on disk. A name
  with the hash removed is refused too, and `..%2f` inside the mirrored tree is
  refused without creating anything.
* **The configuration self-check (1.1.1).** There is no server side loopback
  request any more: hosts that run bot protection answer one with a challenge
  page, which made the old check report a fault on sites where everything
  worked. Three levels are tested instead. Level 1: a normal front end request
  that generates an image sets `sfir_pretty_urls_confirmed`, a second
  generation the same day does not rewrite it, and a value older than a day
  does. Level 2: with the option cleared, the screen hands the browser a signed
  cache URL, a different one on each render, and fetching it really returns an
  image. The confirmation endpoint refuses a request with no nonce and one from
  a subscriber, accepts a signed one from an administrator, and cleans up the
  probe files. "Check again" clears the option. Level 3: without a confirmation
  the screen says so without claiming a fault, and the nginx snippet and the
  Apache note appear only there, inside a collapsed block.
* **Source lookup.** A cache name only carries a sanitised stem, so the handler
  probes the usual extensions in the mirrored source directory and falls back to
  a directory scan; the HMAC decides which candidate is the right one, so an
  unsigned name can never resolve to a file.

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
* **1.2.0 `sf_img_srcset()`.** The helper is checked against the 2000×1000
  fixture and against the 400×300 one. The assertions are: six candidates for a
  large enough source; every candidate a plain cache URL that really resolves to
  an image of the width its `w` descriptor claims; the list sorted ascending;
  widths the source is too small for dropped rather than upscaled, so the small
  fixture yields a single 400w candidate instead of six identical ones; a custom
  width list honoured; `f`, `q` and `bg` carried over from `$params` while `h`
  and `crop` are forced off; and an unusable source giving an empty string
  rather than a broken attribute.
* **1.2.0 tabs and language.** Each tab is requested over HTTP and asserted to
  carry its own content and not the others'. The language POST is submitted with
  a nonce for all seven locales; after each one the screen is re-fetched and
  checked for a string that only exists in that translation, and the choice is
  read back from user meta. Posting an empty locale deletes the meta and the
  screen returns to English. A locale that is not on the list is refused.
* **1.2.0 the Markdown reference.** The documentation tab is asserted to carry
  the field, the copy button and the download link, and the `.md` file is
  fetched over HTTP and compared byte for byte with what the screen shows.
* **1.3.0 render-time generation.** The three modes are exercised against real
  files. In `never` the disk stays empty after `sf_img()` returns and the URL is
  still the cache file. In `always` the file is on disk the moment `sf_img()`
  returns, really has the requested width and format, and an HTTP request for it
  comes back 200 **without** the `X-SFIR` header — proving the web server
  answered it as a static file and the plugin was not involved, which is the
  whole point of the mode. Every candidate of a `sf_img_srcset()` is checked to
  exist and to have the width its descriptor claims.
* **1.3.0 the budget and the fallback.** A filter on `sfir_generation_budget`
  reduces the budget to nothing, which is the state a page reaches after enough
  images. `sf_img()` then returns the untouched original — asserted to be byte
  for byte the source URL, to answer 200 over HTTP, and to be described by
  `sf_img_width()`/`sf_img_height()` with the original's dimensions rather than
  the requested ones. `sf_img_srcset()` returns an empty string rather than
  candidates carrying widths they do not have.
* **1.3.0 the probe stays honest.** With the mode set to `always`, the URL the
  browser check hands out is asserted still to point at a file that does not
  exist. Render-time generation must not pre-create it, or the check would
  confirm the request path on a server where it does not work, and "automatic"
  would then switch the site into a mode that leaves its images broken.
* **1.3.0 the setting.** The radio group is submitted over HTTP with and
  without a nonce; the unsigned POST answers 403 and leaves the option alone,
  the signed one stores the value and the screen comes back with it selected.
  A value that is not one of the three modes falls back to `auto`.

## 4. PHPCS / WordPress Coding Standards

```
$ phpcs --standard=phpcs.xml.dist --parallel=1 --report=summary
.............. 14 / 14 (100%)

Time: 1.96 secs; Memory: 28MB
```

All 14 PHP files of the plugin are inspected, with no errors and no warnings. The ruleset (`phpcs.xml.dist`) is `WordPress` plus
`PHPCompatibilityWP` with `testVersion` set to `7.4-`, so PHP 7.4 through the
current release are all checked.

### Silenced sniffs, and why

Every `phpcs:ignore` in the source is listed here; there are no blanket
exclusions in the ruleset.

| Sniff | Where | Justification |
|---|---|---|
| `WordPress.WP.AlternativeFunctions.file_system_operations_*` | `class-sfir-logger.php`, `class-sfir-cache.php`, `class-sfir-endpoint.php`, `uninstall.php` | `WP_Filesystem` is not initialised on front-end requests and can require credentials. The plugin only ever writes inside its own uploads sub-directory, after a `realpath()` containment check, and a failed write is degraded to a placeholder rather than an error. |
| `WordPress.PHP.NoSilencedErrors.Discouraged` | file and GD calls throughout | The whole plugin is built so that a broken image never breaks a page. Every silenced call has its return value checked immediately afterwards. |
| `WordPress.Security.NonceVerification.Recommended` | `class-sfir-endpoint.php` | The cache and placeholder routes are public, anonymous and cacheable, so a nonce is impossible. The cache route is protected by the HMAC in the file name, which is verified before any file is written. |
| `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` | `class-sfir-endpoint.php` | `REQUEST_URI` is not sanitised as a string; it is split on `/`, each segment decoded on its own and refused unless it survives traversal, null byte and separator checks. |
| `WordPress.Security.EscapeOutput.OutputNotEscaped` | `class-sfir-endpoint.php` (placeholder SVG), `class-sfir-admin.php` (form action) | The SVG is produced by `SFIR_Placeholder::render()`, which escapes every dynamic part; the form action is passed through `esc_url()` one line earlier. |
| `WordPress.Security.NonceVerification.Missing` | `class-sfir-admin.php`, `handle_language()` | The nonce and `manage_options` are both checked by `verify_request()` on the line above; the sniff cannot follow the call into that helper. The two fields it covers are sanitised and then validated against fixed lists. |
| `WordPress.Security.NonceVerification.Recommended` | `class-sfir-admin.php`, `get_current_tab()` | The tab name selects which part of a read-only screen to draw and changes nothing, so a nonce would serve no purpose. The value is refused unless it is one of the three known tabs. |
| `WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents` | `class-sfir-admin.php`, `get_markdown()` | Reads `docs/sf-image-resizer.md` from the plugin directory. It is a local file shipped with the plugin, not a remote request, and the path is a constant. |

## 5. Plugin Check (spec 11.3)

Plugin Check 2.0.0, run through WP-CLI against the installed plugin:

```
$ wp plugin check sf-image-resizer \
      --categories=general,plugin_repo,security,performance,accessibility \
      --include-experimental
Success: Checks complete. No errors found.
```

Two warnings were reported by the very first 1.0.0 run and both were fixed then; the 1.1.0 code base reports none:

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

The admin suite leaves the language of the screen where it found it, but it
does switch through all seven locales while it runs, and both integration
suites switch the generation mode. Start them from a clean slate:

```bash
wp eval 'delete_user_meta( 1, "sfir_admin_locale" ); delete_option( "sfir_generate_mode" );'
```

Both suites restore the shipped default when they finish, and `run.php` asserts
that it did.

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
9. **Directory mirroring is verbatim.** Cache directories reproduce the source
   tree exactly rather than sanitising each segment, because the request handler
   has to map a cache path back onto its source directory. Only the file name is
   reduced to `[A-Za-z0-9._-]`, as the specification requires.
10. **Browser caching after a source change.** Cache responses are sent with
    `Cache-Control: immutable`, and the URL of a given source and parameter set
    never changes, so a browser that already downloaded a copy keeps it until
    the year is up. Server side invalidation works: `sf_img()` deletes a copy
    older than its source and the next request regenerates it. Putting the
    source modification time into the hash would also bust the browser cache,
    at the cost of a URL that changes whenever the source is re-saved; the
    specification defines the hash over the path and parameters only, so that
    is what is implemented.
11. `SFIR_Cache::flush_runtime_cache()` exists to drop the per-request memo.
   Normal page loads never need it (each request is a fresh process); it is
   used by long-running CLI processes and by the test suite, which simulates
   many page renders inside one process.
12. **Translations are loaded by full path (1.2.0).** The screen may have to be
    drawn in a language other than the one WordPress determined, and
    `load_plugin_textdomain()` cannot override a domain that is already loaded.
    `SFIR_I18n::load_textdomain()` therefore calls
    `unload_textdomain( $domain, true )` — the `true` matters, the default marks
    the domain as not reloadable — and then `load_textdomain()` with the full
    path of the `.mo` file. This is done while the screen renders, which is
    after `init`, so the just-in-time loading notice of WordPress 6.7 cannot
    trigger. Only this text domain is affected; the rest of the admin keeps the
    site language.
13. **`sf_img_srcset()` labels each candidate with its real width (1.2.0).**
    Because images are never enlarged, a requested width larger than the source
    yields a file of the source width. The descriptor states the width the file
    actually has, and duplicates are dropped, so a 400-pixel source produces a
    single `400w` candidate rather than six identical ones. A source that cannot
    be processed at all yields an empty string, so the template emits no
    `srcset` attribute rather than a broken one.
14. **The language choice is stored per user, not per site.** It is a reading
    preference for one screen, so it lives in user meta (`sfir_admin_locale`)
    and is removed with the rest of the plugin's data on uninstall. Two
    administrators can read the same screen in different languages.
15. **Render-time generation is a setting, not a detection (1.3.0).** The
    plugin cannot tell from inside PHP whether a request for a missing cache
    file would have reached it, because such a request never arrives when it
    does not. "Automatic" therefore produces copies while rendering until the
    browser check has confirmed the request path, and only then stops. On a
    server where the path works, one visit to the settings screen is what
    flips it; until then the site is merely doing more work than it needs to,
    which is the safe direction to be wrong in.
16. **Level 1 of the configuration check cannot fire while rendering is
    eager.** The request handler is what records that confirmation, and in
    eager mode the file already exists so the handler never runs. That is why
    the browser probe of level 2 is built without going through `sf_img()`:
    it is the only remaining signal, so it must keep missing the cache
    deliberately. The suite asserts exactly that.
17. **The budget is per PHP request, not per page.** A page assembled from
    several requests, or a long-running WP-CLI process, gets a fresh budget
    each time. `SFIR_Cache::flush_runtime_cache()` resets it too, so a test
    that simulates many renders inside one process behaves like many requests.
