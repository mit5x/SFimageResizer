<?php
/**
 * Integration test suite (spec 11.2, cases 1 to 11).
 *
 * Run through WP-CLI:
 *   wp eval-file tests/integration/run.php
 *
 * A web server must be serving the same WordPress installation; its address is
 * taken from home_url() unless SFIR_BASE_URL is set in the environment.
 *
 * @package SFimageResizer
 */

require_once __DIR__ . '/lib.php';

$base_url = getenv( 'SFIR_BASE_URL' );
$base_url = $base_url ? rtrim( $base_url, '/' ) : rtrim( home_url(), '/' );

// A constant, not a global: WP-CLI evaluates this file inside a function scope.
define( 'SFIR_TEST_BASE_URL', $base_url );

$runner   = 'SFIR_TestRunner';
$fixtures = sfir_build_fixtures();

SFIR_Cache::clear();
sfir_reset_log();

/* ---------------------------------------------------------------------------
 * 1. Pretty URLs, generation and caching.
 * ------------------------------------------------------------------------ */

SFIR_TestRunner::group( '11.2.1 pretty cache URLs' );

$jpeg_url = sfir_path_to_url( $fixtures['jpeg'] );

$first = sf_img( $jpeg_url, 'w=900&q=75&f=webp' );

SFIR_TestRunner::check(
	false === strpos( $first, '?' ),
	'the first call returns a URL without any query string',
	$first
);

SFIR_TestRunner::check(
	(bool) preg_match( '#/cache_images/sfir-fixtures/2026/08/landscape-900x0-c0-q75-[a-f0-9]{6}\.webp$#', $first ),
	'the URL follows the documented cache file naming scheme',
	$first
);

$cache_file = sfir_url_to_path( $first );

SFIR_TestRunner::check( ! file_exists( $cache_file ), 'the file does not exist yet' );

$response = sfir_fetch( $first );

SFIR_TestRunner::equals( 200, $response['status'], 'requesting the missing file answers 200' );
SFIR_TestRunner::equals( 'generated', isset( $response['headers']['x-sfir'] ) ? $response['headers']['x-sfir'] : '', 'the plugin handled the request' );
SFIR_TestRunner::equals( 'image/webp', isset( $response['headers']['content-type'] ) ? $response['headers']['content-type'] : '', 'the answer is a WebP image' );
SFIR_TestRunner::equals(
	'public, max-age=31536000, immutable',
	isset( $response['headers']['cache-control'] ) ? $response['headers']['cache-control'] : '',
	'the answer carries an immutable Cache-Control'
);
SFIR_TestRunner::equals(
	'nosniff',
	isset( $response['headers']['x-content-type-options'] ) ? $response['headers']['x-content-type-options'] : '',
	'the answer carries X-Content-Type-Options: nosniff'
);
SFIR_TestRunner::check( isset( $response['headers']['last-modified'] ), 'the answer carries Last-Modified' );
SFIR_TestRunner::equals(
	(string) strlen( $response['body'] ),
	isset( $response['headers']['content-length'] ) ? $response['headers']['content-length'] : '',
	'Content-Length matches the body'
);

$info = sfir_image_info( $response['body'] );
SFIR_TestRunner::check( is_array( $info ) && 900 === $info['width'] && 450 === $info['height'], 'the answer is a 900x450 image', wp_json_encode( $info ) );

clearstatcache();
SFIR_TestRunner::check( file_exists( $cache_file ), 'the cache file now exists, mirroring the source tree', $cache_file );

$second = sf_img( $jpeg_url, 'w=900&q=75&f=webp' );

SFIR_TestRunner::equals( $first, $second, 'the second call returns byte-for-byte the same URL' );

$again = sfir_fetch( $second );

SFIR_TestRunner::equals( 200, $again['status'], 'the second HTTP request also answers 200' );
SFIR_TestRunner::check(
	! isset( $again['headers']['x-sfir'] ),
	'the second HTTP request is served statically, without touching the plugin',
	isset( $again['headers']['x-sfir'] ) ? $again['headers']['x-sfir'] : ''
);

/* ---------------------------------------------------------------------------
 * 1b. A tampered or unsigned name is refused.
 * ------------------------------------------------------------------------ */

SFIR_TestRunner::group( '11.2.1 signature in the file name' );

sfir_reset_log();

$signed    = sf_img( $jpeg_url, 'w=412&f=jpg' );
$corrupted = sfir_corrupt_hash( $signed );

SFIR_TestRunner::check( $signed !== $corrupted, 'the test really changed one character of the hash' );

$bad = sfir_http( sfir_test_url( $corrupted ) );

SFIR_TestRunner::equals( 403, $bad['status'], 'a tampered hash is answered with 403' );
SFIR_TestRunner::check( false !== strpos( $bad['body'], 'E04' ), 'a tampered hash yields an E04 placeholder' );
SFIR_TestRunner::check( ! file_exists( sfir_url_to_path( $corrupted ) ), 'no file was created for the tampered name' );
SFIR_TestRunner::check( false !== strpos( sfir_read_log(), '[E04]' ), 'the refusal is recorded in the log' );

$traversal = str_replace( '/sfir-fixtures/2026/08/', '/sfir-fixtures/2026/08/..%2f..%2f', $signed );
$refused   = sfir_http( sfir_test_url( $traversal ) );

SFIR_TestRunner::check(
	in_array( $refused['status'], array( 403, 404 ), true ),
	'path traversal inside the cache tree is refused',
	(string) $refused['status']
);
SFIR_TestRunner::equals(
	0,
	count( (array) glob( SFIR_Cache::get_cache_dir() . '/sfir-fixtures/landscape-412*' ) ),
	'traversal created no file outside the mirrored tree'
);

SFIR_Cache::flush_runtime_cache();

/* ---------------------------------------------------------------------------
 * 1c. The retired sfir-generate endpoint is gone.
 * ------------------------------------------------------------------------ */

SFIR_TestRunner::group( '11.2.1 retired endpoint' );

$retired = sfir_http( $base_url . '/sfir-generate/?sfir_generate=1&src=u%3Asfir-fixtures%2F2026%2F08%2Flandscape.jpg&p=w%3D100' );

SFIR_TestRunner::equals( 404, $retired['status'], 'the old sfir-generate route is no longer handled' );
SFIR_TestRunner::check(
	false === strpos( (string) ( isset( $retired['headers']['content-type'] ) ? $retired['headers']['content-type'] : '' ), 'image/' ),
	'the old route does not answer with an image'
);

$rules = get_option( 'rewrite_rules' );
SFIR_TestRunner::check(
	! is_array( $rules ) || ! isset( $rules['^sfir-generate/?$'] ),
	'the old rewrite rule is no longer registered'
);

/* ---------------------------------------------------------------------------
 * 1d. The URL does not depend on the permalink structure.
 * ------------------------------------------------------------------------ */

SFIR_TestRunner::group( '11.2.1 permalinks' );

$saved_structure = get_option( 'permalink_structure' );

$with_permalinks = sf_img( $jpeg_url, 'w=321&f=jpg' );

update_option( 'permalink_structure', '' );
SFIR_Cache::flush_runtime_cache();

$without_permalinks = sf_img( $jpeg_url, 'w=321&f=jpg' );

SFIR_TestRunner::equals( $with_permalinks, $without_permalinks, 'the cache URL is the same with and without pretty permalinks' );

$plain_response = sfir_fetch( $without_permalinks );

SFIR_TestRunner::equals( 200, $plain_response['status'], 'generation works with permalinks disabled' );

$plain_info = sfir_image_info( $plain_response['body'] );

SFIR_TestRunner::check(
	is_array( $plain_info ) && 321 === $plain_info['width'] && 'image/jpeg' === $plain_info['mime'],
	'the produced image is correct with permalinks disabled',
	wp_json_encode( $plain_info )
);

update_option( 'permalink_structure', $saved_structure );
SFIR_Cache::flush_runtime_cache();

/* ---------------------------------------------------------------------------
 * 2. Every sizing rule, on real files.
 * ------------------------------------------------------------------------ */

SFIR_TestRunner::group( '11.2.2 sizing rules on real files' );

/**
 * Generates one variant and returns the real dimensions of the produced file.
 *
 * @param string $source Source URL or ID.
 * @param string $params Parameter string.
 * @return array|false
 */
function sfir_generate_and_measure( $source, $params ) {
	$url      = sf_img( $source, $params );
	$response = sfir_fetch( $url );

	if ( 200 !== $response['status'] ) {
		return false;
	}

	return sfir_image_info( $response['body'] );
}

$cases = array(
	array( $jpeg_url, 'w=900&h=0', 900, 450, 'w=900,h=0 on 2000x1000' ),
	array( $jpeg_url, 'w=0&h=450', 900, 450, 'w=0,h=450 on 2000x1000' ),
	array( $jpeg_url, 'w=900&h=900&crop=0', 900, 450, 'w=900,h=900,crop=0 on 2000x1000' ),
	array( $jpeg_url, 'w=900&h=900&crop=1', 900, 900, 'w=900,h=900,crop=1 on 2000x1000' ),
	array( sfir_path_to_url( $fixtures['small'] ), 'w=900', 400, 300, 'no upscaling on 400x300' ),
	array( sfir_path_to_url( $fixtures['small'] ), 'w=900&h=900&crop=1', 300, 300, 'crop without upscaling on 400x300' ),
	array( $jpeg_url, 'w=900&h=0&crop=1', 900, 450, 'crop=1 with h=0 behaves like crop=0' ),
	array( $jpeg_url, '', 2000, 1000, 'no size keeps the original dimensions' ),
);

foreach ( $cases as $case ) {
	list( $source, $params, $width, $height, $label ) = $case;

	$info = sfir_generate_and_measure( $source, $params );

	SFIR_TestRunner::check(
		is_array( $info ) && $width === $info['width'] && $height === $info['height'],
		$label . ' produces ' . $width . 'x' . $height,
		wp_json_encode( $info )
	);
}

/* ---------------------------------------------------------------------------
 * 3. Formats, transparency, background and quality.
 * ------------------------------------------------------------------------ */

SFIR_TestRunner::group( '11.2.3 formats, transparency and quality' );

$png_url = sfir_path_to_url( $fixtures['png'] );

$png_to_webp = sfir_fetch( sf_img( $png_url, 'w=400&f=webp' ) );
$corner      = sfir_pixel( $png_to_webp['body'], 2, 2 );

SFIR_TestRunner::equals( 'image/webp', sfir_image_info( $png_to_webp['body'] )['mime'], 'png converts to webp' );
SFIR_TestRunner::check(
	is_array( $corner ) && $corner[3] > 100,
	'png to webp keeps the transparent corner transparent',
	wp_json_encode( $corner )
);

$png_to_jpg = sfir_fetch( sf_img( $png_url, 'w=400&f=jpg' ) );
$corner     = sfir_pixel( $png_to_jpg['body'], 2, 2 );

SFIR_TestRunner::equals( 'image/jpeg', sfir_image_info( $png_to_jpg['body'] )['mime'], 'png converts to jpg' );
SFIR_TestRunner::check(
	is_array( $corner ) && $corner[0] > 245 && $corner[1] > 245 && $corner[2] > 245,
	'png to jpg fills transparency with FFFFFF',
	wp_json_encode( $corner )
);

$png_red = sfir_fetch( sf_img( $png_url, 'w=400&f=webp&bg=FF0000' ) );
$corner  = sfir_pixel( $png_red['body'], 2, 2 );

SFIR_TestRunner::check(
	is_array( $corner ) && $corner[0] > 240 && $corner[1] < 15 && $corner[2] < 15 && $corner[3] < 20,
	'png to webp with bg=FF0000 fills transparency with red',
	wp_json_encode( $corner )
);

SFIR_TestRunner::equals(
	1,
	count( (array) glob( SFIR_Cache::get_cache_dir() . '/sfir-fixtures/2026/08/transparent-400x0-c0-q75-bgFF0000-*.webp' ) ),
	'an explicit background becomes part of the cache file name'
);

$gif_url = sfir_path_to_url( $fixtures['gif'] );
$gif_out = sfir_fetch( sf_img( $gif_url, 'w=150&f=jpg' ) );
$pixel   = sfir_pixel( $gif_out['body'], 75, 50 );

SFIR_TestRunner::check(
	is_array( $pixel ) && $pixel[0] > 180 && $pixel[1] < 70,
	'animated gif is reduced to its first (red) frame',
	wp_json_encode( $pixel )
);

if ( isset( $fixtures['webp'] ) ) {
	$webp_in = sfir_generate_and_measure( sfir_path_to_url( $fixtures['webp'] ), 'w=600&f=jpg' );
	SFIR_TestRunner::check(
		is_array( $webp_in ) && 600 === $webp_in['width'] && 300 === $webp_in['height'],
		'webp input is accepted and converted',
		wp_json_encode( $webp_in )
	);
}

$low  = sfir_fetch( sf_img( $jpeg_url, 'w=800&q=20&f=jpg' ) );
$high = sfir_fetch( sf_img( $jpeg_url, 'w=800&q=90&f=jpg' ) );

SFIR_TestRunner::check(
	strlen( $low['body'] ) < strlen( $high['body'] ),
	'q=20 produces a smaller file than q=90',
	strlen( $low['body'] ) . ' vs ' . strlen( $high['body'] )
);

/* ---------------------------------------------------------------------------
 * 4. Errors, placeholders and logging.
 * ------------------------------------------------------------------------ */

SFIR_TestRunner::group( '11.2.4 errors and placeholders' );

sfir_reset_log();

/**
 * Fetches an image URL and returns the placeholder code it carries, if any.
 *
 * @param string $url URL to fetch.
 * @return array array( status, code )
 */
function sfir_placeholder_code( $url ) {
	$response = sfir_fetch( $url );

	$code = '';
	if ( preg_match( '/>(E0[1-7])</', $response['body'], $matches ) ) {
		$code = $matches[1];
	}

	return array( $response['status'], $code, $response );
}

list( $status, $code, $response ) = sfir_placeholder_code( sf_img( sfir_path_to_url( $fixtures['text'] ), 'w=300' ) );
SFIR_TestRunner::equals( 'E02', $code, 'a text file yields an E02 placeholder' );
SFIR_TestRunner::equals( 'image/svg+xml; charset=utf-8', $response['headers']['content-type'], 'placeholders are served as SVG' );

list( $status, $code ) = sfir_placeholder_code( sf_img( sfir_path_to_url( $fixtures['broken'] ), 'w=300' ) );
SFIR_TestRunner::equals( 'E02', $code, 'a corrupt .jpg yields an E02 placeholder' );

list( $status, $code ) = sfir_placeholder_code( sf_img( sfir_path_to_url( $fixtures['jpeg'] ) . '.missing.jpg', 'w=300' ) );
SFIR_TestRunner::equals( 'E01', $code, 'a missing file yields an E01 placeholder' );

list( $status, $code ) = sfir_placeholder_code( sf_img( 'https://example.com/photo.jpg', 'w=300' ) );
SFIR_TestRunner::equals( 'E03', $code, 'an external URL yields an E03 placeholder' );

foreach ( array( 'data:image/png;base64,AAAA', 'php://filter/resource=wp-config.php', 'file:///etc/passwd', '//evil.example.com/x.jpg' ) as $hostile ) {
	list( $status, $code ) = sfir_placeholder_code( sf_img( $hostile, 'w=100' ) );
	SFIR_TestRunner::equals( 'E03', $code, 'hostile scheme rejected: ' . $hostile );
}

foreach ( array( '/wp-config.php', '/wp-content/uploads/../../wp-config.php', "/wp-content/uploads/x\0.jpg" ) as $traversal ) {
	list( $status, $code ) = sfir_placeholder_code( sf_img( $traversal, 'w=100' ) );
	SFIR_TestRunner::check( in_array( $code, array( 'E01', 'E02' ), true ), 'traversal rejected: ' . str_replace( "\0", '\\0', $traversal ), $code );
}

$signed   = sf_img( $jpeg_url, 'w=333&f=jpg' );
$tampered = sfir_http( sfir_test_url( sfir_corrupt_hash( $signed ) ) );
SFIR_Cache::flush_runtime_cache();

SFIR_TestRunner::equals( 403, $tampered['status'], 'a tampered signature is answered with 403' );
SFIR_TestRunner::check( false !== strpos( $tampered['body'], 'E04' ), 'a tampered signature yields an E04 placeholder' );

$unsigned = sfir_http( sfir_test_url( str_replace( sfir_hash_of( $signed ), '', $signed ) ) );
SFIR_TestRunner::check(
	in_array( $unsigned['status'], array( 403, 404 ), true ),
	'a name without a signature is refused',
	(string) $unsigned['status']
);

$log = sfir_read_log();

foreach ( array( 'E01', 'E02', 'E03', 'E04' ) as $expected_code ) {
	SFIR_TestRunner::check(
		false !== strpos( $log, '[' . $expected_code . ']' ),
		'the log records a ' . $expected_code . ' line'
	);
}

SFIR_TestRunner::check(
	(bool) preg_match( '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \[E\d\d\] .+ \| source=.* \| params=.*$/m', $log ),
	'log lines follow the documented format'
);

SFIR_TestRunner::check(
	false === strpos( $log, SFIR_Security::get_secret() ),
	'the log never contains the signing secret'
);

$home = sfir_http( $base_url . '/' );
SFIR_TestRunner::equals( 200, $home['status'], 'the front page still answers 200 after all these errors' );

/* ---------------------------------------------------------------------------
 * 5. Invalidation.
 * ------------------------------------------------------------------------ */

SFIR_TestRunner::group( '11.2.5 cache invalidation' );

$invalidation_source = wp_get_upload_dir()['basedir'] . '/sfir-fixtures/2026/08/changing.jpg';

$image = imagecreatetruecolor( 800, 400 );
imagefilledrectangle( $image, 0, 0, 799, 399, imagecolorallocate( $image, 10, 10, 200 ) );
imagejpeg( $image, $invalidation_source, 90 );
imagedestroy( $image );

$changing_url = sfir_path_to_url( $invalidation_source );

$changing_first = sf_img( $changing_url, 'w=200&f=jpg' );
sfir_fetch( $changing_first );

$cache_file = sfir_url_to_path( $changing_first );
clearstatcache();
SFIR_TestRunner::check( file_exists( $cache_file ), 'the cache file exists before the source changes', $cache_file );

$before_pixel = sfir_pixel( file_get_contents( $cache_file ), 100, 50 );

$url_before = sf_img( $changing_url, 'w=200&f=jpg' );

SFIR_TestRunner::check( file_exists( $cache_file ), 'the cache file is present while it is fresh' );

// Rewrite the source with different content and a newer modification time.
$image = imagecreatetruecolor( 800, 400 );
imagefilledrectangle( $image, 0, 0, 799, 399, imagecolorallocate( $image, 250, 250, 0 ) );
imagejpeg( $image, $invalidation_source, 90 );
imagedestroy( $image );
touch( $invalidation_source, time() + 5 );
clearstatcache();
SFIR_Cache::forget_source_size( $invalidation_source );
SFIR_Cache::flush_runtime_cache();

$after_change = sf_img( $changing_url, 'w=200&f=jpg' );

SFIR_TestRunner::equals( $url_before, $after_change, 'the URL is unchanged after the source was rewritten' );

clearstatcache();

SFIR_TestRunner::check(
	! file_exists( $cache_file ),
	'a stale cache file is dropped, so the next request regenerates it'
);

sfir_fetch( $after_change );
clearstatcache();

$after_pixel = sfir_pixel( file_get_contents( $cache_file ), 100, 50 );

SFIR_TestRunner::check(
	$before_pixel !== $after_pixel && $after_pixel[0] > 200 && $after_pixel[2] < 60,
	'the cache file was regenerated from the new source',
	wp_json_encode( array( $before_pixel, $after_pixel ) )
);

/* ---------------------------------------------------------------------------
 * 6. Declared sizes and the source dimension cache.
 * ------------------------------------------------------------------------ */

SFIR_TestRunner::group( '11.2.6 declared sizes and dimension cache' );

foreach ( $cases as $case ) {
	list( $source, $params, $width, $height, $label ) = $case;

	SFIR_TestRunner::check(
		$width === sf_img_width( $source, $params ) && $height === sf_img_height( $source, $params ),
		'sf_img_width/height match the generated file for ' . $label,
		sf_img_width( $source, $params ) . 'x' . sf_img_height( $source, $params )
	);
}

SFIR_TestRunner::equals( 300, sf_img_width( '/nope/missing.jpg', '' ), 'a missing source reports the placeholder width' );
SFIR_TestRunner::equals( 200, sf_img_height( '/nope/missing.jpg', '' ), 'a missing source reports the placeholder height' );
SFIR_TestRunner::equals( 640, sf_img_width( '/nope/missing.jpg', 'w=640&h=480' ), 'a missing source honours the requested width' );
SFIR_TestRunner::equals( 480, sf_img_height( '/nope/missing.jpg', 'w=640&h=480' ), 'a missing source honours the requested height' );

$probe_source = wp_get_upload_dir()['basedir'] . '/sfir-fixtures/2026/08/probe.jpg';

$image = imagecreatetruecolor( 800, 600 );
imagefilledrectangle( $image, 0, 0, 799, 599, imagecolorallocate( $image, 90, 90, 90 ) );
imagejpeg( $image, $probe_source, 90 );
imagedestroy( $image );

$probe_mtime = filemtime( $probe_source );

$size = SFIR_Cache::get_source_size( $probe_source );
SFIR_TestRunner::check( 800 === $size['width'] && 600 === $size['height'], 'the source dimensions are read once' );

$transient = get_transient( 'sfir_size_' . md5( $probe_source ) );
SFIR_TestRunner::check(
	is_array( $transient ) && 800 === (int) $transient['width'] && 600 === (int) $transient['height'],
	'the dimensions are stored in a transient',
	wp_json_encode( $transient )
);

// Replace the file with different dimensions but keep the modification time:
// a second call must answer from the cache instead of probing the file again.
$image = imagecreatetruecolor( 400, 300 );
imagefilledrectangle( $image, 0, 0, 399, 299, imagecolorallocate( $image, 90, 90, 90 ) );
imagejpeg( $image, $probe_source, 90 );
imagedestroy( $image );
touch( $probe_source, $probe_mtime );
clearstatcache();

SFIR_Cache::flush_runtime_cache();

$size = SFIR_Cache::get_source_size( $probe_source );
SFIR_TestRunner::check(
	800 === $size['width'] && 600 === $size['height'],
	'an unchanged mtime serves the cached dimensions without calling getimagesize again',
	wp_json_encode( $size )
);

touch( $probe_source, $probe_mtime + 10 );
clearstatcache();
SFIR_Cache::flush_runtime_cache();

$size = SFIR_Cache::get_source_size( $probe_source );
SFIR_TestRunner::check(
	400 === $size['width'] && 300 === $size['height'],
	'a newer mtime invalidates the cached dimensions',
	wp_json_encode( $size )
);

/* ---------------------------------------------------------------------------
 * 7. Sources: URL, attachment ID and ACF array.
 * ------------------------------------------------------------------------ */

SFIR_TestRunner::group( '11.2.7 source types' );

$attachment_id = sfir_attach( $fixtures['jpeg'] );

SFIR_TestRunner::check( $attachment_id > 0, 'the fixture is registered as an attachment' );

$from_url = sf_img( $jpeg_url, 'w=500&h=250&crop=1&f=jpg' );
$from_id  = sf_img( $attachment_id, 'w=500&h=250&crop=1&f=jpg' );
$from_acf = sf_img(
	array(
		'ID'    => $attachment_id,
		'url'   => $jpeg_url,
		'sizes' => array( 'thumbnail' => $jpeg_url ),
	),
	'w=500&h=250&crop=1&f=jpg'
);

SFIR_TestRunner::equals( $from_url, $from_id, 'attachment ID and URL resolve to the same result' );
SFIR_TestRunner::equals( $from_url, $from_acf, 'an ACF array resolves to the same result' );

$from_gallery = sf_img(
	array(
		array( 'nothing' => 'here' ),
		array( 'url' => $jpeg_url ),
	),
	'w=500&h=250&crop=1&f=jpg'
);

SFIR_TestRunner::equals( $from_url, $from_gallery, 'a nested gallery array resolves to the same result' );

foreach ( array( new stdClass(), true, null, array(), 'not a url at all', -5 ) as $bad_source ) {
	$url = sf_img( $bad_source, 'w=100' );
	SFIR_TestRunner::check(
		false !== strpos( $url, 'sfir_placeholder' ),
		'unusable source falls back to a placeholder: ' . SFIR_Core::describe_source( $bad_source )
	);
}

/* ---------------------------------------------------------------------------
 * 8. Concurrency.
 * ------------------------------------------------------------------------ */

SFIR_TestRunner::group( '11.2.8 concurrent generation' );

$concurrent_url = sfir_test_url( sf_img( $jpeg_url, 'w=1234&h=700&crop=1&q=81&f=jpg' ) );
$concurrent     = sfir_http_parallel( array( $concurrent_url, $concurrent_url ) );

SFIR_TestRunner::check(
	200 === $concurrent[0]['status'] && 200 === $concurrent[1]['status'],
	'both concurrent requests answer 200',
	$concurrent[0]['status'] . '/' . $concurrent[1]['status']
);

$info_a = sfir_image_info( $concurrent[0]['body'] );
$info_b = sfir_image_info( $concurrent[1]['body'] );

SFIR_TestRunner::check( is_array( $info_a ) && is_array( $info_b ), 'both concurrent responses are valid images' );

$concurrent_files = (array) glob( SFIR_Cache::get_cache_dir() . '/sfir-fixtures/2026/08/landscape-1234x700-c1-q81-*.*' );
SFIR_TestRunner::equals( 1, count( $concurrent_files ), 'exactly one cache file was produced' );

$locks = array();
foreach ( sfir_list_files( SFIR_Cache::get_cache_dir() ) as $file ) {
	if ( '.lock' === substr( $file, -5 ) ) {
		$locks[] = $file;
	}
}

SFIR_TestRunner::equals( 0, count( $locks ), 'no lock files are left behind', wp_json_encode( $locks ) );

/* ---------------------------------------------------------------------------
 * 9. Many images, several passes.
 * ------------------------------------------------------------------------ */

SFIR_TestRunner::group( '11.2.9 many images over several passes' );

SFIR_Cache::clear();
SFIR_Cache::flush_runtime_cache();

$variants = array();
for ( $i = 0; $i < 30; $i++ ) {
	$variants[] = 'w=' . ( 100 + $i * 10 ) . '&q=' . ( 40 + $i ) . '&f=webp';
}

$pass_one = array();
foreach ( $variants as $variant ) {
	$pass_one[] = sf_img( $jpeg_url, $variant );
}

$pretty = 0;
foreach ( $pass_one as $url ) {
	if ( false === strpos( $url, '?' ) && false !== strpos( $url, '/cache_images/' ) ) {
		++$pretty;
	}
}

SFIR_TestRunner::equals( 30, $pretty, 'the first render already returns 30 plain cache URLs' );
SFIR_TestRunner::equals( 0, count( sfir_cached_files() ), 'none of them exists on disk yet' );

foreach ( $pass_one as $url ) {
	sfir_fetch( $url );
}

SFIR_Cache::flush_runtime_cache();
clearstatcache();

$identical = 0;
foreach ( $variants as $index => $variant ) {
	if ( sf_img( $jpeg_url, $variant ) === $pass_one[ $index ] ) {
		++$identical;
	}
}

SFIR_TestRunner::equals( 30, $identical, 'the second render returns the same 30 URLs' );
SFIR_TestRunner::equals( 30, count( sfir_cached_files() ), 'exactly 30 files sit in the cache' );

/* ---------------------------------------------------------------------------
 * 10. Cache statistics and clearing (the admin screen itself is covered by admin.sh).
 * ------------------------------------------------------------------------ */

SFIR_TestRunner::group( '11.2.10 cache statistics and clearing' );

$stats = SFIR_Cache::get_stats();

SFIR_TestRunner::equals( 30, $stats['files'], 'the statistics report 30 files' );

$expected_bytes = 0;
foreach ( sfir_cached_files() as $file ) {
	$expected_bytes += filesize( $file );
}

SFIR_TestRunner::equals( $expected_bytes, $stats['bytes'], 'the statistics report the exact total size' );

// A stray file outside the cache directory must survive a cache clear.
$outside = wp_get_upload_dir()['basedir'] . '/sfir-fixtures/keep-me.txt';
file_put_contents( $outside, 'keep' );

$deleted = SFIR_Cache::clear();

SFIR_TestRunner::equals( 30, $deleted, 'clearing the cache deletes exactly the 30 cached files' );
SFIR_TestRunner::equals( 0, count( sfir_cached_files() ), 'the cache directory is empty afterwards' );
SFIR_TestRunner::check( file_exists( $outside ), 'files outside the cache directory are untouched' );
SFIR_TestRunner::check( file_exists( SFIR_Cache::get_cache_dir() . '/index.php' ), 'the directory protection files survive' );
SFIR_TestRunner::check( file_exists( SFIR_Cache::get_cache_dir() . '/.htaccess' ), 'the .htaccess file survives' );
SFIR_TestRunner::check( file_exists( $fixtures['jpeg'] ), 'source images are untouched' );

/* ---------------------------------------------------------------------------
 * 11. Log rotation.
 * ------------------------------------------------------------------------ */

SFIR_TestRunner::group( '11.2.11 log rotation' );

$log_file = SFIR_Logger::get_log_file();

$bulk = '';
for ( $i = 1; $i <= 45000; $i++ ) {
	$bulk .= '[2026-01-01 00:00:00] [E01] filler line ' . $i . " | source=x | params=y\n";
}

file_put_contents( $log_file, $bulk );

SFIR_TestRunner::check( filesize( $log_file ) > 2097152, 'the log is over 2 MB before rotation', (string) filesize( $log_file ) );

SFIR_Logger::log( 'E05', 'line written after the limit was exceeded', 'x', 'y' );
clearstatcache();

$rotated = file_get_contents( $log_file );
$lines   = array_values( array_filter( explode( "\n", $rotated ), 'strlen' ) );

SFIR_TestRunner::check( filesize( $log_file ) < 2097152, 'the log is truncated', (string) filesize( $log_file ) );
SFIR_TestRunner::check( count( $lines ) <= 501 && count( $lines ) >= 400, 'about 500 lines are kept', (string) count( $lines ) );
SFIR_TestRunner::check( false !== strpos( $rotated, 'filler line 45000' ), 'the newest pre-existing lines are kept' );
SFIR_TestRunner::check( false === strpos( $rotated, 'filler line 1 ' ), 'the oldest lines are dropped' );
SFIR_TestRunner::check( false !== strpos( $rotated, 'line written after the limit was exceeded' ), 'the new line is appended' );

sfir_reset_log();

exit( SFIR_TestRunner::summary() );
