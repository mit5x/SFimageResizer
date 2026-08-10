<?php
/**
 * Cache directories, cached file lookups and cached source dimensions.
 *
 * @package SFimageResizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Disk cache for generated images and transient cache for source dimensions.
 */
class SFIR_Cache {

	/**
	 * Name of the plugin directory inside uploads.
	 */
	const BASE_DIRNAME = 'SFimageResizer';

	/**
	 * Prefix of the transients holding source dimensions.
	 */
	const SIZE_TRANSIENT_PREFIX = 'sfir_size_';

	/**
	 * Whether the working directories were already prepared during this request.
	 *
	 * @var bool|null
	 */
	protected static $prepared = null;

	/**
	 * In-request memo of source dimensions, keyed by absolute path.
	 *
	 * @var array
	 */
	protected static $size_memo = array();

	/**
	 * In-request memo of prepared images, keyed by source and parameters.
	 *
	 * @var array
	 */
	protected static $prepare_memo = array();

	/**
	 * Reads an entry from the prepared-image memo.
	 *
	 * @param string $key Memo key.
	 * @return array|null
	 */
	public static function get_memo( $key ) {
		return isset( self::$prepare_memo[ $key ] ) ? self::$prepare_memo[ $key ] : null;
	}

	/**
	 * Writes an entry into the prepared-image memo.
	 *
	 * @param string $key   Memo key.
	 * @param array  $value Value to remember.
	 * @return void
	 */
	public static function set_memo( $key, array $value ) {
		self::$prepare_memo[ $key ] = $value;
	}

	/**
	 * Drops everything memoised for the current request.
	 *
	 * Useful in long running processes such as WP-CLI, where files can change
	 * between two calls inside the same PHP process.
	 *
	 * @return void
	 */
	public static function flush_runtime_cache() {
		self::$size_memo    = array();
		self::$prepare_memo = array();
		self::$prepared     = null;
	}

	/**
	 * Returns the base directory of the plugin inside uploads.
	 *
	 * @return string Absolute path without trailing slash, empty on failure.
	 */
	public static function get_base_dir() {
		$uploads = wp_get_upload_dir();

		if ( empty( $uploads['basedir'] ) ) {
			return '';
		}

		return rtrim( str_replace( '\\', '/', $uploads['basedir'] ), '/' ) . '/' . self::BASE_DIRNAME;
	}

	/**
	 * Returns the base URL of the plugin directory inside uploads.
	 *
	 * @return string URL without trailing slash, empty on failure.
	 */
	public static function get_base_url() {
		$uploads = wp_get_upload_dir();

		if ( empty( $uploads['baseurl'] ) ) {
			return '';
		}

		return rtrim( $uploads['baseurl'], '/' ) . '/' . self::BASE_DIRNAME;
	}

	/**
	 * Returns the directory holding cached images.
	 *
	 * @return string Absolute path without trailing slash, empty on failure.
	 */
	public static function get_cache_dir() {
		$base = self::get_base_dir();

		return '' === $base ? '' : $base . '/cache_images';
	}

	/**
	 * Returns the URL of the directory holding cached images.
	 *
	 * @return string URL without trailing slash, empty on failure.
	 */
	public static function get_cache_url() {
		$base = self::get_base_url();

		return '' === $base ? '' : $base . '/cache_images';
	}

	/**
	 * Returns the directory holding the log file.
	 *
	 * @return string Absolute path without trailing slash, empty on failure.
	 */
	public static function get_logs_dir() {
		$base = self::get_base_dir();

		return '' === $base ? '' : $base . '/logs';
	}

	/**
	 * Creates the working directories and their protection files.
	 *
	 * @return bool True when both directories exist and are writable.
	 */
	public static function prepare_directories() {
		if ( null !== self::$prepared ) {
			return self::$prepared;
		}

		$cache = self::get_cache_dir();
		$logs  = self::get_logs_dir();

		if ( '' === $cache || '' === $logs ) {
			self::$prepared = false;
			return false;
		}

		$ok = self::make_dir( $cache ) && self::make_dir( $logs );

		if ( $ok ) {
			self::write_protection_file( $cache . '/index.php', "<?php\n// Silence is golden.\n" );
			self::write_protection_file( $cache . '/.htaccess', self::get_cache_htaccess() );
			self::write_protection_file( $logs . '/index.php', "<?php\n// Silence is golden.\n" );
			self::write_protection_file( $logs . '/.htaccess', self::get_logs_htaccess() );
		}

		self::$prepared = $ok;

		return $ok;
	}

	/**
	 * Creates a directory, including missing parents.
	 *
	 * @param string $dir Absolute path.
	 * @return bool
	 */
	public static function make_dir( $dir ) {
		if ( is_dir( $dir ) ) {
			return wp_is_writable( $dir );
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		return wp_is_writable( $dir );
	}

	/**
	 * Writes a protection file if it is not there yet.
	 *
	 * @param string $path     Absolute path of the file.
	 * @param string $contents File contents.
	 * @return void
	 */
	protected static function write_protection_file( $path, $contents ) {
		if ( file_exists( $path ) ) {
			return;
		}

		// Direct write: protection files are created before WP_Filesystem is
		// available on front end requests.
		@file_put_contents( $path, $contents ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		if ( file_exists( $path ) ) {
			@chmod( $path, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		}
	}

	/**
	 * Returns the .htaccess body used for the cache directory.
	 *
	 * @return string
	 */
	protected static function get_cache_htaccess() {
		return "Options -Indexes\n"
			. "<IfModule mod_php7.c>\nphp_flag engine off\n</IfModule>\n"
			. "<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n";
	}

	/**
	 * Returns the .htaccess body used for the logs directory.
	 *
	 * @return string
	 */
	protected static function get_logs_htaccess() {
		return "Options -Indexes\n"
			. "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
	}

	/**
	 * Builds the absolute path of a cache file.
	 *
	 * @param string $relative_dir Relative directory inside the cache root.
	 * @param string $filename     Cache file name.
	 * @return string Empty string when the cache root is unavailable.
	 */
	public static function get_file_path( $relative_dir, $filename ) {
		$root = self::get_cache_dir();

		if ( '' === $root ) {
			return '';
		}

		$dir = '' === $relative_dir ? $root : $root . '/' . $relative_dir;

		return $dir . '/' . $filename;
	}

	/**
	 * Builds the URL of a cache file.
	 *
	 * @param string $relative_dir Relative directory inside the cache root.
	 * @param string $filename     Cache file name.
	 * @return string Empty string when the cache root is unavailable.
	 */
	public static function get_file_url( $relative_dir, $filename ) {
		$root = self::get_cache_url();

		if ( '' === $root ) {
			return '';
		}

		$parts = array();
		foreach ( explode( '/', $relative_dir ) as $part ) {
			if ( '' !== $part ) {
				$parts[] = rawurlencode( $part );
			}
		}
		$parts[] = rawurlencode( $filename );

		return $root . '/' . implode( '/', $parts );
	}

	/**
	 * Tells whether a cache file exists and is not older than its source.
	 *
	 * @param string $cache_path  Absolute path of the cache file.
	 * @param string $source_path Absolute path of the source file.
	 * @return bool
	 */
	public static function is_fresh( $cache_path, $source_path ) {
		if ( '' === $cache_path || ! is_file( $cache_path ) ) {
			return false;
		}

		$cache_time  = @filemtime( $cache_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$source_time = @filemtime( $source_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $cache_time || ! $source_time ) {
			return false;
		}

		return $cache_time >= $source_time;
	}

	/**
	 * Returns the pixel dimensions and MIME type of a source file.
	 *
	 * The value is memoised for the request and cached in a transient keyed by
	 * the file path. The entry is discarded as soon as the file modification
	 * time changes.
	 *
	 * @param string $path          Absolute path of the source file.
	 * @param int    $attachment_id Attachment ID when known.
	 * @return array {
	 *     Source description.
	 *
	 *     @type bool   $ok     Whether the file is a supported image.
	 *     @type string $error  Error code when $ok is false.
	 *     @type string $mime   MIME type.
	 *     @type int    $width  Width in pixels.
	 *     @type int    $height Height in pixels.
	 * }
	 */
	public static function get_source_size( $path, $attachment_id = 0 ) {
		if ( isset( self::$size_memo[ $path ] ) ) {
			return self::$size_memo[ $path ];
		}

		$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $mtime ) {
			$result = array(
				'ok'     => false,
				'error'  => 'E01',
				'mime'   => '',
				'width'  => 0,
				'height' => 0,
			);

			self::$size_memo[ $path ] = $result;

			return $result;
		}

		$key    = self::SIZE_TRANSIENT_PREFIX . md5( $path );
		$cached = get_transient( $key );

		if ( is_array( $cached ) && isset( $cached['mtime'], $cached['width'], $cached['height'], $cached['mime'] ) && (int) $cached['mtime'] === (int) $mtime ) {
			$result = array(
				'ok'     => (int) $cached['width'] > 0 && (int) $cached['height'] > 0 && '' !== $cached['mime'],
				'error'  => '' === $cached['mime'] ? 'E02' : '',
				'mime'   => (string) $cached['mime'],
				'width'  => (int) $cached['width'],
				'height' => (int) $cached['height'],
			);

			self::$size_memo[ $path ] = $result;

			return $result;
		}

		$result = self::probe_source( $path, $attachment_id );

		set_transient(
			$key,
			array(
				'mtime'  => (int) $mtime,
				'width'  => $result['width'],
				'height' => $result['height'],
				'mime'   => $result['mime'],
			),
			WEEK_IN_SECONDS
		);

		self::$size_memo[ $path ] = $result;

		return $result;
	}

	/**
	 * Reads the dimensions of a source file, preferring attachment metadata.
	 *
	 * @param string $path          Absolute path of the source file.
	 * @param int    $attachment_id Attachment ID when known.
	 * @return array See get_source_size().
	 */
	protected static function probe_source( $path, $attachment_id = 0 ) {
		if ( $attachment_id > 0 ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
			$mime = get_post_mime_type( $attachment_id );

			if ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] )
				&& is_string( $mime ) && in_array( strtolower( $mime ), SFIR_Security::get_supported_input_types(), true )
			) {
				return array(
					'ok'     => true,
					'error'  => '',
					'mime'   => strtolower( $mime ),
					'width'  => (int) $meta['width'],
					'height' => (int) $meta['height'],
				);
			}
		}

		return SFIR_Security::inspect_image( $path );
	}

	/**
	 * Drops the cached dimensions of a source file.
	 *
	 * @param string $path Absolute path of the source file.
	 * @return void
	 */
	public static function forget_source_size( $path ) {
		unset( self::$size_memo[ $path ] );
		delete_transient( self::SIZE_TRANSIENT_PREFIX . md5( $path ) );
	}

	/**
	 * Counts cached files and their total size.
	 *
	 * @param int $time_limit Maximum number of seconds to spend walking the cache.
	 * @return array {
	 *     Cache statistics.
	 *
	 *     @type int  $files       Number of files found.
	 *     @type int  $bytes       Total size in bytes.
	 *     @type bool $approximate Whether the walk was cut short by the time limit.
	 * }
	 */
	public static function get_stats( $time_limit = 5 ) {
		$stats = array(
			'files'       => 0,
			'bytes'       => 0,
			'approximate' => false,
		);

		$root = self::get_cache_dir();

		if ( '' === $root || ! is_dir( $root ) ) {
			return $stats;
		}

		$deadline = microtime( true ) + (float) $time_limit;

		foreach ( self::walk( $root ) as $file ) {
			if ( microtime( true ) > $deadline ) {
				$stats['approximate'] = true;
				break;
			}

			if ( self::is_protection_file( $file ) || self::is_lock_file( $file ) ) {
				continue;
			}

			++$stats['files'];
			$stats['bytes'] += (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		return $stats;
	}

	/**
	 * Deletes every cached image.
	 *
	 * Each path is re-checked against the cache root before it is removed.
	 *
	 * @return int Number of deleted files.
	 */
	public static function clear() {
		$root = self::get_cache_dir();

		if ( '' === $root || ! is_dir( $root ) ) {
			return 0;
		}

		$root_real = realpath( $root );

		if ( false === $root_real ) {
			return 0;
		}

		$root_real = str_replace( '\\', '/', $root_real );
		$deleted   = 0;

		foreach ( self::walk( $root ) as $file ) {
			if ( self::is_protection_file( $file ) ) {
				continue;
			}

			$real = realpath( $file );

			if ( false === $real ) {
				continue;
			}

			$real = str_replace( '\\', '/', $real );

			if ( ! SFIR_Core::is_path_within( $real, $root_real ) ) {
				continue;
			}

			// Stale lock files are removed too, but they are not cached images
			// and must not show up in the "deleted" count.
			$is_lock = self::is_lock_file( $real );

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			if ( @unlink( $real ) && ! $is_lock ) {
				++$deleted;
			}
		}

		self::remove_empty_dirs( $root, $root_real );

		return $deleted;
	}

	/**
	 * Removes empty sub-directories of the cache root.
	 *
	 * @param string $dir       Directory to clean.
	 * @param string $root_real Resolved cache root, used as a safety boundary.
	 * @return void
	 */
	protected static function remove_empty_dirs( $dir, $root_real ) {
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $entries ) ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $dir . '/' . $entry;

			if ( ! is_dir( $path ) || is_link( $path ) ) {
				continue;
			}

			$real = realpath( $path );

			if ( false === $real || ! SFIR_Core::is_path_within( str_replace( '\\', '/', $real ), $root_real ) ) {
				continue;
			}

			self::remove_empty_dirs( $path, $root_real );

			$left = @scandir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( is_array( $left ) && 2 === count( $left ) ) {
				@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
		}
	}

	/**
	 * Yields every file below a directory, without following symlinks.
	 *
	 * @param string $dir Directory to walk.
	 * @return Generator
	 */
	protected static function walk( $dir ) {
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $entries ) ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $dir . '/' . $entry;

			if ( is_link( $path ) ) {
				continue;
			}

			if ( is_dir( $path ) ) {
				foreach ( self::walk( $path ) as $nested ) {
					yield $nested;
				}
				continue;
			}

			yield $path;
		}
	}

	/**
	 * Tells whether a file is one of the directory protection files.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	protected static function is_protection_file( $path ) {
		$name = basename( $path );

		return 'index.php' === $name || '.htaccess' === $name;
	}

	/**
	 * Tells whether a file is a generation lock rather than a cached image.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	protected static function is_lock_file( $path ) {
		return '.lock' === substr( $path, -5 );
	}
}
