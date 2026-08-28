=== SF Image resizer ===
Contributors: saytformat
Tags: images, resize, thumbnails, webp, performance
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

On-demand image resizing, cropping and WebP/JPG conversion straight from your PHP templates, with automatic disk caching.

== Description ==

SF Image resizer is built for developers who create custom WordPress themes.
Instead of registering dozens of image sizes, you request exactly the size,
crop, quality and format you need, right where you need it:

`<img src="<?php echo esc_url( sf_img( $image, 'w=900&f=webp&q=75' ) ); ?>" />`

* Lazy, on-demand generation: each size is created on first request and then
  served as a static cached file — no cron, no queues, works on shared hosting.
* Sources: image URL, attachment ID, or an ACF image field.
* Parameters: max width/height (no upscaling), center crop, WebP or JPG
  output, quality, background color for transparent images.
* Helper functions for correct `width`/`height` attributes, a ready made
  responsive `srcset`, and full `<img>` tags.
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
* `sf_img_srcset( $source, $params = '', $widths = array() )` — a complete `srcset` for 320, 640, 960, 1280, 1920 and 2560 pixels. Output it with `esc_attr()`.
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

= How do I output a responsive image? =
Use `sf_img_srcset()`. It builds the whole `srcset` at 320, 640, 960, 1280,
1920 and 2560 pixels, so nothing has to be added to your theme's
`functions.php`. Pair it with `sizes="auto"` and `loading="lazy"`. The
Documentation tab on the plugin screen shows the full example.

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
Almost every host works out of the box: Apache is covered by the .htaccess the
plugin writes, and the usual nginx configuration already ends in
`try_files $uri $uri/ /index.php?$args`.

Settings -> SFimageResizer shows a configuration check. It reports success as
soon as the plugin has really generated and served an image, and on a fresh
installation it asks your own browser to load a test URL. Until one of those
happens it says so plainly rather than claiming something is broken, because a
site cannot reliably test itself: hosting bot protection answers server side
requests with a challenge page even when everything is fine.

The most reliable test is the obvious one: open the URL of a size that has not
been generated yet in your browser. If the image appears, it works. If it does
not, the check offers the nginx location block to add.

== Screenshots ==

1. The Cache tab: statistics, the cache directory and the maintenance buttons.
2. The Check and log tab: the configuration check and the error log.
3. The Documentation tab: the functions, the parameters, worked examples and
   the Markdown reference for an AI assistant.

== Changelog ==

= 1.2.0 =
* Renamed the plugin to "SF Image resizer" so it reads better in the plugin list.
* New template function `sf_img_srcset()`: a complete responsive `srcset` at
  320, 640, 960, 1280, 1920 and 2560 pixels, skipping the widths the source is
  too small for and labelling every candidate with the width it really has.
* The plugin screen is now split into Cache, Check and log, and Documentation.
* The screen is translated into Russian, Spanish, German, French, Italian,
  Brazilian Portuguese and Simplified Chinese. It follows the WordPress
  language by default, and a picker on the screen overrides it per user.
* The Documentation tab carries a Markdown reference written for AI assistants,
  with copy and download buttons.
* Added a Settings link on the plugins list.

= 1.1.1 =
* The configuration check no longer calls the site from the server. That
  loopback request was answered with a challenge page by hosts that run bot
  protection, which made the check report a problem on sites where everything
  worked.
* The check now trusts, in order: an image the plugin has really generated and
  served, then a test request made by the administrator's own browser, and
  otherwise says that it could not confirm anything yet instead of warning.
* The nginx snippet and the Apache note moved into a collapsed block, shown
  only while nothing has been confirmed.

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

= 1.2.0 =
Adds sf_img_srcset() for responsive images, a tabbed and translated settings
screen, and a Markdown reference for AI assistants.

= 1.1.1 =
Fixes a false warning in the configuration check on hosts with bot protection.

= 1.1.0 =
Image URLs are now plain cache URLs from the first render. Existing cached
files use the old naming scheme and become unused; clear the cache on the
plugin screen to reclaim the space.

= 1.0.0 =
Initial release.
