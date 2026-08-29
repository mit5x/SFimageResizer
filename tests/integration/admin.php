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
$check_url = $page_url . '&tab=check';
$docs_url  = $page_url . '&tab=documentation';
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

// These groups describe what happens when a browser asks for a file that is
// not there yet, so they pin the mode that leaves generation to that request.
SFIR_Generator::set_mode( SFIR_Generator::MODE_NEVER );
SFIR_Cache::flush_runtime_cache();

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
 * The configuration self-check.
 * ----------------------------------------------------------------------- */

SFIR_TestRunner::group( 'self-check level 1: real traffic' );

SFIR_Diagnostics::reset();
SFIR_Cache::flush_runtime_cache();

SFIR_TestRunner::equals( 0, SFIR_Diagnostics::get_confirmed_at(), 'no confirmation is stored to begin with' );

$probe_source = SFIR_Diagnostics::ensure_probe_image();

SFIR_TestRunner::check( '' !== $probe_source && is_file( $probe_source ), 'the probe image exists', (string) $probe_source );

$probe_size = getimagesize( $probe_source );
SFIR_TestRunner::check( is_array( $probe_size ) && 16 === $probe_size[0] && 16 === $probe_size[1], 'the probe image is a 16x16 PNG', wp_json_encode( $probe_size ) );

// A normal front end generation has to set the confirmation on its own.
$traffic = sfir_fetch( sf_img( sfir_path_to_url( $fixtures['jpeg'] ), 'w=277&f=jpg' ) );

SFIR_TestRunner::equals( 200, $traffic['status'], 'a normal image request succeeds' );

sfir_forget_options( SFIR_Diagnostics::CONFIRMED_OPTION );

$confirmed = SFIR_Diagnostics::get_confirmed_at();

SFIR_TestRunner::check( $confirmed > 0, 'serving a generated file records the confirmation', (string) $confirmed );

// A second generation on the same day must not rewrite the option.
sfir_fetch( sf_img( sfir_path_to_url( $fixtures['jpeg'] ), 'w=278&f=jpg' ) );

sfir_forget_options( SFIR_Diagnostics::CONFIRMED_OPTION );

SFIR_TestRunner::equals( $confirmed, SFIR_Diagnostics::get_confirmed_at(), 'a second generation the same day does not rewrite the option' );

// Only an explicit request, or a day passing, refreshes it.
update_option( SFIR_Diagnostics::CONFIRMED_OPTION, $confirmed - DAY_IN_SECONDS - 10, false );
sfir_fetch( sf_img( sfir_path_to_url( $fixtures['jpeg'] ), 'w=279&f=jpg' ) );

sfir_forget_options( SFIR_Diagnostics::CONFIRMED_OPTION );

SFIR_TestRunner::check( SFIR_Diagnostics::get_confirmed_at() > $confirmed - DAY_IN_SECONDS - 10, 'a day later the confirmation is refreshed' );

SFIR_TestRunner::group( 'self-check level 1: what the screen shows' );

$page = sfir_admin_request( $check_url );

SFIR_TestRunner::check( false !== strpos( $page['body'], 'Configuration check' ), 'the page shows the configuration check' );
SFIR_TestRunner::check( false !== strpos( $page['body'], 'Pretty URLs are working.' ), 'the page reports that pretty URLs work' );
SFIR_TestRunner::check( false !== strpos( $page['body'], 'Confirmed by a real request on' ), 'the page names the moment it was confirmed' );
SFIR_TestRunner::check( false === strpos( $page['body'], 'location ^~' ), 'no nginx snippet is shown while it is confirmed' );
SFIR_TestRunner::check( false === strpos( $page['body'], 'Not confirmed automatically yet' ), 'no warning is shown while it is confirmed' );
SFIR_TestRunner::check( false === strpos( $page['body'], 'AllowOverride' ), 'no Apache note is shown while it is confirmed' );
SFIR_TestRunner::check(
	empty( sfir_admin_settings( $page['body'] )['probeUrl'] ),
	'no browser probe is handed out once it is confirmed'
);

SFIR_TestRunner::group( 'self-check level 2: the browser probe' );

SFIR_Diagnostics::reset();

$page = sfir_admin_request( $check_url );

SFIR_TestRunner::check( false !== strpos( $page['body'], 'Not confirmed automatically yet' ), 'without a confirmation the page says so plainly' );
SFIR_TestRunner::check( false !== strpos( $page['body'], 'does not mean anything is broken' ), 'the wording avoids claiming a fault' );
SFIR_TestRunner::check( false !== strpos( $page['body'], 'location ^~' ), 'the nginx snippet is offered as a hint' );
SFIR_TestRunner::check( false !== strpos( $page['body'], '<details class="sfir-hints">' ), 'the hints are collapsed' );

$probe_urls = array();

for ( $i = 0; $i < 2; $i++ ) {
	$settings = sfir_admin_settings( sfir_admin_request( $check_url )['body'] );

	if ( ! empty( $settings['probeUrl'] ) ) {
		$probe_urls[] = $settings['probeUrl'];
	}
}

SFIR_TestRunner::equals( 2, count( $probe_urls ), 'the page hands a probe URL to the browser' );
SFIR_TestRunner::check(
	2 === count( $probe_urls ) && $probe_urls[0] !== $probe_urls[1],
	'each render asks for a different size, so the cache always misses',
	wp_json_encode( $probe_urls )
);

if ( ! empty( $probe_urls ) ) {
	$probe_url = $probe_urls[0];

	SFIR_TestRunner::check( false !== strpos( $probe_url, '/cache_images/' ), 'the probe URL is a cache URL', $probe_url );
	SFIR_TestRunner::check( ! file_exists( sfir_url_to_path( $probe_url ) ), 'the probe file does not exist yet' );

	// This is what the administrator's browser does.
	$fetched = sfir_http( sfir_test_url( $probe_url ) );

	SFIR_TestRunner::equals( 200, $fetched['status'], 'the browser probe URL answers 200' );
	SFIR_TestRunner::check(
		0 === strpos( (string) ( isset( $fetched['headers']['content-type'] ) ? $fetched['headers']['content-type'] : '' ), 'image/' ),
		'the browser probe URL answers with an image'
	);
	SFIR_TestRunner::check( is_array( sfir_image_info( $fetched['body'] ) ), 'the probe answer is a valid image' );
}

SFIR_TestRunner::group( 'self-check: the confirmation endpoint' );

SFIR_Diagnostics::reset();

$ajax_url = $base_url . '/wp-admin/admin-ajax.php';

$no_nonce = sfir_admin_request(
	$ajax_url,
	array(
		CURLOPT_POST       => true,
		CURLOPT_POSTFIELDS => 'action=' . SFIR_Diagnostics::AJAX_ACTION,
	)
);

SFIR_TestRunner::check(
	200 !== $no_nonce['status'] || '-1' === trim( $no_nonce['body'] ),
	'a confirmation without a nonce is refused',
	$no_nonce['status'] . ' ' . substr( $no_nonce['body'], 0, 40 )
);
sfir_forget_options( SFIR_Diagnostics::CONFIRMED_OPTION );
SFIR_TestRunner::equals( 0, SFIR_Diagnostics::get_confirmed_at(), 'the refused request stored nothing' );

// A logged in user without manage_options must be refused as well.
$editor_id = wp_insert_user(
	array(
		'user_login' => 'sfir-subscriber',
		'user_pass'  => 'sfir-subscriber-pass',
		'role'       => 'subscriber',
	)
);

if ( ! is_wp_error( $editor_id ) || 'existing_user_login' === $editor_id->get_error_code() ) {
	$subscriber_cookies = tempnam( sys_get_temp_dir(), 'sfir-sub-' );

	sfir_http(
		$base_url . '/wp-login.php',
		array(
			CURLOPT_POST       => true,
			CURLOPT_POSTFIELDS => http_build_query(
				array(
					'log'        => 'sfir-subscriber',
					'pwd'        => 'sfir-subscriber-pass',
					'wp-submit'  => 'Log In',
					'testcookie' => '1',
				)
			),
			CURLOPT_COOKIEJAR  => $subscriber_cookies,
			CURLOPT_COOKIEFILE => $subscriber_cookies,
			CURLOPT_COOKIE     => 'wordpress_test_cookie=WP Cookie check',
		)
	);

	$as_subscriber = sfir_http(
		$ajax_url,
		array(
			CURLOPT_POST       => true,
			CURLOPT_POSTFIELDS => 'action=' . SFIR_Diagnostics::AJAX_ACTION,
			CURLOPT_COOKIEJAR  => $subscriber_cookies,
			CURLOPT_COOKIEFILE => $subscriber_cookies,
			CURLOPT_COOKIE     => 'wordpress_test_cookie=WP Cookie check',
		)
	);

	SFIR_TestRunner::check(
		200 !== $as_subscriber['status'],
		'a subscriber cannot confirm the check',
		$as_subscriber['status'] . ' ' . substr( $as_subscriber['body'], 0, 60 )
	);
	sfir_forget_options( SFIR_Diagnostics::CONFIRMED_OPTION );
	SFIR_TestRunner::equals( 0, SFIR_Diagnostics::get_confirmed_at(), 'the subscriber request stored nothing' );

	unlink( $subscriber_cookies );
}

$settings = sfir_admin_settings( sfir_admin_request( $check_url )['body'] );
$nonce    = isset( $settings['nonce'] ) ? $settings['nonce'] : '';

SFIR_TestRunner::check( '' !== $nonce, 'the page hands a nonce to the browser check' );
SFIR_TestRunner::check( ! empty( $settings['ajaxAction'] ), 'the page hands the action name to the browser check' );

$confirm = sfir_admin_request(
	$ajax_url,
	array(
		CURLOPT_POST       => true,
		CURLOPT_POSTFIELDS => http_build_query(
			array(
				'action'   => SFIR_Diagnostics::AJAX_ACTION,
				'_wpnonce' => $nonce,
			)
		),
	)
);

SFIR_TestRunner::equals( 200, $confirm['status'], 'a signed confirmation is accepted' );
sfir_forget_options( SFIR_Diagnostics::CONFIRMED_OPTION );
SFIR_TestRunner::check( SFIR_Diagnostics::get_confirmed_at() > 0, 'the signed confirmation is stored' );
SFIR_TestRunner::equals(
	0,
	count( (array) glob( SFIR_Diagnostics::get_probe_cache_dir() . '/*.webp' ) ) + count( (array) glob( SFIR_Diagnostics::get_probe_cache_dir() . '/*.jpg' ) ),
	'the probe cache files were cleaned up'
);

SFIR_TestRunner::group( 'self-check: reset' );

$page  = sfir_admin_request( $check_url );
$nonce = sfir_form_nonce( $page['body'], 'sfir_recheck' );

SFIR_TestRunner::check( '' !== $nonce, 'the re-check form carries a nonce' );

$without_nonce = sfir_admin_request(
	$post_url,
	array(
		CURLOPT_POST       => true,
		CURLOPT_POSTFIELDS => 'action=sfir_recheck',
	)
);

SFIR_TestRunner::equals( 403, $without_nonce['status'], 'a re-check POST without a nonce is refused with 403' );
sfir_forget_options( SFIR_Diagnostics::CONFIRMED_OPTION );
SFIR_TestRunner::check( SFIR_Diagnostics::get_confirmed_at() > 0, 'the nonce-less request did not reset anything' );

$with_nonce = sfir_admin_request(
	$post_url,
	array(
		CURLOPT_POST       => true,
		CURLOPT_POSTFIELDS => http_build_query(
			array(
				'action'   => 'sfir_recheck',
				'_wpnonce' => $nonce,
			)
		),
	)
);

SFIR_TestRunner::equals( 302, $with_nonce['status'], 'a signed re-check POST redirects back to the page' );
sfir_forget_options( SFIR_Diagnostics::CONFIRMED_OPTION );
SFIR_TestRunner::equals( 0, SFIR_Diagnostics::get_confirmed_at(), '"check again" resets the confirmation' );

SFIR_Cache::flush_runtime_cache();

/* --------------------------------------------------------------------------
 * The log viewer.
 * ----------------------------------------------------------------------- */

// Back to the shipped default for the rest of the screen.
delete_option( SFIR_Generator::MODE_OPTION );
SFIR_Cache::flush_runtime_cache();

SFIR_TestRunner::group( '11.2.10 log viewer' );

$log_file = SFIR_Logger::get_log_file();
$lines    = '';

for ( $i = 1; $i <= 60; $i++ ) {
	$lines .= '[2026-01-01 00:00:00] [E01] marker-' . $i . " | source=x | params=y\n";
}

file_put_contents( $log_file, $lines );

$page = sfir_admin_request( $check_url );

SFIR_TestRunner::check( false !== strpos( $page['body'], 'marker-1 ' ), 'the log viewer shows the first line' );
SFIR_TestRunner::check( false !== strpos( $page['body'], 'marker-50 ' ), 'the log viewer shows the 50th line' );
SFIR_TestRunner::check( false === strpos( $page['body'], 'marker-51 ' ), 'the log viewer stops after 50 lines' );
SFIR_TestRunner::check( false !== strpos( $page['body'], '<textarea class="sfir-log" readonly' ), 'the log sits in a read-only textarea' );

// The log content must be escaped before it reaches the page.
file_put_contents( $log_file, "[2026-01-01 00:00:00] [E01] <script>alert(1)</script> | source=x | params=y\n" );

$page = sfir_admin_request( $check_url );

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

$after = sfir_admin_request( $check_url );

SFIR_TestRunner::check(
	false !== strpos( $after['body'], 'The error log has been cleared.' ),
	'an admin notice confirms the log was cleared'
);

/* --------------------------------------------------------------------------
 * Documentation and local assets.
 * ----------------------------------------------------------------------- */

SFIR_TestRunner::group( '11.2.10 documentation and assets' );

$page = sfir_admin_request( $docs_url );

foreach ( array( 'sf_img(', 'sf_img_width(', 'sf_img_height(', 'sf_img_tag(', 'E07', 'crop', 'WebP fallback', 'How the URL is built', '{hash}' ) as $needle ) {
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
 * Tabs.
 * ----------------------------------------------------------------------- */

SFIR_TestRunner::group( '1.2.0 tabs' );

$page = sfir_admin_request( $page_url );

foreach ( array( 'Cache', 'Check and log', 'Documentation' ) as $label ) {
	SFIR_TestRunner::check(
		(bool) preg_match( '#<a href="[^"]*"\s*class="nav-tab[^"]*">\s*' . preg_quote( $label, '#' ) . '#', $page['body'] ),
		'the screen offers the "' . $label . '" tab'
	);
}

SFIR_TestRunner::check( false !== strpos( $page['body'], 'Cached files' ), 'the cache tab is the default' );
SFIR_TestRunner::check( false === strpos( $page['body'], 'Configuration check' ), 'the cache tab does not carry the check' );
SFIR_TestRunner::check( false === strpos( $page['body'], 'Error codes' ), 'the cache tab does not carry the documentation' );

$check_page = sfir_admin_request( $check_url );
SFIR_TestRunner::check( false !== strpos( $check_page['body'], 'Configuration check' ), 'the check tab carries the check' );
SFIR_TestRunner::check( false !== strpos( $check_page['body'], 'Error log' ), 'the check tab carries the log' );
SFIR_TestRunner::check( false === strpos( $check_page['body'], 'Cached files' ), 'the check tab does not carry the statistics' );

$docs_page = sfir_admin_request( $docs_url );
SFIR_TestRunner::check( false !== strpos( $docs_page['body'], 'Error codes' ), 'the documentation tab carries the documentation' );
SFIR_TestRunner::check( false === strpos( $docs_page['body'], 'Cached files' ), 'the documentation tab does not carry the statistics' );

$unknown = sfir_admin_request( $page_url . '&tab=nonsense' );
SFIR_TestRunner::check( false !== strpos( $unknown['body'], 'Cached files' ), 'an unknown tab falls back to the cache tab' );

/* --------------------------------------------------------------------------
 * The generation mode.
 * ----------------------------------------------------------------------- */

SFIR_TestRunner::group( '1.3.0 generation mode' );

delete_option( SFIR_Generator::MODE_OPTION );
sfir_forget_options( SFIR_Generator::MODE_OPTION );

$mode_page = sfir_admin_request( $check_url );

SFIR_TestRunner::check( false !== strpos( $mode_page['body'], 'When copies are produced' ), 'the check tab carries the generation setting' );
SFIR_TestRunner::check( false !== strpos( $mode_page['body'], 'name="sfir_mode"' ), 'the setting is a set of radio buttons' );
SFIR_TestRunner::check(
	(bool) preg_match( '#value="auto"[^>]*checked#', $mode_page['body'] ),
	'the automatic mode is selected on a fresh installation'
);
$cache_tab = sfir_admin_request( $page_url );
SFIR_TestRunner::check( false === strpos( $cache_tab['body'], 'When copies are produced' ), 'the setting lives on the check tab only' );

$mode_nonce = sfir_form_nonce( $mode_page['body'], 'sfir_generate_mode' );

SFIR_TestRunner::check( '' !== $mode_nonce, 'the setting form carries a nonce' );

// A POST without a nonce changes nothing.
$mode_unsigned = sfir_admin_request(
	$post_url,
	array(
		CURLOPT_POST       => true,
		CURLOPT_POSTFIELDS => http_build_query(
			array(
				'action'    => 'sfir_generate_mode',
				'sfir_mode' => 'always',
			)
		),
	)
);

sfir_forget_options( SFIR_Generator::MODE_OPTION );

SFIR_TestRunner::equals( 403, $mode_unsigned['status'], 'an unsigned POST is refused with 403' );
SFIR_TestRunner::equals( SFIR_Generator::MODE_AUTO, SFIR_Generator::get_mode(), 'the unsigned POST did not change the mode' );

// A signed POST does.
sfir_admin_request(
	$post_url,
	array(
		CURLOPT_POST       => true,
		CURLOPT_POSTFIELDS => http_build_query(
			array(
				'action'    => 'sfir_generate_mode',
				'_wpnonce'  => $mode_nonce,
				'sfir_mode' => 'always',
			)
		),
	)
);

sfir_forget_options( SFIR_Generator::MODE_OPTION );

SFIR_TestRunner::equals( SFIR_Generator::MODE_ALWAYS, SFIR_Generator::get_mode(), 'a signed POST stores the chosen mode' );

$saved_page = sfir_admin_request( $check_url );

SFIR_TestRunner::check( false !== strpos( $saved_page['body'], 'The generation mode has been saved.' ), 'a notice confirms the change' );
SFIR_TestRunner::check(
	(bool) preg_match( '#value="always"[^>]*checked#', $saved_page['body'] ),
	'the stored mode is the one selected on the screen'
);
SFIR_TestRunner::check(
	false !== strpos( $saved_page['body'], 'copies are produced while the page is rendered' ),
	'the screen states what is happening right now'
);

// A value that is not one of the three modes is refused.
$refuse_nonce = sfir_form_nonce( $saved_page['body'], 'sfir_generate_mode' );

sfir_admin_request(
	$post_url,
	array(
		CURLOPT_POST       => true,
		CURLOPT_POSTFIELDS => http_build_query(
			array(
				'action'    => 'sfir_generate_mode',
				'_wpnonce'  => $refuse_nonce,
				'sfir_mode' => 'whenever',
			)
		),
	)
);

sfir_forget_options( SFIR_Generator::MODE_OPTION );

SFIR_TestRunner::equals( SFIR_Generator::MODE_AUTO, SFIR_Generator::get_mode(), 'an unknown mode falls back to automatic' );

delete_option( SFIR_Generator::MODE_OPTION );
sfir_forget_options( SFIR_Generator::MODE_OPTION );

/* --------------------------------------------------------------------------
 * The language picker.
 * ----------------------------------------------------------------------- */

SFIR_TestRunner::group( '1.2.0 language' );

delete_user_meta( 1, SFIR_I18n::USER_META );

$page = sfir_admin_request( $page_url );

SFIR_TestRunner::check( false !== strpos( $page['body'], 'id="sfir-locale"' ), 'the screen carries a language picker' );

foreach ( array( 'ru_RU', 'es_ES', 'de_DE', 'fr_FR', 'it_IT', 'pt_BR', 'zh_CN' ) as $locale ) {
	SFIR_TestRunner::check(
		false !== strpos( $page['body'], 'value="' . $locale . '"' ),
		'the picker offers ' . $locale
	);
	SFIR_TestRunner::check(
		is_readable( SFIR_PLUGIN_DIR . 'languages/sf-image-resizer-' . $locale . '.mo' ),
		'a compiled translation ships for ' . $locale
	);
}

SFIR_TestRunner::check( false !== strpos( $page['body'], 'Cached files' ), 'the untranslated screen is English by default' );

$no_nonce = sfir_admin_request(
	$post_url,
	array(
		CURLOPT_POST       => true,
		CURLOPT_POSTFIELDS => 'action=sfir_language&sfir_locale=ru_RU',
	)
);

SFIR_TestRunner::equals( 403, $no_nonce['status'], 'a language change without a nonce is refused' );

$nonce = sfir_form_nonce( $page['body'], 'sfir_language' );

SFIR_TestRunner::check( '' !== $nonce, 'the language form carries a nonce' );

$switched = sfir_admin_request(
	$post_url,
	array(
		CURLOPT_POST       => true,
		CURLOPT_POSTFIELDS => http_build_query(
			array(
				'action'      => 'sfir_language',
				'_wpnonce'    => $nonce,
				'sfir_locale' => 'ru_RU',
				'sfir_tab'    => 'check',
			)
		),
	)
);

SFIR_TestRunner::equals( 302, $switched['status'], 'a signed language change redirects back' );
SFIR_TestRunner::check(
	false !== strpos( (string) ( isset( $switched['headers']['location'] ) ? $switched['headers']['location'] : '' ), 'tab=check' ),
	'the redirect returns to the tab the change was made on'
);

sfir_forget_user_meta( 1 );
SFIR_TestRunner::equals( 'ru_RU', get_user_meta( 1, SFIR_I18n::USER_META, true ), 'the choice is remembered for the user' );

$russian = sfir_admin_request( $page_url );

SFIR_TestRunner::check( false !== strpos( $russian['body'], 'Файлов в кэше' ), 'the screen is now in Russian' );
SFIR_TestRunner::check( false !== strpos( $russian['body'], 'Очистить кэш изображений' ), 'the buttons are translated too' );
SFIR_TestRunner::check( false === strpos( $russian['body'], 'Cached files' ), 'the English wording is gone' );

$russian_docs = sfir_admin_request( $docs_url );
SFIR_TestRunner::check( false !== strpos( $russian_docs['body'], 'Коды ошибок' ), 'the documentation is translated as well' );

// Another locale, to prove the picker is not hard wired to one language.
$nonce = sfir_form_nonce( $russian['body'], 'sfir_language' );

sfir_admin_request(
	$post_url,
	array(
		CURLOPT_POST       => true,
		CURLOPT_POSTFIELDS => http_build_query(
			array(
				'action'      => 'sfir_language',
				'_wpnonce'    => $nonce,
				'sfir_locale' => 'de_DE',
			)
		),
	)
);

$german = sfir_admin_request( $page_url );
SFIR_TestRunner::check( false !== strpos( $german['body'], 'Dateien im Cache' ), 'switching to German works' );

// Back to the site language.
$nonce = sfir_form_nonce( $german['body'], 'sfir_language' );

sfir_admin_request(
	$post_url,
	array(
		CURLOPT_POST       => true,
		CURLOPT_POSTFIELDS => http_build_query(
			array(
				'action'      => 'sfir_language',
				'_wpnonce'    => $nonce,
				'sfir_locale' => '',
			)
		),
	)
);

sfir_forget_user_meta( 1 );
SFIR_TestRunner::equals( '', (string) get_user_meta( 1, SFIR_I18n::USER_META, true ), 'choosing the site language clears the stored choice' );

$back = sfir_admin_request( $page_url );
SFIR_TestRunner::check( false !== strpos( $back['body'], 'Cached files' ), 'the screen follows the site language again' );

/* --------------------------------------------------------------------------
 * The Markdown reference and the plugins list link.
 * ----------------------------------------------------------------------- */

SFIR_TestRunner::group( '1.2.0 markdown reference' );

$docs_page = sfir_admin_request( $docs_url );

SFIR_TestRunner::check( false !== strpos( $docs_page['body'], 'id="sfir-markdown"' ), 'the documentation tab carries the Markdown field' );
SFIR_TestRunner::check( false !== strpos( $docs_page['body'], 'id="sfir-copy-markdown"' ), 'it has a copy button' );
SFIR_TestRunner::check( false !== strpos( $docs_page['body'], 'download="sf-image-resizer.md"' ), 'it has a download link' );
SFIR_TestRunner::check( false !== strpos( $docs_page['body'], 'sf_img_srcset' ), 'the reference mentions sf_img_srcset' );

$markdown_url = $base_url . '/wp-content/plugins/sf-image-resizer/docs/sf-image-resizer.md';
$markdown     = sfir_http( $markdown_url );

SFIR_TestRunner::equals( 200, $markdown['status'], 'the .md file is downloadable' );
SFIR_TestRunner::check( false !== strpos( $markdown['body'], 'sf_img_srcset' ), 'the downloaded file is the reference' );
// The admin class is only loaded on admin requests, so pull it in explicitly.
if ( ! class_exists( 'SFIR_Admin' ) ) {
	require_once WP_PLUGIN_DIR . '/sf-image-resizer/admin/class-sfir-admin.php';
}

SFIR_TestRunner::check(
	strlen( $markdown['body'] ) > 3000 && $markdown['body'] === SFIR_Admin::get_markdown(),
	'the download matches what the page shows'
);

SFIR_TestRunner::group( '1.2.0 plugins list' );

$plugins_page = sfir_admin_request( $base_url . '/wp-admin/plugins.php' );

SFIR_TestRunner::equals( 200, $plugins_page['status'], 'the plugins screen loads' );
SFIR_TestRunner::check(
	false !== strpos( $plugins_page['body'], 'options-general.php?page=sf-image-resizer' ),
	'the plugin row links to the settings screen'
);
SFIR_TestRunner::check(
	false !== strpos( $plugins_page['body'], 'SF Image resizer' ),
	'the plugin is listed under its new display name'
);

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
