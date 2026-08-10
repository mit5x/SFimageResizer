<?php
/**
 * Error logging.
 *
 * @package SFimageResizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes one line per problem into the plugin log file.
 *
 * The logger never interrupts a page render: if the log directory cannot be
 * created or written to, the message is silently dropped.
 */
class SFIR_Logger {

	/**
	 * Maximum log size in bytes before the file is truncated.
	 */
	const MAX_SIZE = 2097152;

	/**
	 * Number of trailing lines kept when the log is rotated.
	 */
	const KEEP_LINES = 500;

	/**
	 * Option name holding the flags of the notices that were already logged once.
	 */
	const NOTICE_OPTION = 'sfir_logged_notices';

	/**
	 * Returns the absolute path of the log file.
	 *
	 * @return string Empty string when the uploads directory is unavailable.
	 */
	public static function get_log_file() {
		$dir = SFIR_Cache::get_logs_dir();

		if ( '' === $dir ) {
			return '';
		}

		return $dir . '/error.log';
	}

	/**
	 * Writes one entry into the log.
	 *
	 * @param string $code    Error code, for example "E01".
	 * @param string $message Human readable message. Must not contain secrets.
	 * @param mixed  $source  Source that was being processed.
	 * @param mixed  $params  Parameters that were requested.
	 * @return void
	 */
	public static function log( $code, $message, $source = '', $params = '' ) {
		$file = self::get_log_file();

		if ( '' === $file ) {
			return;
		}

		if ( ! SFIR_Cache::prepare_directories() ) {
			return;
		}

		$line = sprintf(
			"[%s] [%s] %s | source=%s | params=%s\n",
			gmdate( 'Y-m-d H:i:s' ),
			self::clean( $code ),
			self::clean( $message ),
			self::clean( SFIR_Core::describe_source( $source ) ),
			self::clean( is_array( $params ) ? SFIR_Core::params_to_string( $params ) : (string) $params )
		);

		self::rotate( $file );

		// Direct file writes: the log is an append-only diagnostic file that has to
		// keep working when WP_Filesystem is not initialised (front end requests).
		@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Logs a notice at most once for the lifetime of the installation.
	 *
	 * @param string $key     Unique key of the notice.
	 * @param string $message Message to write.
	 * @return void
	 */
	public static function notice_once( $key, $message ) {
		$logged = get_option( self::NOTICE_OPTION, array() );

		if ( ! is_array( $logged ) ) {
			$logged = array();
		}

		if ( isset( $logged[ $key ] ) ) {
			return;
		}

		$logged[ $key ] = 1;
		update_option( self::NOTICE_OPTION, $logged, false );

		self::log( 'notice', $message );
	}

	/**
	 * Reads the first lines of the log.
	 *
	 * @param int $limit Maximum number of lines to return.
	 * @return string
	 */
	public static function read( $limit = 50 ) {
		$file = self::get_log_file();

		if ( '' === $file || ! is_readable( $file ) ) {
			return '';
		}

		$handle = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			return '';
		}

		$lines = array();
		$count = 0;

		while ( $count < $limit && ! feof( $handle ) ) {
			$line = fgets( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets

			if ( false === $line ) {
				break;
			}

			$lines[] = rtrim( $line, "\r\n" );
			++$count;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return implode( "\n", $lines );
	}

	/**
	 * Empties the log file.
	 *
	 * @return bool True when the file is gone or empty afterwards.
	 */
	public static function clear() {
		$file = self::get_log_file();

		if ( '' === $file ) {
			return false;
		}

		if ( ! file_exists( $file ) ) {
			return true;
		}

		return false !== @file_put_contents( $file, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Truncates the log when it grows past the size limit.
	 *
	 * @param string $file Absolute path to the log file.
	 * @return void
	 */
	protected static function rotate( $file ) {
		if ( ! file_exists( $file ) ) {
			return;
		}

		$size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $size || $size <= self::MAX_SIZE ) {
			return;
		}

		$handle = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			return;
		}

		// Read the tail of the file only, so that rotation stays cheap.
		$chunk = 512000;
		$start = max( 0, $size - $chunk );
		fseek( $handle, $start );
		$tail = stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( ! is_string( $tail ) ) {
			return;
		}

		$lines = explode( "\n", $tail );

		if ( $start > 0 ) {
			array_shift( $lines );
		}

		$lines = array_slice( $lines, -self::KEEP_LINES );
		$kept  = rtrim( implode( "\n", $lines ), "\n" ) . "\n";

		@file_put_contents( $file, $kept, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Strips control characters so that one entry stays on one line.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	protected static function clean( $value ) {
		$value = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $value );

		if ( strlen( $value ) > 500 ) {
			$value = substr( $value, 0, 500 ) . '...';
		}

		return $value;
	}
}
