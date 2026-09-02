=== SF Image resizer ===
Contributors: saytformat
Tags: images, resize, thumbnails, webp, performance
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

On-demand image resizing, cropping and WebP/JPG conversion straight from your PHP templates, with automatic disk caching.

== Description ==

SF Image resizer is built for developers who create custom WordPress themes.
Instead of registering dozens of image sizes, you request exactly the size,
crop, quality and format you need, right where you need it:

`<img src="<?php echo esc_url( sf_img( $image, 'w=900&f=webp&q=75' ) ); ?>" />`

* On-demand generation: each size is created once — while the page renders, or
  on the first request for it — and then served as a static cached file. No
  cron, no queues, works on shared hosting and on servers that cannot be
  reconfigured.
* Sources: image URL, attachment ID, or an ACF image field.
* Parameters: max width/height (no upscaling), center crop, WebP or JPG
  output, quality, background color for transparent images.
* Helper functions for correct `width`/`height` attributes, a ready made
  responsive `srcset`, and full `<img>` tags.
* Safe by design: local files only, signed generation URLs, strict parameter
  validation, graceful SVG placeholders on errors — a broken image never
  breaks your site.
* Admin page with cache statistics, one-click cache/log cleanup, a visual
  configuration check and full documentation.
* Optionally stops WordPress shrinking uploads to 2560 pixels, so the plugin
  has the full size original to resize from.

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

While a copy does not exist yet it has to be created. By default the plugin
does that while the page renders, so the file is already there when the browser
asks for it; once the configuration check confirms that your web server passes
requests for missing files to WordPress, the plugin stops and lets that request
do the work instead. Either way, from the second request onwards the web server
answers it as a static file and PHP is no longer involved. The hash means only
the sizes your own templates ask for can ever be created.

Problems are recorded in
`/wp-content/uploads/SFimageResizer/logs/error.log`.

== Installation ==

1. Upload the `sf-image-resizer` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Call `sf_img()` and friends from your theme templates.
4. SF Image resizer in the main admin menu shows cache statistics, the upload
   settings, the configuration check, the log and the full documentation.

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
Then nothing is broken and nothing needs configuring. The configuration check
on Settings -> SF Image resizer -> Check and log tests both ways of producing a
copy and reports them separately. A server that supports only "generating while
the page is rendered" is perfectly normal; the check says so instead of
claiming a fault, and the default mode keeps producing your images that way.

If you would rather fix the server, the same tab offers the nginx location
block to add. Apache is covered by the .htaccess the plugin writes, and the
usual nginx configuration already ends in
`try_files $uri $uri/ /index.php?$args`.

= The check says one thing works and the other does not. Is that a problem? =
No. The two are alternatives, not requirements, and the plugin only needs one
of them. It is normal for nginx without the extra rule to support only the
first.

= Should I turn off the 2560 pixel upload limit? =
Turn it off if you serve retina or full width images through this plugin. It
can only resize down from the file WordPress kept, so a 4000 pixel photo that
was reduced to 2560 at upload time can never produce a sharp 2560 pixel copy,
let alone a larger one. The cost is disk space. Images already in the media
library are unaffected — WordPress discarded their full size when they were
uploaded, so re-upload the ones that matter.

= Which generation mode should I choose? =
Leave it on "Automatic" unless you have a reason not to: it uses whichever of
the other two works on your server. Choose "up front" to keep the web server
out of it entirely, or "when the browser asks" if the check confirms that works
here and you want the lightest possible page render.

= Does producing copies while rendering slow my site down? =
Only the first visit to a page that needs sizes nobody has requested yet, and
only until those files exist. The work each page render may do is bounded, so a
page full of new images cannot run PHP out of time; whatever is left over is
produced by the next visit. If a copy cannot be produced in time, the untouched
original is used for that one image rather than a broken one.

== Screenshots ==

1. The Cache tab: statistics, the cache directory and the maintenance buttons.
2. The Settings tab: whether WordPress may shrink uploads.
3. The Check and log tab: the visual configuration check and the error log.
4. The Documentation tab: the functions, the parameters, worked examples and
   the Markdown reference for an AI assistant.

== Changelog ==

= 1.5.0 =
* The plugin now has its own entry in the main admin menu instead of sitting
  under Settings. The old address keeps working, so no bookmark breaks.
* New Settings tab with a switch that stops WordPress shrinking uploads.
  On its own WordPress reduces anything over 2560 pixels at upload time, keeps
  the smaller file as the original and adds "-scaled" to its name; this plugin
  can only ever resize down from what it is given, so that ceiling was also the
  ceiling of what it could produce.
* Fixed the configuration check reporting a failure for "generating while the
  page is rendered" on every server, including ones where it plainly worked.
  The screen created the test copy and then deleted it again before the browser
  could load it, so that half of the check could only ever pass on a server
  whose request path already worked — exactly backwards.
* The check is now visual. Each half loads a real 50x50 image, produced by the
  mechanism it is testing, and shows it on the screen. A picture you can see is
  a check that passed. The images are produced from a 1280x1280 source that
  ships with the plugin.
* The check is settled by the image elements themselves rather than by a
  background request, so content blockers can no longer hide a working setup.
* Both halves now run on every visit to the tab, so what is shown is the state
  now rather than a stored memory of it.

= 1.4.0 =
* The configuration check now tests both ways of producing a copy, separately,
  and reports each one. Before, it only tested whether a request for a missing
  file reaches the plugin, so a server that cannot do that showed a permanent
  "not confirmed" even though its images were being produced perfectly well by
  the other mechanism. Each line now says plainly whether it works here.
* When only the render-time mechanism works, the screen says so and says it is
  not a fault — that is the case the first mode exists for.
* Rewrote the mode descriptions in plain terms: what each one actually does to
  your pages, rather than what it does internally.
* The nginx snippet moved under the modes, where it belongs: it is what makes
  the on-request mode possible, and it now explains why the ^~ prefix matters.
* Fixed the check probe reusing one file name. The requested width was clamped
  to the 5000 pixel ceiling, so every probe asked for the same file and a copy
  left over from an earlier check could have answered for a fresh one.

= 1.3.0 =
* New setting on the Check and log tab: when resized copies are produced.
  Some hosts cannot be configured to hand a missing cache file to WordPress —
  nginx without the fallback rule answers such a request with 404 and never
  reaches PHP, so the image never appeared. The plugin can now produce the
  copies while the page is rendered instead, which works on any server.
* Three modes. "Automatic", the default, produces copies while rendering until
  the configuration check confirms that requests for missing files reach the
  plugin, then stops. "Always" never relies on the web server at all. "Never"
  is the behaviour of earlier versions.
* Rendering is bounded by a budget, so a page full of new sizes cannot run PHP
  out of time. Anything left over is produced by the next visit.
* When a copy cannot be produced in time, the untouched original is served
  instead of a URL with no file behind it, so an image is never broken.
  sf_img_srcset() leaves such candidates out rather than mislabelling them.
* The request handler and the render-time path now share one implementation,
  so both take the same lock and write the same file.

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

= 1.5.0 =
Fixes the configuration check, which reported a failure for render-time
generation even where it worked. The check is now visual: each half shows the
test image it produced. Adds a top-level admin menu entry and a switch that
stops WordPress shrinking uploads to 2560 pixels.

= 1.4.0 =
The configuration check now tests both ways of producing a copy and reports
each separately, so a server that only supports one no longer looks broken.
Clearer wording for the generation modes.

= 1.3.0 =
Adds the option to produce resized copies while the page is rendered, for hosts
where the web server cannot pass a missing cache file to WordPress. Recommended
if your images are not appearing.

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
