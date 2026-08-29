# SF Image resizer — reference for generating `<img>` markup

You are helping a WordPress theme developer output images through the
**SF Image resizer** plugin. This document is the complete contract. Follow it
literally; do not invent parameters or functions that are not listed here.

## What the plugin does

It creates resized, cropped and converted copies of images on demand and caches
them on disk. You ask for a size in a PHP template, the plugin returns the URL
of the cached file, and the file is generated the first time a browser requests
that URL. Every call returns the same plain URL, with no query string:

```
/wp-content/uploads/SFimageResizer/cache_images/2026/08/photo-800x0-c0-q75-a3f9c1.webp
```

## Template functions

All four are safe with any input. They never throw and never break a page: on
error they return the URL or the size of an SVG placeholder and write one line
to the plugin log.

| Function | Returns |
|---|---|
| `sf_img( $source, $params = '' )` | URL of the resized copy. Output with `esc_url()`. |
| `sf_img_width( $source, $params = '' )` | Width the copy will have, computed without generating it. |
| `sf_img_height( $source, $params = '' )` | Matching height. |
| `sf_img_srcset( $source, $params = '', $widths = array() )` | A complete `srcset` value. Output with `esc_attr()`. |
| `sf_img_tag( $source, $params = '', $attrs = array() )` | A complete, escaped `<img>` tag. |

### `$source`

Accepts any of:

* a URL belonging to this site, for example `$image['url']`;
* an attachment ID, for example `get_post_thumbnail_id()`;
* an ACF image array, passed as is: `sf_img( $image, 'w=800' )`.

Anything else — a URL on another host, a `data:` URI, a missing file, an SVG —
produces a placeholder, never a fatal error.

### `$params`

A query string. Unknown parameters are ignored.

| Parameter | Values | Default | Meaning |
|---|---|---|---|
| `w` | 0–5000 | `0` | Maximum width in pixels. `0` means unconstrained. |
| `h` | 0–5000 | `0` | Maximum height in pixels. `0` means unconstrained. |
| `f` | `webp`, `jpg` | `webp` | Output format. |
| `q` | 1–95 | `75` | Output quality. |
| `bg` | 3 or 6 hex digits, no `#` | transparent, `FFFFFF` for JPG | Fills transparent areas. |
| `crop` | `0`, `1` | `0` | Crop to exactly `w`×`h`, centred. |

Sizing rules:

* `w` alone scales the height proportionally, and vice versa.
* Both sides with `crop=0` fits the image inside the box.
* Both sides with `crop=1` fills the box and crops the excess, centred.
* `crop=1` is ignored unless both `w` and `h` are set.
* **Images are never enlarged.** If the source is smaller than the request, the
  result keeps the source size. This matters for `srcset`: see below.

## Rule 1: never take `width`/`height` from the source

The most common mistake is leaving the original dimensions in the attributes
while `src` points at a resized copy. The browser then renders the wrong aspect
ratio and the page shifts while loading.

The attributes must describe the file in `src`:

```php
<img src="<?php echo esc_url( sf_img( $image, 'w=900' ) ); ?>"
     width="<?php echo (int) sf_img_width( $image, 'w=900' ); ?>"
     height="<?php echo (int) sf_img_height( $image, 'w=900' ); ?>"
     alt="" loading="lazy" decoding="async">
```

Pass the **same** parameters to all three calls. Only `w`, `h` and `crop`
change the geometry; `q` and `f` do not, but keeping the string identical is
simpler and costs nothing — the result is memoised per request.

## Rule 2: use `sf_img_srcset()` for responsive images

`sf_img_srcset()` returns a full `srcset` value for the default width ladder
**320, 640, 960, 1280, 1920, 2560**. It is the right answer for almost every
responsive image.

```php
<img src="<?php echo esc_url( $image['url'] ); ?>"
     srcset="<?php echo esc_attr( sf_img_srcset( $image ) ); ?>"
     sizes="auto"
     width="<?php echo (int) sf_img_width( $image, '' ); ?>"
     height="<?php echo (int) sf_img_height( $image, '' ); ?>"
     alt="<?php echo esc_attr( $image['alt'] ); ?>"
     loading="lazy" decoding="async">
```

What the function does for you, and why writing the ladder by hand is a trap:

* It skips widths the source is too small for, so a 1600 pixel original yields
  `320w, 640w, 960w, 1280w, 1600w` instead of claiming three files are 1280,
  1920 and 2560 pixels wide. A hand written ladder tells the browser that a
  1600 pixel file is 2560 pixels wide, the browser picks it for a 2560 slot,
  and the image renders blurred.
* The descriptor of every candidate is the width the file really has.
* Candidates are ordered from small to large.
* It returns an empty string when the source is unusable, so guard the
  attribute if that is possible in your template.

Optional arguments:

```php
sf_img_srcset( $image, 'q=80&f=jpg' )              // other quality or format
sf_img_srcset( $image, '', array( 400, 800 ) )      // a ladder of your own
```

`w`, `h` and `crop` in `$params` are ignored: the ladder defines the widths, and
candidates are never cropped.

## Rule 3: `sizes`

`srcset` with `w` descriptors needs `sizes`, otherwise the browser assumes
`100vw` and downloads more than it needs.

* `sizes="auto"` lets the browser measure the laid out image itself. It is the
  easiest correct answer, **but it only works together with
  `loading="lazy"`** — that is a requirement of the specification, not an
  optimisation. Browsers without support treat it as `100vw`, which is safe.
* For an image that must not be lazy, typically the one in the first screen,
  write `sizes` explicitly, for example `sizes="100vw"` for a full width hero
  or `sizes="(max-width: 782px) 100vw, 33vw"` for a three column card.

## Rule 4: what goes into `src`

`src` is only used by browsers without `srcset` support and by crawlers and
preview generators. Two reasonable choices:

* the original, `$image['url']` — simplest, and the safest: it exists whatever
  happens, so the image always shows even on the very first visit;
* a middle size, `sf_img( $image, 'w=960' )` — then `width`/`height` must use
  the same `w=960`, and both must come from `sf_img_width()`/`sf_img_height()`
  rather than from constants, because that call can fall back to the original.

## Complete examples

### An ACF image field, responsive

```php
<?php $image = get_field( 'hero' ); ?>
<?php if ( $image ) : ?>
	<img src="<?php echo esc_url( $image['url'] ); ?>"
	     srcset="<?php echo esc_attr( sf_img_srcset( $image ) ); ?>"
	     sizes="auto"
	     width="<?php echo (int) sf_img_width( $image, '' ); ?>"
	     height="<?php echo (int) sf_img_height( $image, '' ); ?>"
	     alt="<?php echo esc_attr( $image['alt'] ); ?>"
	     class="hero__image" loading="lazy" decoding="async">
<?php endif; ?>
```

### A featured image, cropped to a fixed box

```php
<?php echo sf_img_tag( get_post_thumbnail_id(), 'w=600&h=400&crop=1', array(
	'alt'   => get_the_title(),
	'class' => 'card__image',
) ); ?>
```

### An image above the fold

```php
<img src="<?php echo esc_url( sf_img( $image, 'w=1280' ) ); ?>"
     srcset="<?php echo esc_attr( sf_img_srcset( $image ) ); ?>"
     sizes="100vw"
     width="<?php echo (int) sf_img_width( $image, 'w=1280' ); ?>"
     height="<?php echo (int) sf_img_height( $image, 'w=1280' ); ?>"
     alt="" fetchpriority="high" decoding="async">
```

Note the absence of `loading="lazy"` and the explicit `sizes`.

### A gallery loop

```php
<?php foreach ( get_field( 'gallery' ) as $item ) : ?>
	<img src="<?php echo esc_url( $item['url'] ); ?>"
	     srcset="<?php echo esc_attr( sf_img_srcset( $item ) ); ?>"
	     sizes="auto"
	     width="<?php echo (int) sf_img_width( $item, '' ); ?>"
	     height="<?php echo (int) sf_img_height( $item, '' ); ?>"
	     alt="<?php echo esc_attr( $item['alt'] ); ?>"
	     loading="lazy" decoding="async">
<?php endforeach; ?>
```

## Escaping

* `src` → `esc_url()`
* `srcset`, `alt`, `class`, `sizes` → `esc_attr()`
* `width`, `height` → `(int)`

`sf_img_tag()` does all of this itself.

## Behaviour worth knowing

* **First render.** A copy that does not exist yet is created either while the
  template renders or by the browser's own request for it, depending on the
  mode set on *Settings → SF Image resizer → Check and log*. Both are normal;
  which ones a server supports is reported by the check on that tab. Either way your
  markup is identical: you never choose, and you never wait for anything in
  your template code. A page with many new sizes may be slower on its first
  visit, or may need a second visit before every size exists. Both are normal.
* **The fallback.** If a copy has to be produced during the render and cannot
  be — the page has already used its generation budget, or GD refused the file
  — `sf_img()` returns the URL of the **untouched original** and
  `sf_img_width()`/`sf_img_height()` report that original's dimensions. The
  image is correct, just larger than asked for, and the next visit produces the
  proper copy. `sf_img_srcset()` leaves such candidates out rather than
  describing them with a width they do not have, so a `srcset` can come back
  shorter than the ladder, or empty, on a first visit. This is why `src` should
  carry the original and why the markup must not assume a fixed number of
  candidates.
* **Formats.** Output is WebP by default. If the server's GD has no WebP
  support the plugin produces JPG instead and the cached file gets a `.jpg`
  extension; nothing in your markup changes.
* **Animated GIF.** Only the first frame is used.
* **SVG.** Not accepted as a source.
* **Changing a source file.** The plugin notices the new modification time and
  regenerates the copy. The URL stays the same, so a browser that already
  cached the old copy may keep showing it until its cache expires.
* **Errors.** A broken source yields an SVG placeholder carrying an error code
  (`E01` missing, `E02` unsupported format, `E03` external URL, `E04` bad
  signature, `E05` GD failure, `E06` cache not writable, `E07` image too large)
  and one line in
  `/wp-content/uploads/SFimageResizer/logs/error.log`. The rest of the page is
  unaffected.

## Do not

* Do not put the original `width`/`height` next to a resized `src`.
* Do not build a `srcset` by hand with a fixed ladder; use `sf_img_srcset()`.
* Do not use `sizes="auto"` without `loading="lazy"`.
* Do not pass URLs from other domains; only files belonging to this site work.
* Do not call the functions with a source you have not checked for emptiness if
  your template can render without an image — they will return a placeholder,
  which is rarely what a layout wants in that case.
