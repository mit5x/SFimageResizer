=== SFimageResizer ===
Contributors: saytformat
Tags: images, resize, thumbnails, webp, performance
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

On-demand image resizing, cropping and WebP/JPG conversion straight from your PHP templates, with automatic disk caching.

== Description ==

SFimageResizer is built for developers who create custom WordPress themes.
Instead of registering dozens of image sizes, you request exactly the size,
crop, quality and format you need, right where you need it:

`<img src="<?php echo esc_url( sf_img( $image, 'w=900&f=webp&q=75' ) ); ?>" />`

* Lazy, on-demand generation: each size is created on first request and then
  served as a static cached file — no cron, no queues, works on shared hosting.
* Sources: image URL, attachment ID, or an ACF image field.
* Parameters: max width/height (no upscaling), center crop, WebP or JPG
  output, quality, background color for transparent images.
* Helper functions for correct `width`/`height` attributes and full `<img>`
  tags with `srcset` support.
* Safe by design: local files only, signed generation URLs, strict parameter
  validation, graceful SVG placeholders on errors — a broken image never
  breaks your site.
* Admin page with cache statistics, one-click cache/log cleanup and full
  documentation.

Requires the GD extension (bundled with virtually every WordPress hosting).
If your PHP build lacks WebP support, the plugin automatically falls back
to JPG output.

= Functions =

* `sf_img( $source, $params = '' )` — URL of the resized copy. Output it with `esc_url()`.
* `sf_img_width( $source, $params = '' )` — the width the copy will have, computed without generating it.
* `sf_img_height( $source, $params = '' )` — the matching height.
* `sf_img_tag( $source, $params = '', $attrs = array() )` — a complete, escaped `<img>` tag.

= Parameters =

`w` (0–5000, default 0), `h` (0–5000, default 0), `f` (`webp` or `jpg`,
default `webp`), `q` (1–95, default 75), `bg` (three or six hex digits without
`#`), `crop` (`0` or `1`, default `0`). Images are never enlarged. `crop=1` is
ignored unless both `w` and `h` are set.

= Where files are stored, and what the URLs look like =

`sf_img()` always returns the plain URL of the cached file, from the very first
render:

`/wp-content/uploads/SFimageResizer/cache_images/2026/08/photo-800x0-c0-q75-a3f9c1.webp`

Generated copies mirror the directory tree of their sources under
`/wp-content/uploads/SFimageResizer/cache_images/`. The file name is
`{name}-{w}x{h}-c{crop}-q{q}[-bg{BG}]-{hash}.{format}`; the `bg` part appears
only when a background colour was requested, and the hash is six hexadecimal
characters of an HMAC over the source path and the parameters.

While a copy does not exist yet, the request falls through to WordPress, which
generates the file and returns it. Afterwards the web server answers it as a
static file and PHP is no longer involved. The hash means only the sizes your
own templates ask for can ever be created.

Problems are recorded in
`/wp-content/uploads/SFimageResizer/logs/error.log`.

== Installation ==

1. Upload the `sf-image-resizer` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Call `sf_img()` and friends from your theme templates.
4. Settings → SFimageResizer shows cache statistics, the log and the full
   documentation.

== Frequently Asked Questions ==

= Does it work with page builders? =
The plugin targets developers writing PHP templates. It does not integrate
with visual builders.

= Are animated GIFs supported? =
The first frame is used; animation is not preserved in resized copies.

= Can I resize an SVG? =
No. SVG files are rejected on input because they can carry scripts.

= Can I resize an image hosted on another domain? =
No. Only files belonging to this site are processed.

= How do I protect the log file on nginx? =
`.htaccess` files are ignored by nginx. Add a rule to your server
configuration that denies direct access to
`/wp-content/uploads/SFimageResizer/logs/`, for example:

`location ~* /wp-content/uploads/SFimageResizer/logs/ { deny all; }`

= Do image URLs change between page loads? =
No. The same plain URL is returned every time, whether the file exists yet or
not.

= What if my server does not pass missing files to WordPress? =
Settings -> SFimageResizer runs a configuration check and tells you. Almost
every host works out of the box: Apache is covered by the .htaccess the plugin
writes, and the usual nginx configuration already ends in
`try_files $uri $uri/ /index.php?$args`. If the check reports a problem, it
shows the exact nginx location block to add.

== Screenshots ==

1. The plugin screen: cache statistics, maintenance buttons and documentation.

== Changelog ==

= 1.1.0 =
* `sf_img()` now always returns the plain URL of the cached file, from the
  first call onwards. URLs no longer change between renders and carry no query
  string.
* The signature moved from the query string into the file name, as a six
  character suffix.
* Removed the `sfir-generate` endpoint and its rewrite rule.
* Added a configuration check on the plugin screen that verifies missing cache
  files reach WordPress, with a ready-made nginx snippet when they do not.
* The cache `.htaccess` now also routes missing files to WordPress on Apache.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
Image URLs are now plain cache URLs from the first render. Existing cached
files use the old naming scheme and become unused; clear the cache on the
plugin screen to reclaim the space.

= 1.0.0 =
Initial release.
