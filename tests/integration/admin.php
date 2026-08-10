<?php
/**
 * Admin screen tests (spec 11.2.10).
 *
 * Drives the real screen over HTTP with a logged in administrator, so that
 * capability checks, nonces and redirects are exercised exactly as they are in
 * production.
 *
 * Run through WP-CLI:
 *   SFIR_ADMIN_USER=admin SFIR_ADMIN_PASS=... wp eval-file tests/integration/admin.php
 *
 * @package SFimageResizer
 */

require_once __DIR__ . '/lib.php';

$base_url = getenv( 'SFIR_BASE_URL' );
$base_url = $base_url ? rtrim( $base_url, '/' ) : rtrim( home_url(), '/' );

define( 'SFIR_TEST_BASE_URL', $base_url );

$user      = getenv( 'SFIR_ADMIN_USER' ) ? getenv( 'SFIR_ADMIN_USER' ) : 'admin';
$password  = getenv( 'SFIR_ADMIN_PASS' ) ? getenv( 'SFIR_ADMIN_PASS' ) : 'admin123';
define( 'SFIR_TEST_COOKIES', tempnam( sys_get_temp_dir(), 'sfir-cookies-' ) );
$page_url  = $base_url . '/wp-admin/options-general.php?page=sf-image-resizer';
$post_url  = $base_url . '/wp-admin/admin-post.php';

/**
 * Performs a request that carries the shared cookie jar.
 *
 * @param string $url     URL to request.
 * @param array  $options Extra cURL options.
 * @return array See sfir_http().
 */
function sfir_admin_request( $url, $options = array() ) {
	return sfir_http(
		$url,
		$options + array(
			CURLOPT_COOKIEJAR  => SFIR_TEST_COOKIES,
			CURLOPT_COOKIEFILE => SFIR_TEST_COOKIES,
			CURLOPT_COOKIE     => 'wordpress_test_cookie=WP Cookie check',
		)
	);
}

/**
 * Extracts the nonce of the form that submits a given action.
 *
 * @param string $html   Page markup.
 * @param string $action Value of the hidden "action" field.
 * @return string Nonce value, or an empty string.
 */
function sfir_form_nonce( $html, $action ) {
	foreach ( explode( '<form', $html ) as $chunk ) {
		if ( false === strpos( $chunk, 'value="' . $action . '"' ) ) {
			continue;
		}

		if ( preg_match( '/name="_wpnonce"\s+value="([a-z0-9]+)"/i', $chunk, $matches ) ) {
			return $matches[1];
		}
	}

	return '';
}

/* --------------------------------------------------------------------------
 * Access control.
 * ----------------------------------------------------------------------- */

SFIR_TestRunner::group( '11.2.10 admin access control' );

$anonymous = sfir_http( $page_url );

SFIR_TestRunner::check(
	302 === $anonymous['status'] || 403 === $anonymous['status'],
	'the settings page is not reachable without logging in',
	(string) $anonymous['status']
);

$anonymous_post = sfir_http(
	$post_url,
	array(
		CURLOPT_POST       => true,
		CURLOPT_POSTFIELDS => 'action=sfir_clear_cache',
	)
);

SFIR_TestRunner::check(
	200 !== $anonymous_post['status'] || false === strpos( $anonymous_post['body'], 'deleted' ),
	'an anonymous clear-cache POST is refused',
	(string) $anonymous_post['status']
);

/* --------------------------------------------------------------------------
 * Log in.
 * ----------------------------------------------------------------------- */

sfir_admin_request(
	$base_url . '/wp-login.php',
	array(
		CURLOPT_POST       => true,
		CURLOPT_POSTFIELDS => http_build_query(
			array(
				'log'         => $user,
				'pwd'         => $password,
				'wp-submit'   => 'Log In',
				'redirect_to' => $base_url . '/wp-admin/',
				'testcookie'  => '1',
			)
		),
	)
);

$page = sfir_admin_request( $page_url );

SFIR_TestRunner::equals( 200, $page['status'], 'an administrator can open the settings page' );
SFIR_TestRunner::check( false !== strpos( $page['body'], 'SFimageResizer' ), 'the page carries the plugin name' );

if ( 200 !== $page['status'] ) {
	echo "Login failed, skipping the rest of the admin suite.\n";
	exit( SFIR_TestRunner::summary() );
}

/* --------------------------------------------------------------------------
 * Statistics.
 * ----------------------------------------------------------------------- */

SFIR_TestRunner::group( '11.2.10 cache statistics' );

SFIR_Cache::clear();
SFIR_Cache::flush_runtime_cache();

$fixtures = sfir_build_fixtures();
$jpeg_url = sfir_path_to_url( $fixtures['jpeg'] );

for ( $i = 0; $i < 5; $i++ ) {
	sfir_fetch( sf_img( $jpeg_url, 'w=' . ( 120 + $i * 10 ) . '&f=jpg' ) );
}

$files = sfir_cached_files();
$bytes = 0;
foreach ( $files as $file ) {
	$bytes += filesize( $file );
}

SFIR_TestRunner::equals( 5, count( $files ), 'five cache files were produced for this test' );

$page = sfir_admin_request( $page_url );

SFIR_TestRunner::check(
	(bool) preg_match( '#Cached files</th>\s*<td>\s*5\b#', $page['body'] ),
	'the page reports the correct file count'
);

$megabytes = number_format( $bytes / 1048576, 2 );

SFIR_TestRunner::check(
	false !== strpos( $page['body'], $megabytes . ' MB' ),
	'the page reports the correct total size',
	$megabytes . ' MB expected'
);

/* --------------------------------------------------------------------------
 * Clearing the cache.
 * ----------------------------------------------------------------------- */

SFIR_TestRunner::group( '11.2.10 clear cache' );

$without_nonce = sfir_admin_request(
	$post_url,
	array(
		CURLOPT_POST       => true,
		CURLOPT_POSTFIELDS => 'action=sfir_clear_cache',
	)
);

clearstatcache();

SFIR_TestRunner::equals( 403, $without_nonce['status'], 'a clear-cache POST without a nonce is refused with 403' );
SFIR_TestRunner::equals( 5, count( sfir_cached_files() ), 'no file was deleted by the nonce-less request' );

$nonce = sfir_form_nonce( $page['body'], 'sfir_clear_cache' );

SFIR_TestRunner::check( '' !== $nonce, 'the clear-cache form carries a nonce' );

$with_nonce = sfir_admin_request(
	$post_url,
	array(
		CURLOPT_POST       => true,
		CURLOPT_POSTFIELDS => http_build_query(
			array(
				'action'   => 'sfir_clear_cache',
				'_wpnonce' => $nonce,
			)
		),
	)
);

clearstatcache();

SFIR_TestRunner::equals( 302, $with_nonce['status'], 'a signed clear-cache POST redirects back to the page' );
SFIR_TestRunner::equals( 0, count( sfir_cached_files() ), 'the signed request emptied the cache' );
SFIR_TestRunner::check( file_exists( $fixtures['jpeg'] ), 'source images survived the cache clear' );

$after = sfir_admin_request( $page_url );

SFIR_TestRunner::check(
	false !== strpos( $after['body'], '5 cached files deleted.' ),
	'an admin notice reports how many files were deleted'
);

/* --------------------------------------------------------------------------
 * The log viewer.
 * ----------------------------------------------------------------------- */

SFIR_TestRunner::group( '11.2.10 log viewer' );

$log_file = SFIR_Logger::get_log_file();
$lines    = '';

for ( $i = 1; $i <= 60; $i++ ) {
	$lines .= '[2026-01-01 00:00:00] [E01] marker-' . $i . " | source=x | params=y\n";
}

file_put_contents( $log_file, $lines );

$page = sfir_admin_request( $page_url );

SFIR_TestRunner::check( false !== strpos( $page['body'], 'marker-1 ' ), 'the log viewer shows the first line' );
SFIR_TestRunner::check( false !== strpos( $page['body'], 'marker-50 ' ), 'the log viewer shows the 50th line' );
SFIR_TestRunner::check( false === strpos( $page['body'], 'marker-51 ' ), 'the log viewer stops after 50 lines' );
SFIR_TestRunner::check( false !== strpos( $page['body'], '<textarea class="sfir-log" readonly' ), 'the log sits in a read-only textarea' );

// The log content must be escaped before it reaches the page.
file_put_contents( $log_file, "[2026-01-01 00:00:00] [E01] <script>alert(1)</script> | source=x | params=y\n" );

$page = sfir_admin_request( $page_url );

SFIR_TestRunner::check( false === strpos( $page['body'], '<script>alert(1)</script>' ), 'log content is escaped' );
SFIR_TestRunner::check( false !== strpos( $page['body'], '&lt;script&gt;' ), 'log content is escaped as entities' );

$without_nonce = sfir_admin_request(
	$post_url,
	array(
		CURLOPT_POST       => true,
		CURLOPT_POSTFIELDS => 'action=sfir_clear_log',
	)
);

clearstatcache();

SFIR_TestRunner::equals( 403, $without_nonce['status'], 'a clear-log POST without a nonce is refused with 403' );
SFIR_TestRunner::check( filesize( $log_file ) > 0, 'the log survived the nonce-less request' );

$nonce = sfir_form_nonce( $page['body'], 'sfir_clear_log' );

SFIR_TestRunner::check( '' !== $nonce, 'the clear-log form carries a nonce' );

$with_nonce = sfir_admin_request(
	$post_url,
	array(
		CURLOPT_POST       => true,
		CURLOPT_POSTFIELDS => http_build_query(
			array(
				'action'   => 'sfir_clear_log',
				'_wpnonce' => $nonce,
			)
		),
	)
);

clearstatcache();

SFIR_TestRunner::equals( 302, $with_nonce['status'], 'a signed clear-log POST redirects back to the page' );
SFIR_TestRunner::equals( 0, filesize( $log_file ), 'the signed request emptied the log' );

$after = sfir_admin_request( $page_url );

SFIR_TestRunner::check(
	false !== strpos( $after['body'], 'The error log has been cleared.' ),
	'an admin notice confirms the log was cleared'
);

/* --------------------------------------------------------------------------
 * Documentation and local assets.
 * ----------------------------------------------------------------------- */

SFIR_TestRunner::group( '11.2.10 documentation and assets' );

$page = sfir_admin_request( $page_url );

foreach ( array( 'sf_img(', 'sf_img_width(', 'sf_img_height(', 'sf_img_tag(', 'E07', 'crop', 'WebP fallback' ) as $needle ) {
	SFIR_TestRunner::check( false !== strpos( $page['body'], $needle ), 'the documentation mentions ' . $needle );
}

preg_match_all( '#<(?:script[^>]+src|link[^>]+href)="([^"]+)"#', $page['body'], $matches );

$external = array();
foreach ( $matches[1] as $asset ) {
	if ( 0 === strpos( $asset, 'http' ) && 0 !== strpos( $asset, $base_url ) ) {
		$external[] = $asset;
	}
}

SFIR_TestRunner::equals( 0, count( $external ), 'the screen loads no third party assets', wp_json_encode( $external ) );

foreach ( array( '/wp-content/plugins/sf-image-resizer/admin/assets/css/admin.css', '/wp-content/plugins/sf-image-resizer/admin/assets/js/admin.js' ) as $asset ) {
	SFIR_TestRunner::check( false !== strpos( $page['body'], $asset ), 'the local asset is enqueued: ' . basename( $asset ) );
	SFIR_TestRunner::equals( 200, sfir_http( $base_url . $asset )['status'], 'the local asset is served: ' . basename( $asset ) );
}

/* --------------------------------------------------------------------------
 * Directory protection.
 * ----------------------------------------------------------------------- */

SFIR_TestRunner::group( 'directory protection' );

foreach ( array( 'cache_images', 'logs' ) as $directory ) {
	$listing = sfir_http( $base_url . '/wp-content/uploads/SFimageResizer/' . $directory . '/' );

	SFIR_TestRunner::check(
		false === strpos( $listing['body'], 'Index of' ),
		'the ' . $directory . ' directory does not list its contents'
	);

	SFIR_TestRunner::check(
		file_exists( SFIR_Cache::get_base_dir() . '/' . $directory . '/index.php' ),
		'the ' . $directory . ' directory has an index.php'
	);

	SFIR_TestRunner::check(
		file_exists( SFIR_Cache::get_base_dir() . '/' . $directory . '/.htaccess' ),
		'the ' . $directory . ' directory has an .htaccess'
	);
}

$logs_htaccess = file_get_contents( SFIR_Cache::get_logs_dir() . '/.htaccess' );

SFIR_TestRunner::check(
	false !== strpos( $logs_htaccess, 'Require all denied' ) && false !== strpos( $logs_htaccess, 'Deny from all' ),
	'the logs .htaccess denies access on both Apache generations'
);

unlink( SFIR_TEST_COOKIES );

exit( SFIR_TestRunner::summary() );
