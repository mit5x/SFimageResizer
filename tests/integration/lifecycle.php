<?php
/**
 * Activation, deactivation and uninstall tests (spec 11.2.13).
 *
 * This script leaves the installation with the plugin active and its working
 * directories rebuilt.
 *
 * Run through WP-CLI:
 *   wp eval-file tests/integration/lifecycle.php
 *
 * @package SFimageResizer
 */

require_once __DIR__ . '/lib.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

define( 'SFIR_TEST_BASE_URL', rtrim( home_url(), '/' ) );

$plugin_file = 'sf-image-resizer/sf-image-resizer.php';
$base_dir    = SFIR_Cache::get_base_dir();

/* --------------------------------------------------------------------------
 * Activation.
 * ----------------------------------------------------------------------- */

SFIR_TestRunner::group( '11.2.13 activation' );

// Wipe the working directory so that activation has to rebuild it.
if ( is_dir( $base_dir ) ) {
	foreach ( array_reverse( sfir_list_files( $base_dir ) ) as $file ) {
		unlink( $file );
	}

	$directories = array();
	$iterator    = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $entry ) {
		if ( $entry->isDir() ) {
			$directories[] = $entry->getPathname();
		}
	}

	foreach ( $directories as $directory ) {
		@rmdir( $directory );
	}

	@rmdir( $base_dir );
}

delete_option( SFIR_Security::SECRET_OPTION );
SFIR_Cache::flush_runtime_cache();

SFIR_TestRunner::check( ! is_dir( $base_dir ), 'the working directory is gone before activation' );

sfir_activate();

SFIR_TestRunner::check( is_dir( $base_dir . '/cache_images' ), 'activation creates the cache directory' );
SFIR_TestRunner::check( is_dir( $base_dir . '/logs' ), 'activation creates the logs directory' );

foreach ( array( 'cache_images', 'logs' ) as $directory ) {
	SFIR_TestRunner::check( file_exists( $base_dir . '/' . $directory . '/index.php' ), $directory . '/index.php is created' );
	SFIR_TestRunner::check( file_exists( $base_dir . '/' . $directory . '/.htaccess' ), $directory . '/.htaccess is created' );
}

$secret = get_option( SFIR_Security::SECRET_OPTION );

SFIR_TestRunner::check( is_string( $secret ) && strlen( $secret ) >= 32, 'activation creates a signing secret' );

$rules = get_option( 'rewrite_rules' );

SFIR_TestRunner::check(
	! is_array( $rules ) || ! isset( $rules['^sfir-generate/?$'] ),
	'activation registers no rewrite rule for the retired endpoint'
);

$htaccess = (string) file_get_contents( $base_dir . '/cache_images/.htaccess' );

SFIR_TestRunner::check(
	false !== strpos( $htaccess, 'RewriteCond %{REQUEST_FILENAME} !-f' ) && false !== strpos( $htaccess, 'index.php' ),
	'the cache .htaccess routes missing files to WordPress'
);

SFIR_TestRunner::check(
	false !== strpos( $htaccess, 'Options -Indexes' ),
	'the cache .htaccess still forbids directory listing'
);

SFIR_TestRunner::equals( SFIR_VERSION, get_option( 'sfir_version' ), 'activation records the plugin version' );

/* --------------------------------------------------------------------------
 * Deactivation keeps everything.
 * ----------------------------------------------------------------------- */

SFIR_TestRunner::group( '11.2.13 deactivation' );

// Produce one cached file so we can prove deactivation keeps user data.
$fixtures = sfir_build_fixtures();
$jpeg     = sfir_path_to_url( $fixtures['jpeg'] );

$resolved = SFIR_Core::resolve_source( $jpeg );
$params   = SFIR_Core::apply_format_support( SFIR_Core::parse_params( 'w=120&f=jpg' ) );
$size     = SFIR_Cache::get_source_size( $resolved['path'], $resolved['id'] );
$geometry = SFIR_Core::calculate_dimensions( $size['width'], $size['height'], $params );
$cached   = SFIR_Cache::get_cache_dir() . '/' . SFIR_Core::build_cache_filename( 'landscape.jpg', $params );

SFIR_Resizer::generate( $resolved['path'], $size['mime'], $cached, $params, $geometry );

SFIR_TestRunner::check( file_exists( $cached ), 'a cached file exists before deactivation' );

deactivate_plugins( $plugin_file );

SFIR_TestRunner::check( ! is_plugin_active( $plugin_file ), 'the plugin is deactivated' );
SFIR_TestRunner::check( is_dir( $base_dir ), 'deactivation keeps the working directory' );
SFIR_TestRunner::check( file_exists( $cached ), 'deactivation keeps the cached files' );
SFIR_TestRunner::check( '' !== (string) get_option( SFIR_Security::SECRET_OPTION ), 'deactivation keeps the options' );

activate_plugin( $plugin_file );

SFIR_TestRunner::check( is_plugin_active( $plugin_file ), 'the plugin can be activated again' );

/* --------------------------------------------------------------------------
 * Uninstall removes everything.
 * ----------------------------------------------------------------------- */

SFIR_TestRunner::group( '11.2.13 uninstall' );

set_transient( 'sfir_size_' . md5( 'lifecycle-probe' ), array( 'mtime' => 1 ), HOUR_IN_SECONDS );
update_option( SFIR_Logger::NOTICE_OPTION, array( 'probe' => 1 ), false );

SFIR_TestRunner::check(
	false !== get_transient( 'sfir_size_' . md5( 'lifecycle-probe' ) ),
	'a plugin transient exists before uninstall'
);

define( 'WP_UNINSTALL_PLUGIN', $plugin_file );

require WP_PLUGIN_DIR . '/sf-image-resizer/uninstall.php';

clearstatcache();

SFIR_TestRunner::check( ! is_dir( $base_dir ), 'uninstall removes the whole working directory', $base_dir );
SFIR_TestRunner::check( false === get_option( SFIR_Security::SECRET_OPTION, false ), 'uninstall removes the secret option' );
SFIR_TestRunner::check( false === get_option( SFIR_Logger::NOTICE_OPTION, false ), 'uninstall removes the notice option' );
SFIR_TestRunner::check(
	false === get_transient( 'sfir_size_' . md5( 'lifecycle-probe' ) ),
	'uninstall removes the plugin transients'
);
SFIR_TestRunner::check( file_exists( $fixtures['jpeg'] ), 'uninstall keeps the media library files' );
SFIR_TestRunner::check( is_dir( wp_get_upload_dir()['basedir'] ), 'uninstall keeps the uploads directory' );

/* --------------------------------------------------------------------------
 * Put the installation back together for any later run.
 * ----------------------------------------------------------------------- */

SFIR_Cache::flush_runtime_cache();
sfir_activate();

SFIR_TestRunner::check( is_dir( $base_dir . '/cache_images' ), 'the environment is restored for later runs' );

exit( SFIR_TestRunner::summary() );
