# SFimageResizer

On-demand image resizing for WordPress theme developers. Request exactly the
size, crop, quality and format you need directly from a PHP template; the
plugin generates the copy on first request, caches it on disk and serves a
plain static URL from then on.

```php
<img src="<?php echo esc_url( sf_img( $image, 'w=900&crop=0&q=75&f=webp' ) ); ?>"
     width="<?php echo (int) sf_img_width( $image, 'w=900' ); ?>"
     height="<?php echo (int) sf_img_height( $image, 'w=900' ); ?>"
     alt="" loading="lazy" />
```

Or hand the browser every size at once and let it pick:

```php
<img src="<?php echo esc_url( $image['url'] ); ?>"
     srcset="<?php echo esc_attr( sf_img_srcset( $image ) ); ?>"
     sizes="auto" alt="" loading="lazy" decoding="async" />
```

* **Download:** the installable zip is attached to each
  [GitHub Release](../../releases/latest); it is deliberately not committed to
  the repository.
* **Plugin:** `sf-image-resizer/` — this is the only directory that ships.
* **User documentation:** `sf-image-resizer/readme.txt`, and the built-in
  documentation on *Settings → SF Image resizer → Documentation*. That tab also
  offers `sf-image-resizer/docs/sf-image-resizer.md`, a reference written for a
  language model: paste it into Claude or ChatGPT together with your template
  and the assistant has everything it needs to write correct markup.
* **Tests:** `tests/` — see `TEST-REPORT.md`.
* **Release process:** `BUILD.md`.

## Repository layout

```
.
├── sf-image-resizer/        the plugin (the release artifact)
│   ├── sf-image-resizer.php
│   ├── uninstall.php
│   ├── readme.txt
│   ├── includes/
│   ├── admin/
│   ├── docs/                the Markdown reference the screen hands out
│   └── languages/           .pot, and .po/.mo for seven locales
├── tests/
│   ├── bootstrap.php        stubs for the WordPress-free unit tests
│   ├── unit/                PHPUnit tests for the pure logic
│   ├── integration/         suites driven through WP-CLI and HTTP
│   └── run-tests.sh         runs everything
├── .github/workflows/     CI on every push, release on every vX.Y.Z tag
├── composer.json          dev tooling only; the plugin has no dependencies
├── phpunit.xml.dist
├── phpcs.xml.dist
├── CLAUDE.md              conventions every change has to follow
├── TEST-REPORT.md
└── BUILD.md
```

## Requirements

* WordPress 7.0 or newer
* PHP 7.4 or newer
* The GD extension. WebP output is used when the build supports it, otherwise
  the plugin falls back to JPG automatically.

## Quick start

1. Copy or symlink `sf-image-resizer/` into `wp-content/plugins/`.
2. Activate it. Activation creates
   `wp-content/uploads/SFimageResizer/{cache_images,logs}/`, a signing secret
   and the rewrite rule for the generation endpoint.
3. Call `sf_img()`, `sf_img_srcset()`, `sf_img_width()`, `sf_img_height()` or
   `sf_img_tag()` from your templates.

The settings screen is on *Settings → SF Image resizer*, reachable from the
**Settings** link in the plugin row as well. It has three tabs — Cache, Check
and log, Documentation — and follows the site language, with a picker for
English, Russian, Spanish, German, French, Italian, Brazilian Portuguese and
Simplified Chinese. The choice is remembered per administrator and affects this
screen only.

## Running the tests

See section 6 of `TEST-REPORT.md`. In short:

```bash
composer install
composer run test                                    # pure logic, no WordPress
composer run lint                                    # WordPress Coding Standards
SFIR_WP_DIR=... SFIR_BASE_URL=... bash tests/run-tests.sh   # everything
```

CI runs the same checks on PHP 7.4 and 8.4, plus the full WordPress
integration suite and Plugin Check.

## Releasing

Bump the version and push, then either run **Actions → Release → Run workflow**
(pick the branch, type the version — the tag is created for you) or push a
`vX.Y.Z` tag by hand. Either way the workflow builds the archive, verifies it
and publishes the GitHub Release. See `BUILD.md`.

## License

GPLv2 or later.
