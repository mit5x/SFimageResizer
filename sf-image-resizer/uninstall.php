<?php
/**
 * Removes every trace of the plugin when it is deleted.
 *
 * @package SFimageResizer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes a directory and everything below it, without following symlinks.
 *
 * @param string $dir  Directory to remove.
 * @param string $root Resolved boundary the directory has to stay inside.
 * @return void
 */
function sfir_uninstall_rmdir( $dir, $root ) {
	$real = realpath( $dir );

	if ( false === $real ) {
		return;
	}

	$real = str_replace( '\\', '/', $real );

	if ( $real !== $root && 0 !== strpos( $real, $root . '/' ) ) {
		return;
	}

	$entries = scandir( $real );

	if ( ! is_array( $entries ) ) {
		return;
	}

	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$path = $real . '/' . $entry;

		if ( is_link( $path ) || is_file( $path ) ) {
			wp_delete_file( $path );
			continue;
		}

		if ( is_dir( $path ) ) {
			sfir_uninstall_rmdir( $path, $root );
		}
	}

	@rmdir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

/**
 * Removes the plugin options, transients and working directory for one site.
 *
 * @return void
 */
function sfir_uninstall_site() {
	global $wpdb;

	delete_option( 'sfir_secret' );
	delete_option( 'sfir_logged_notices' );
	delete_option( 'sfir_version' );
	delete_option( 'sfir_pretty_urls_confirmed' );
	delete_option( 'sfir_generate_mode' );

	// Remove the per-administrator language choice for the plugin screen.
	delete_metadata( 'user', 0, 'sfir_admin_locale', '', true );

	// Remove the source-dimension and admin-notice transients.
	$like = $wpdb->esc_like( '_transient_sfir_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

	if ( is_array( $names ) ) {
		foreach ( $names as $name ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		}
	}

	$uploads = wp_get_upload_dir();

	if ( empty( $uploads['basedir'] ) ) {
		return;
	}

	$base = realpath( $uploads['basedir'] );

	if ( false === $base ) {
		return;
	}

	$base   = rtrim( str_replace( '\\', '/', $base ), '/' );
	$target = $base . '/SFimageResizer';

	if ( is_dir( $target ) ) {
		sfir_uninstall_rmdir( $target, $base );
	}
}

sfir_uninstall_site();
