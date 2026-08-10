<?php
/**
 * Admin screen: cache statistics, maintenance actions and documentation.
 *
 * @package SFimageResizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the plugin screen under Settings.
 */
class SFIR_Admin {

	/**
	 * Slug of the settings page.
	 */
	const PAGE_SLUG = 'sf-image-resizer';

	/**
	 * Capability required to see and use the page.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Prefix of the transient carrying a one-off admin notice.
	 */
	const NOTICE_TRANSIENT_PREFIX = 'sfir_admin_notice_';

	/**
	 * Hooks the admin screen up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_sfir_clear_cache', array( __CLASS__, 'handle_clear_cache' ) );
		add_action( 'admin_post_sfir_clear_log', array( __CLASS__, 'handle_clear_log' ) );
		add_action( 'admin_post_sfir_recheck', array( __CLASS__, 'handle_recheck' ) );
	}

	/**
	 * Adds the page under the Settings menu.
	 *
	 * @return void
	 */
	public static function register_page() {
		add_options_page(
			__( 'SFimageResizer', 'sf-image-resizer' ),
			__( 'SFimageResizer', 'sf-image-resizer' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Loads the local stylesheet and script on the plugin screen only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'sfir-admin',
			SFIR_PLUGIN_URL . 'admin/assets/css/admin.css',
			array(),
			SFIR_VERSION
		);

		wp_enqueue_script(
			'sfir-admin',
			SFIR_PLUGIN_URL . 'admin/assets/js/admin.js',
			array(),
			SFIR_VERSION,
			true
		);

		// The browser check only runs while nothing has confirmed pretty URLs yet.
		$probe = SFIR_Diagnostics::get_confirmed_at() > 0 ? '' : SFIR_Diagnostics::get_probe_url();

		wp_localize_script(
			'sfir-admin',
			'sfirAdmin',
			array(
				'confirmCache' => __( 'Delete every cached image? They will be regenerated on demand.', 'sf-image-resizer' ),
				'confirmLog'   => __( 'Clear the error log?', 'sf-image-resizer' ),
				'probeUrl'     => $probe,
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'ajaxAction'   => SFIR_Diagnostics::AJAX_ACTION,
				'nonce'        => wp_create_nonce( SFIR_Diagnostics::AJAX_ACTION ),
				'successTitle' => __( 'Pretty URLs are working.', 'sf-image-resizer' ),
				'successText'  => __( 'Checked from your browser just now.', 'sf-image-resizer' ),
			)
		);
	}

	/**
	 * Handles the "clear cache" form submission.
	 *
	 * @return void
	 */
	public static function handle_clear_cache() {
		self::verify_request( 'sfir_clear_cache' );

		$deleted = SFIR_Cache::clear();

		self::set_notice(
			'success',
			sprintf(
				/* translators: %d: number of deleted files. */
				_n( '%d cached file deleted.', '%d cached files deleted.', $deleted, 'sf-image-resizer' ),
				(int) $deleted
			)
		);

		self::redirect_back();
	}

	/**
	 * Handles the "clear log" form submission.
	 *
	 * @return void
	 */
	public static function handle_clear_log() {
		self::verify_request( 'sfir_clear_log' );

		$cleared = SFIR_Logger::clear();

		self::set_notice(
			$cleared ? 'success' : 'error',
			$cleared
				? __( 'The error log has been cleared.', 'sf-image-resizer' )
				: __( 'The error log could not be cleared.', 'sf-image-resizer' )
		);

		self::redirect_back();
	}

	/**
	 * Handles the "check again" form submission.
	 *
	 * @return void
	 */
	public static function handle_recheck() {
		self::verify_request( 'sfir_recheck' );

		SFIR_Diagnostics::reset();
		SFIR_Diagnostics::cleanup_probe_files();

		self::set_notice( 'success', __( 'The check was reset and will run again from your browser.', 'sf-image-resizer' ) );

		self::redirect_back();
	}

	/**
	 * Rejects the request unless it is a signed POST from an allowed user.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	protected static function verify_request( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'sf-image-resizer' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Stores a one-off notice for the current user.
	 *
	 * @param string $type    Notice type, "success" or "error".
	 * @param string $message Message to display.
	 * @return void
	 */
	protected static function set_notice( $type, $message ) {
		set_transient(
			self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Sends the user back to the plugin screen.
	 *
	 * @return void
	 */
	protected static function redirect_back() {
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Prints and clears the pending notice.
	 *
	 * @return void
	 */
	protected static function render_notice() {
		$key    = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			'error' === $notice['type'] ? 'error' : 'success',
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Renders the plugin screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'sf-image-resizer' ), '', array( 'response' => 403 ) );
		}

		$stats  = SFIR_Cache::get_stats();
		$log    = SFIR_Logger::read( 50 );
		$action = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<div class="wrap sfir-wrap">
			<h1><?php esc_html_e( 'SFimageResizer', 'sf-image-resizer' ); ?></h1>
			<?php self::render_notice(); ?>

			<h2><?php esc_html_e( 'Cache', 'sf-image-resizer' ); ?></h2>
			<table class="widefat striped sfir-stats">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cached files', 'sf-image-resizer' ); ?></th>
						<td>
							<?php
							echo esc_html( number_format_i18n( $stats['files'] ) );
							if ( $stats['approximate'] ) {
								echo ' ' . esc_html__( '(approximate)', 'sf-image-resizer' );
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Total size', 'sf-image-resizer' ); ?></th>
						<td>
							<?php
							/* translators: %s: cache size in megabytes. */
							printf( esc_html__( '%s MB', 'sf-image-resizer' ), esc_html( number_format_i18n( $stats['bytes'] / 1048576, 2 ) ) );
							if ( $stats['approximate'] ) {
								echo ' ' . esc_html__( '(approximate)', 'sf-image-resizer' );
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cache directory', 'sf-image-resizer' ); ?></th>
						<td><code><?php echo esc_html( SFIR_Cache::get_cache_dir() ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'WebP output', 'sf-image-resizer' ); ?></th>
						<td>
							<?php
							echo SFIR_Resizer::supports_webp()
								? esc_html__( 'Supported by this server.', 'sf-image-resizer' )
								: esc_html__( 'Not available: this GD build has no WebP support, JPG is produced instead.', 'sf-image-resizer' );
							?>
						</td>
					</tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>" class="sfir-form">
				<?php wp_nonce_field( 'sfir_clear_cache' ); ?>
				<input type="hidden" name="action" value="sfir_clear_cache" />
				<?php submit_button( __( 'Clear image cache', 'sf-image-resizer' ), 'secondary', 'sfir-clear-cache', false ); ?>
			</form>

			<?php self::render_diagnostics( $action ); ?>

			<h2><?php esc_html_e( 'Error log', 'sf-image-resizer' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'The 50 first lines of the log file are shown below.', 'sf-image-resizer' ); ?>
				<code><?php echo esc_html( SFIR_Logger::get_log_file() ); ?></code>
			</p>
			<textarea class="sfir-log" readonly="readonly" rows="12" spellcheck="false"><?php echo esc_textarea( '' === $log ? __( 'The log is empty.', 'sf-image-resizer' ) : $log ); ?></textarea>

			<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>" class="sfir-form">
				<?php wp_nonce_field( 'sfir_clear_log' ); ?>
				<input type="hidden" name="action" value="sfir_clear_log" />
				<?php submit_button( __( 'Clear log', 'sf-image-resizer' ), 'secondary', 'sfir-clear-log', false ); ?>
			</form>

			<?php self::render_documentation(); ?>
		</div>
		<?php
	}

	/**
	 * Renders the configuration self-check.
	 *
	 * @param string $action URL of admin-post.php, already escaped.
	 * @return void
	 */
	protected static function render_diagnostics( $action ) {
		$confirmed = SFIR_Diagnostics::get_confirmed_at();

		// Clean up after any earlier browser check before starting a new one.
		SFIR_Diagnostics::cleanup_probe_files();
		?>
		<h2><?php esc_html_e( 'Configuration check', 'sf-image-resizer' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Image URLs point straight at the cache file. While that file does not exist yet, the web server has to hand the request to WordPress so the plugin can create it.', 'sf-image-resizer' ); ?>
		</p>

		<div id="sfir-check">
			<?php if ( $confirmed > 0 ) : ?>
				<div class="notice notice-success inline sfir-check"><p>
					<strong><?php esc_html_e( 'Pretty URLs are working.', 'sf-image-resizer' ); ?></strong>
					<?php
					printf(
						/* translators: %s: date and time of the last confirmation. */
						esc_html__( 'Confirmed by a real request on %s.', 'sf-image-resizer' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $confirmed ) )
					);
					?>
				</p></div>
			<?php else : ?>
				<div class="notice notice-warning inline sfir-check"><p>
					<strong><?php esc_html_e( 'Not confirmed automatically yet.', 'sf-image-resizer' ); ?></strong>
					<?php esc_html_e( 'This is often caused by the hosting bot protection and does not mean anything is broken. Check it yourself: open the URL of any size that has not been generated yet in your browser. If the image appears, everything works.', 'sf-image-resizer' ); ?>
				</p></div>

				<details class="sfir-hints">
					<summary><?php esc_html_e( 'If images really are not being created', 'sf-image-resizer' ); ?></summary>
					<p><?php esc_html_e( 'On nginx, add this to the server configuration and reload it. Your host can apply it for you:', 'sf-image-resizer' ); ?></p>
					<pre class="sfir-code"><code><?php echo esc_html( SFIR_Cache::get_nginx_snippet() ); ?></code></pre>
					<p><?php esc_html_e( 'On Apache, make sure .htaccess files are honoured (AllowOverride All) for the uploads directory.', 'sf-image-resizer' ); ?></p>
				</details>
			<?php endif; ?>
		</div>

		<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?>" class="sfir-form">
			<?php wp_nonce_field( 'sfir_recheck' ); ?>
			<input type="hidden" name="action" value="sfir_recheck" />
			<?php submit_button( __( 'Check again', 'sf-image-resizer' ), 'secondary', 'sfir-recheck', false ); ?>
		</form>
		<?php
	}

	/**
	 * Renders the built-in documentation.
	 *
	 * @return void
	 */
	protected static function render_documentation() {
		?>
		<h2><?php esc_html_e( 'Documentation', 'sf-image-resizer' ); ?></h2>

		<p><?php esc_html_e( 'Call these functions from your theme templates. They never throw and never break a page: on error they return an SVG placeholder and write one line to the log.', 'sf-image-resizer' ); ?></p>

		<h3><?php esc_html_e( 'Functions', 'sf-image-resizer' ); ?></h3>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Function', 'sf-image-resizer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'sf-image-resizer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>sf_img( $source, $params = '' )</code></td>
					<td><?php esc_html_e( 'Returns the URL of the resized copy. Output it with esc_url().', 'sf-image-resizer' ); ?></td>
				</tr>
				<tr>
					<td><code>sf_img_width( $source, $params = '' )</code></td>
					<td><?php esc_html_e( 'Returns the width the copy will have, calculated without generating it.', 'sf-image-resizer' ); ?></td>
				</tr>
				<tr>
					<td><code>sf_img_height( $source, $params = '' )</code></td>
					<td><?php esc_html_e( 'Returns the matching height.', 'sf-image-resizer' ); ?></td>
				</tr>
				<tr>
					<td><code>sf_img_tag( $source, $params = '', $attrs = [] )</code></td>
					<td><?php esc_html_e( 'Returns a complete img tag with src, width, height, loading="lazy" and decoding="async". Attributes in $attrs override the defaults.', 'sf-image-resizer' ); ?></td>
				</tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Sources', 'sf-image-resizer' ); ?></h3>
		<p><?php esc_html_e( '$source accepts a URL belonging to this site, an attachment ID, or an ACF image array. Anything else, including URLs on other hosts, produces a placeholder.', 'sf-image-resizer' ); ?></p>

		<h3><?php esc_html_e( 'Parameters', 'sf-image-resizer' ); ?></h3>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Parameter', 'sf-image-resizer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Values', 'sf-image-resizer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Default', 'sf-image-resizer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'sf-image-resizer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>w</code></td>
					<td><?php echo esc_html( sprintf( '0 - %d', SFIR_MAX_DIMENSION ) ); ?></td>
					<td><code>0</code></td>
					<td><?php esc_html_e( 'Maximum width in pixels. 0 means "not constrained".', 'sf-image-resizer' ); ?></td>
				</tr>
				<tr>
					<td><code>h</code></td>
					<td><?php echo esc_html( sprintf( '0 - %d', SFIR_MAX_DIMENSION ) ); ?></td>
					<td><code>0</code></td>
					<td><?php esc_html_e( 'Maximum height in pixels. 0 means "not constrained".', 'sf-image-resizer' ); ?></td>
				</tr>
				<tr>
					<td><code>f</code></td>
					<td><code>webp</code>, <code>jpg</code></td>
					<td><code>webp</code></td>
					<td><?php esc_html_e( 'Output format.', 'sf-image-resizer' ); ?></td>
				</tr>
				<tr>
					<td><code>q</code></td>
					<td><?php echo esc_html( '1 - 95' ); ?></td>
					<td><code>75</code></td>
					<td><?php esc_html_e( 'Output quality.', 'sf-image-resizer' ); ?></td>
				</tr>
				<tr>
					<td><code>bg</code></td>
					<td><?php esc_html_e( '3 or 6 hex digits, without "#"', 'sf-image-resizer' ); ?></td>
					<td><?php esc_html_e( 'transparent, FFFFFF for JPG', 'sf-image-resizer' ); ?></td>
					<td><?php esc_html_e( 'Background colour used to fill transparent areas.', 'sf-image-resizer' ); ?></td>
				</tr>
				<tr>
					<td><code>crop</code></td>
					<td><code>0</code>, <code>1</code></td>
					<td><code>0</code></td>
					<td><?php esc_html_e( 'Crop to exactly w x h, centred. Ignored unless both w and h are set.', 'sf-image-resizer' ); ?></td>
				</tr>
			</tbody>
		</table>

		<p><?php esc_html_e( 'Images are never enlarged. If the source is smaller than the request, the result keeps the source size; with crop=1 it is cropped to the largest region matching the requested ratio.', 'sf-image-resizer' ); ?></p>

		<h3><?php esc_html_e( 'Examples', 'sf-image-resizer' ); ?></h3>

		<?php
		$examples = array(
			array(
				'title' => __( '1. A plain image URL:', 'sf-image-resizer' ),
				'code'  => "<img src=\"<?php echo esc_url( sf_img( \$url, 'w=900&q=75&f=webp' ) ); ?>\"\n"
					. "     width=\"<?php echo (int) sf_img_width( \$url, 'w=900' ); ?>\"\n"
					. "     height=\"<?php echo (int) sf_img_height( \$url, 'w=900' ); ?>\"\n"
					. '     alt="" loading="lazy" />',
			),
			array(
				'title' => __( '2. An attachment ID, cropped to a fixed box:', 'sf-image-resizer' ),
				'code'  => "<?php echo sf_img_tag( get_post_thumbnail_id(), 'w=600&h=400&crop=1', array( 'alt' => get_the_title(), 'class' => 'card__image' ) ); ?>",
			),
			array(
				'title' => __( '3. An ACF image field, flattened onto a white background as JPG:', 'sf-image-resizer' ),
				'code'  => "<?php \$image = get_field( 'hero' ); ?>\n"
					. "<img src=\"<?php echo esc_url( sf_img( \$image, 'w=1200&f=jpg&bg=FFFFFF' ) ); ?>\" alt=\"\" />",
			),
			array(
				'title' => __( '4. A responsive srcset:', 'sf-image-resizer' ),
				'code'  => "<img src=\"<?php echo esc_url( sf_img( \$image, 'w=640' ) ); ?>\"\n"
					. "     srcset=\"<?php echo esc_attr( sf_img( \$image, 'w=320' ) ); ?> 320w,\n"
					. "             <?php echo esc_attr( sf_img( \$image, 'w=640' ) ); ?> 640w,\n"
					. "             <?php echo esc_attr( sf_img( \$image, 'w=1280' ) ); ?> 1280w\"\n"
					. '     sizes="(max-width: 640px) 100vw, 640px" alt="" />',
			),
		);

		foreach ( $examples as $example ) {
			echo '<p>' . esc_html( $example['title'] ) . '</p>';
			echo '<pre class="sfir-code"><code>' . esc_html( $example['code'] ) . '</code></pre>';
		}
		?>

		<h3><?php esc_html_e( 'How the URL is built', 'sf-image-resizer' ); ?></h3>
		<p><?php esc_html_e( 'Cached copies mirror the directory tree of their source inside the cache directory, and the file name carries the parameters plus a short signature:', 'sf-image-resizer' ); ?></p>
		<pre class="sfir-code"><code><?php echo esc_html( SFIR_Cache::get_cache_url() . '/2026/08/photo-800x0-c0-q75-a3f9c1.webp' ); ?></code></pre>
		<p>
			<code>{name}-{w}x{h}-c{crop}-q{q}[-bg{BG}]-{hash}.{format}</code><br />
			<?php esc_html_e( 'The bg part appears only when a background colour was requested. The hash is six hexadecimal characters of an HMAC over the source path and the parameters; it lets the plugin create only the sizes your templates actually ask for, and cannot be guessed to fill the disk.', 'sf-image-resizer' ); ?>
		</p>

		<h3><?php esc_html_e( 'Error codes', 'sf-image-resizer' ); ?></h3>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Code', 'sf-image-resizer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Meaning', 'sf-image-resizer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( SFIR_Placeholder::get_codes() as $code => $description ) : ?>
					<tr>
						<td><code><?php echo esc_html( $code ); ?></code></td>
						<td><?php echo esc_html( $description ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Notes', 'sf-image-resizer' ); ?></h3>
		<ul class="sfir-notes">
			<li><?php esc_html_e( 'WebP fallback: if this PHP build has no WebP support, JPG is produced instead and the cached file gets a .jpg extension. A single notice is written to the log.', 'sf-image-resizer' ); ?></li>
			<li><?php esc_html_e( 'Animated GIF: only the first frame is used, animation is not preserved.', 'sf-image-resizer' ); ?></li>
			<li><?php esc_html_e( 'SVG files are not accepted as input.', 'sf-image-resizer' ); ?></li>
			<li><?php esc_html_e( 'Every call returns the same plain URL of the cached file, from the very first render. When that file does not exist yet, the request falls through to WordPress and the plugin generates it; afterwards the web server serves it as a static file and PHP is no longer involved.', 'sf-image-resizer' ); ?></li>
			<li><?php esc_html_e( 'On nginx, .htaccess files are ignored. Deny direct access to the logs directory in your server configuration. The configuration check above shows the location block for the cache directory if it is ever needed.', 'sf-image-resizer' ); ?></li>
		</ul>
		<?php
	}
}
