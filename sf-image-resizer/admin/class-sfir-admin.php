<?php
/**
 * Admin screen: cache, configuration check, log and documentation.
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
	 * Tab showing cache statistics and maintenance.
	 */
	const TAB_CACHE = 'cache';

	/**
	 * Tab showing the configuration check and the log.
	 */
	const TAB_CHECK = 'check';

	/**
	 * Tab holding the settings that change what WordPress itself does.
	 */
	const TAB_SETTINGS = 'settings';

	/**
	 * Tab showing the documentation.
	 */
	const TAB_DOCS = 'documentation';

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
		add_action( 'admin_post_sfir_language', array( __CLASS__, 'handle_language' ) );
		add_action( 'admin_post_sfir_generate_mode', array( __CLASS__, 'handle_generate_mode' ) );
		add_action( 'admin_post_sfir_settings', array( __CLASS__, 'handle_settings' ) );
	}

	/**
	 * Adds the plugin to the main admin menu.
	 *
	 * @return void
	 */
	public static function register_page() {
		add_menu_page(
			__( 'SF Image resizer', 'sf-image-resizer' ),
			__( 'SF Image resizer', 'sf-image-resizer' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-format-image',
			81
		);
	}

	/**
	 * Loads the local stylesheet and script on the plugin screen only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook_suffix ) {
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

		wp_localize_script(
			'sfir-admin',
			'sfirAdmin',
			array(
				'confirmCache' => __( 'Delete every cached image? They will be regenerated on demand.', 'sf-image-resizer' ),
				'confirmLog'   => __( 'Clear the error log?', 'sf-image-resizer' ),
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'ajaxAction'   => SFIR_Diagnostics::AJAX_ACTION,
				'nonce'        => wp_create_nonce( SFIR_Diagnostics::AJAX_ACTION ),
				'workingLabel' => __( 'Works on this server.', 'sf-image-resizer' ),
				'checkedNow'   => __( 'The image below loaded in your browser just now.', 'sf-image-resizer' ),
				'failedLabel'  => __( 'Does not work on this server.', 'sf-image-resizer' ),
				'failedText'   => __( 'The image below could not be loaded.', 'sf-image-resizer' ),
				'copied'       => __( 'Copied', 'sf-image-resizer' ),
				'copyFailed'   => __( 'Press Ctrl+C to copy', 'sf-image-resizer' ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Form handlers.
	// ---------------------------------------------------------------------

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

		self::redirect_back( self::TAB_CACHE );
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

		self::redirect_back( self::TAB_CHECK );
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

		self::redirect_back( self::TAB_CHECK );
	}

	/**
	 * Handles the language picker.
	 *
	 * @return void
	 */
	public static function handle_language() {
		self::verify_request( 'sfir_language' );

		// The nonce and the capability are checked by verify_request() above;
		// the sniff cannot follow the call into that helper.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$locale = isset( $_POST['sfir_locale'] ) ? sanitize_text_field( wp_unslash( $_POST['sfir_locale'] ) ) : '';
		$tab    = isset( $_POST['sfir_tab'] ) ? sanitize_key( wp_unslash( $_POST['sfir_tab'] ) ) : self::TAB_CACHE;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		SFIR_I18n::set_user_locale( $locale );

		self::redirect_back( $tab );
	}

	/**
	 * Handles the generation mode form.
	 *
	 * @return void
	 */
	public static function handle_generate_mode() {
		self::verify_request( 'sfir_generate_mode' );

		// The nonce and the capability are checked by verify_request() above;
		// the sniff cannot follow the call into that helper.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$mode = isset( $_POST['sfir_mode'] ) ? sanitize_key( wp_unslash( $_POST['sfir_mode'] ) ) : SFIR_Generator::MODE_AUTO;

		SFIR_Generator::set_mode( $mode );

		self::set_notice( 'success', __( 'The generation mode has been saved.', 'sf-image-resizer' ) );

		self::redirect_back( self::TAB_CHECK );
	}

	/**
	 * Handles the settings form.
	 *
	 * @return void
	 */
	public static function handle_settings() {
		self::verify_request( 'sfir_settings' );

		// The nonce and the capability are checked by verify_request() above;
		// the sniff cannot follow the call into that helper.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$keep_full = ! empty( $_POST['sfir_keep_full_size_uploads'] );

		SFIR_Settings::set_keeps_full_size_uploads( $keep_full );

		self::set_notice( 'success', __( 'The settings have been saved.', 'sf-image-resizer' ) );

		self::redirect_back( self::TAB_SETTINGS );
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
	 * @param string $tab Tab to return to.
	 * @return void
	 */
	protected static function redirect_back( $tab = self::TAB_CACHE ) {
		wp_safe_redirect( self::get_tab_url( $tab ) );
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

	// ---------------------------------------------------------------------
	// The screen.
	// ---------------------------------------------------------------------

	/**
	 * Returns the tabs of the screen.
	 *
	 * @return array<string,string> Tab slug to label.
	 */
	protected static function get_tabs() {
		return array(
			self::TAB_CACHE    => __( 'Cache', 'sf-image-resizer' ),
			self::TAB_SETTINGS => __( 'Settings', 'sf-image-resizer' ),
			self::TAB_CHECK    => __( 'Check and log', 'sf-image-resizer' ),
			self::TAB_DOCS     => __( 'Documentation', 'sf-image-resizer' ),
		);
	}

	/**
	 * Returns the tab the request asks for.
	 *
	 * @return string
	 */
	public static function get_current_tab() {
		// Reading a tab name from the URL needs no nonce: it selects which part
		// of a read-only screen to draw and changes nothing.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return isset( self::get_tabs()[ $tab ] ) ? $tab : self::TAB_CACHE;
	}

	/**
	 * Returns the URL of one tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public static function get_tab_url( $tab ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		if ( self::TAB_CACHE !== $tab ) {
			$url .= '&tab=' . rawurlencode( $tab );
		}

		return $url;
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

		SFIR_I18n::load_textdomain();

		$tab    = self::get_current_tab();
		$action = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<div class="wrap sfir-wrap">
			<div class="sfir-header">
				<h1><?php esc_html_e( 'SF Image resizer', 'sf-image-resizer' ); ?></h1>
				<?php self::render_language_picker( $action, $tab ); ?>
			</div>

			<?php self::render_notice(); ?>

			<h2 class="nav-tab-wrapper sfir-tabs">
				<?php foreach ( self::get_tabs() as $slug => $label ) : ?>
					<a href="<?php echo esc_url( self::get_tab_url( $slug ) ); ?>"
						class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<div class="sfir-tab-content">
				<?php
				if ( self::TAB_CHECK === $tab ) {
					self::render_tab_check( $action );
				} elseif ( self::TAB_SETTINGS === $tab ) {
					self::render_tab_settings( $action );
				} elseif ( self::TAB_DOCS === $tab ) {
					self::render_tab_documentation();
				} else {
					self::render_tab_cache( $action );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the language picker.
	 *
	 * @param string $action URL of admin-post.php, already escaped.
	 * @param string $tab    Tab to return to after the change.
	 * @return void
	 */
	protected static function render_language_picker( $action, $tab ) {
		$current = SFIR_I18n::get_user_locale();
		?>
		<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?>" class="sfir-language">
			<?php wp_nonce_field( 'sfir_language' ); ?>
			<input type="hidden" name="action" value="sfir_language" />
			<input type="hidden" name="sfir_tab" value="<?php echo esc_attr( $tab ); ?>" />
			<label for="sfir-locale" class="screen-reader-text"><?php esc_html_e( 'Language of this screen', 'sf-image-resizer' ); ?></label>
			<select name="sfir_locale" id="sfir-locale">
				<?php foreach ( SFIR_I18n::get_available_locales() as $code => $name ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $current ); ?>>
						<?php echo esc_html( $name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button" id="sfir-language-apply"><?php esc_html_e( 'Apply', 'sf-image-resizer' ); ?></button>
		</form>
		<?php
	}

	// ---------------------------------------------------------------------
	// Tab: cache.
	// ---------------------------------------------------------------------

	/**
	 * Renders the cache tab.
	 *
	 * @param string $action URL of admin-post.php, already escaped.
	 * @return void
	 */
	protected static function render_tab_cache( $action ) {
		$stats = SFIR_Cache::get_stats();
		?>
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

		<p class="description"><?php esc_html_e( 'Deleting the cache is safe: every copy is created again the first time a browser asks for it.', 'sf-image-resizer' ); ?></p>

		<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?>" class="sfir-form">
			<?php wp_nonce_field( 'sfir_clear_cache' ); ?>
			<input type="hidden" name="action" value="sfir_clear_cache" />
			<?php submit_button( __( 'Clear image cache', 'sf-image-resizer' ), 'secondary', 'sfir-clear-cache', false ); ?>
		</form>
		<?php
	}

	// ---------------------------------------------------------------------
	// Tab: settings.
	// ---------------------------------------------------------------------

	/**
	 * Renders the settings that change what WordPress itself does.
	 *
	 * @param string $action URL of admin-post.php, already escaped.
	 * @return void
	 */
	protected static function render_tab_settings( $action ) {
		$keep_full = SFIR_Settings::keeps_full_size_uploads();
		$threshold = SFIR_Settings::get_effective_threshold();
		?>
		<h2><?php esc_html_e( 'Uploads', 'sf-image-resizer' ); ?></h2>

		<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?>" class="sfir-form">
			<?php wp_nonce_field( 'sfir_settings' ); ?>
			<input type="hidden" name="action" value="sfir_settings" />

			<fieldset class="sfir-modes">
				<label>
					<input type="checkbox" name="sfir_keep_full_size_uploads" value="1" <?php checked( $keep_full ); ?> />
					<span><?php esc_html_e( 'Keep uploaded images at their original size', 'sf-image-resizer' ); ?></span>
				</label>
				<p class="description">
					<?php esc_html_e( 'On its own, WordPress shrinks any image wider or taller than 2560 pixels when it is uploaded, keeps the smaller version as the original and adds "-scaled" to its file name. The full size image is never used again. Tick this box and the file you upload is the file that is kept.', 'sf-image-resizer' ); ?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Worth doing if you serve retina or full width images through this plugin, since it can only ever resize down from what it is given. The cost is disk space, and slightly more work the first time a large original is resized.', 'sf-image-resizer' ); ?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Images already uploaded are not affected: WordPress shrank them at upload time and the full size is gone. Re-upload the ones that matter.', 'sf-image-resizer' ); ?>
				</p>
			</fieldset>

			<p class="description sfir-verdict">
				<strong>
					<?php
					if ( $threshold > 0 ) {
						printf(
							/* translators: %d: threshold in pixels. */
							esc_html__( 'Right now: WordPress shrinks uploads larger than %d pixels.', 'sf-image-resizer' ),
							(int) $threshold
						);
					} else {
						esc_html_e( 'Right now: uploads are kept at their original size.', 'sf-image-resizer' );
					}
					?>
				</strong>
			</p>

			<?php submit_button( __( 'Save', 'sf-image-resizer' ), 'secondary', 'sfir-save-settings', false ); ?>
		</form>
		<?php
	}

	// ---------------------------------------------------------------------
	// Tab: check and log.
	// ---------------------------------------------------------------------

	/**
	 * Renders the configuration check and the log.
	 *
	 * @param string $action URL of admin-post.php, already escaped.
	 * @return void
	 */
	protected static function render_tab_check( $action ) {
		$results = SFIR_Diagnostics::get_results();
		$log     = SFIR_Logger::read( 50 );

		// Clear out the copies earlier visits left behind, and only then make
		// the ones this visit is about to show. Doing it the other way round
		// deletes the file the render check has just produced, and the browser
		// is then handed a URL with nothing behind it.
		SFIR_Diagnostics::cleanup_probe_files();

		// Both checks run on every visit: what matters is whether they work
		// now, not whether they once did.
		$render_url  = SFIR_Diagnostics::get_render_probe_url();
		$request_url = SFIR_Diagnostics::get_probe_url();
		?>
		<h2><?php esc_html_e( 'Configuration check', 'sf-image-resizer' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'There are two ways for a resized copy to come into being, and a server can support one without the other. Each is checked on its own by loading a real image in your browser, right here. A picture you can see is a check that passed.', 'sf-image-resizer' ); ?>
		</p>

		<div id="sfir-check">
			<?php
			self::render_check_row(
				SFIR_Diagnostics::CHECK_RENDER,
				__( 'Generating while the page is rendered', 'sf-image-resizer' ),
				__( 'The plugin created the image below during this page load, and your browser then asked the web server for it like any other file. Nothing else is required, so this works almost everywhere.', 'sf-image-resizer' ),
				$results[ SFIR_Diagnostics::CHECK_RENDER ],
				$render_url
			);

			self::render_check_row(
				SFIR_Diagnostics::CHECK_REQUEST,
				__( 'Generating on request from the browser', 'sf-image-resizer' ),
				__( 'The image below does not exist on disk. Your browser is asking for it anyway, and the web server has to pass that request to WordPress instead of answering 404. Apache does it through the .htaccess this plugin writes; nginx needs a rule in its configuration.', 'sf-image-resizer' ),
				$results[ SFIR_Diagnostics::CHECK_REQUEST ],
				$request_url
			);
			?>
		</div>

		<p class="description sfir-verdict"><strong><?php echo esc_html( self::get_verdict( $results ) ); ?></strong></p>

		<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?>" class="sfir-form">
			<?php wp_nonce_field( 'sfir_recheck' ); ?>
			<input type="hidden" name="action" value="sfir_recheck" />
			<?php submit_button( __( 'Check again', 'sf-image-resizer' ), 'secondary', 'sfir-recheck', false ); ?>
		</form>

		<?php self::render_generate_mode( $action ); ?>

		<h2><?php esc_html_e( 'Error log', 'sf-image-resizer' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'The 50 first lines of the log file are shown below.', 'sf-image-resizer' ); ?>
			<code><?php echo esc_html( SFIR_Logger::get_log_file() ); ?></code>
		</p>
		<textarea class="sfir-log" readonly="readonly" rows="12" spellcheck="false"><?php echo esc_textarea( '' === $log ? __( 'The log is empty.', 'sf-image-resizer' ) : $log ); ?></textarea>

		<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?>" class="sfir-form">
			<?php wp_nonce_field( 'sfir_clear_log' ); ?>
			<input type="hidden" name="action" value="sfir_clear_log" />
			<?php submit_button( __( 'Clear log', 'sf-image-resizer' ), 'secondary', 'sfir-clear-log', false ); ?>
		</form>
		<?php
	}

	/**
	 * Renders one line of the configuration check.
	 *
	 * The verdict is drawn from the last stored answer so that the page is
	 * readable without JavaScript, and the image is what settles it for this
	 * visit: the browser either loads it or it does not, and the script turns
	 * that into the state shown here.
	 *
	 * @param string $which       Check name, see SFIR_Diagnostics::CHECK_*.
	 * @param string $title       What is being checked.
	 * @param string $explanation What it depends on.
	 * @param int    $confirmed   Timestamp of the last confirmation, 0 if none.
	 * @param string $probe_url   URL of the image that settles it.
	 * @return void
	 */
	protected static function render_check_row( $which, $title, $explanation, $confirmed, $probe_url ) {
		$works = $confirmed > 0;
		?>
		<div class="sfir-check-row <?php echo $works ? 'is-working' : 'is-unknown'; ?>" data-check="<?php echo esc_attr( $which ); ?>">
			<p class="sfir-check-title">
				<span class="sfir-check-mark" aria-hidden="true"><?php echo $works ? '&#10003;' : '&#8226;'; ?></span>
				<strong><?php echo esc_html( $title ); ?></strong>
			</p>

			<div class="sfir-check-body">
				<div class="sfir-check-figure">
					<?php if ( '' !== $probe_url ) : ?>
						<img class="sfir-probe" src="<?php echo esc_url( $probe_url ); ?>" width="<?php echo (int) SFIR_Diagnostics::PROBE_SIZE; ?>" height="<?php echo (int) SFIR_Diagnostics::PROBE_SIZE; ?>" alt="<?php esc_attr_e( 'Test image', 'sf-image-resizer' ); ?>" />
					<?php else : ?>
						<span class="sfir-probe-missing" aria-hidden="true">&#10007;</span>
					<?php endif; ?>
				</div>

				<div class="sfir-check-text">
					<p class="sfir-check-state">
						<?php
						if ( '' === $probe_url ) {
							esc_html_e( 'The test image could not be produced at all. Check that GD is available and that the cache directory is writable; the log below will say which.', 'sf-image-resizer' );
						} elseif ( $works ) {
							printf(
								/* translators: %s: date and time of the last confirmation. */
								esc_html__( 'Works on this server. Confirmed on %s.', 'sf-image-resizer' ),
								esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $confirmed ) )
							);
						} else {
							esc_html_e( 'Checking…', 'sf-image-resizer' );
						}
						?>
					</p>
					<p class="description"><?php echo esc_html( $explanation ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Sums up what the two checks mean together.
	 *
	 * @param array $results Result of SFIR_Diagnostics::get_results().
	 * @return string
	 */
	protected static function get_verdict( array $results ) {
		$render  = $results[ SFIR_Diagnostics::CHECK_RENDER ] > 0;
		$request = $results[ SFIR_Diagnostics::CHECK_REQUEST ] > 0;

		if ( $render && $request ) {
			return __( 'Both ways work here, so any mode below is safe. Generating on request is the lighter one.', 'sf-image-resizer' );
		}

		if ( $render ) {
			return __( 'This server supports generating while the page is rendered, but not on request. That is not a fault, and your images are being produced normally — it is exactly the case the first mode below exists for. Leave the mode on "Automatic" or set it to the first one.', 'sf-image-resizer' );
		}

		if ( $request ) {
			return __( 'Generating on request works here, which is the lighter of the two. The other check has not answered yet.', 'sf-image-resizer' );
		}

		return __( 'Neither check has answered yet. They run in your browser when this tab is open, so give the page a moment, and make sure no ad blocker is stopping requests to your own site.', 'sf-image-resizer' );
	}

	/**
	 * Renders the picker that decides when copies are produced.
	 *
	 * @param string $action URL of admin-post.php, already escaped.
	 * @return void
	 */
	protected static function render_generate_mode( $action ) {
		$current = SFIR_Generator::get_mode();
		$eager   = SFIR_Generator::is_eager();
		?>
		<h2><?php esc_html_e( 'When copies are produced', 'sf-image-resizer' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'If your host cannot be configured to hand a missing cache file to WordPress, let the plugin produce the copies while the page is rendered. The first visit to each page is then slower, and every visit after that is served straight from disk.', 'sf-image-resizer' ); ?>
		</p>

		<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?>" class="sfir-form">
			<?php wp_nonce_field( 'sfir_generate_mode' ); ?>
			<input type="hidden" name="action" value="sfir_generate_mode" />

			<fieldset class="sfir-modes">
				<?php foreach ( SFIR_Generator::get_modes() as $value => $label ) : ?>
					<label>
						<input type="radio" name="sfir_mode" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?> />
						<span><?php echo esc_html( $label ); ?></span>
					</label>
					<p class="description"><?php echo esc_html( self::get_mode_description( $value ) ); ?></p>
				<?php endforeach; ?>
			</fieldset>

			<p class="description">
				<strong>
					<?php
					echo esc_html(
						$eager
							? __( 'Right now: copies are produced while the page is rendered.', 'sf-image-resizer' )
							: __( 'Right now: copies are produced the first time a browser asks for them.', 'sf-image-resizer' )
					);
					?>
				</strong>
			</p>

			<?php submit_button( __( 'Save', 'sf-image-resizer' ), 'secondary', 'sfir-save-mode', false ); ?>
		</form>

		<details class="sfir-hints"<?php echo SFIR_Diagnostics::get_confirmed_at() > 0 ? '' : ' open="open"'; ?>>
			<summary><?php esc_html_e( 'Making the on-request mode work on nginx', 'sf-image-resizer' ); ?></summary>
			<p><?php esc_html_e( 'nginx answers a request for a file that does not exist with 404 and never involves WordPress, which is why the on-request mode cannot work until it is told otherwise. Add this to the server configuration of this site and reload nginx. Your host can apply it for you; it affects one directory and nothing else:', 'sf-image-resizer' ); ?></p>
			<pre class="sfir-code"><code><?php echo esc_html( SFIR_Cache::get_nginx_snippet() ); ?></code></pre>
			<p class="description"><?php esc_html_e( 'The ^~ matters: without it the rule loses to the block that serves static images, which is present in almost every configuration. Once nginx is reloaded, press "Check again" above.', 'sf-image-resizer' ); ?></p>
			<p><?php esc_html_e( 'On Apache nothing needs adding, but .htaccess files have to be honoured (AllowOverride All) for the uploads directory.', 'sf-image-resizer' ); ?></p>
		</details>
		<?php
	}

	/**
	 * Returns the explanation shown under one generation mode.
	 *
	 * @param string $mode One of the SFIR_Generator::MODE_* constants.
	 * @return string
	 */
	protected static function get_mode_description( $mode ) {
		if ( SFIR_Generator::MODE_ALWAYS === $mode ) {
			return __( 'Every size of every image on the page is created as the page is built, even the sizes no visitor has needed yet. Nine images at six widths is 54 copies, so the first visit creates part of them and the following visits create the rest — deliberately, so that a page full of new sizes cannot run into the PHP timeout and hold the site up. Slower on the first visits, and it works on every server.', 'sf-image-resizer' );
		}

		if ( SFIR_Generator::MODE_NEVER === $mode ) {
			return __( 'Nothing is made in advance. When a visitor arrives whose screen needs a particular size, that visitor\'s browser asks for it, the plugin creates exactly that one copy and caches it on disk, and it is never made again. Only the sizes really being looked at are ever created, and no page render is slowed down. This is the better of the two — but it needs the web server to pass a request for a missing file to WordPress, and not every server can be made to do that. The mode above exists to cover the ones that cannot.', 'sf-image-resizer' );
		}

		return __( 'Uses whichever of the two below works on this server. It starts by generating up front, and switches to generating on request as soon as the check above confirms that requests for missing files reach the plugin. Leave it on this unless you have a reason not to.', 'sf-image-resizer' );
	}

	// ---------------------------------------------------------------------
	// Tab: documentation.
	// ---------------------------------------------------------------------

	/**
	 * Returns the absolute path of the Markdown reference.
	 *
	 * @return string
	 */
	public static function get_markdown_path() {
		return SFIR_PLUGIN_DIR . 'docs/sf-image-resizer.md';
	}

	/**
	 * Returns the contents of the Markdown reference.
	 *
	 * @return string
	 */
	public static function get_markdown() {
		$path = self::get_markdown_path();

		if ( ! is_readable( $path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file shipped with the plugin, not a remote request.
		return (string) file_get_contents( $path );
	}

	/**
	 * Renders the documentation tab.
	 *
	 * @return void
	 */
	protected static function render_tab_documentation() {
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
					<td><code>sf_img_srcset( $source, $params = '', $widths = [] )</code></td>
					<td><?php esc_html_e( 'Returns a complete srcset for 320, 640, 960, 1280, 1920 and 2560 pixels. Output it with esc_attr().', 'sf-image-resizer' ); ?></td>
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
		);

		foreach ( $examples as $example ) {
			echo '<p>' . esc_html( $example['title'] ) . '</p>';
			echo '<pre class="sfir-code"><code>' . esc_html( $example['code'] ) . '</code></pre>';
		}
		?>

		<?php
		$universal = "<img src=\"<?php echo esc_url( \$image['url'] ); ?>\"\n"
			. "     srcset=\"<?php echo esc_attr( sf_img_srcset( \$image ) ); ?>\"\n"
			. "     sizes=\"auto\"\n"
			. "     width=\"<?php echo (int) sf_img_width( \$image, '' ); ?>\"\n"
			. "     height=\"<?php echo (int) sf_img_height( \$image, '' ); ?>\"\n"
			. "     alt=\"<?php echo esc_attr( \$image['alt'] ); ?>\"\n"
			. '     class="card__image" loading="lazy" decoding="async">';

		$custom = "<img src=\"<?php echo esc_url( sf_img( \$image, 'w=640' ) ); ?>\"\n"
			. "     srcset=\"<?php echo esc_attr( sf_img_srcset( \$image, '', array( 320, 640, 1280 ) ) ); ?>\"\n"
			. "     sizes=\"(max-width: 640px) 100vw, 640px\"\n"
			. "     width=\"<?php echo (int) sf_img_width( \$image, 'w=640' ); ?>\"\n"
			. "     height=\"<?php echo (int) sf_img_height( \$image, 'w=640' ); ?>\"\n"
			. '     alt="" loading="lazy" decoding="async">';
		?>

		<h3><?php esc_html_e( '4. A universal responsive srcset', 'sf-image-resizer' ); ?></h3>
		<p><?php esc_html_e( 'This is the recommended way to output a responsive image, and it covers almost every case. sf_img_srcset() builds the whole srcset for you at 320, 640, 960, 1280, 1920 and 2560 pixels, so nothing has to be added to your theme functions.php. Everything else in the tag stays yours to write.', 'sf-image-resizer' ); ?></p>
		<pre class="sfir-code"><code><?php echo esc_html( $universal ); ?></code></pre>
		<p><?php esc_html_e( 'The variable may be called anything: it is just the first argument. Widths the source is too small for are skipped, and every candidate is labelled with the width the file really has, so the browser is never told that a 1600 pixel copy is 2560 pixels wide.', 'sf-image-resizer' ); ?></p>
		<p><?php esc_html_e( 'sizes="auto" lets the browser measure the image itself, and it requires loading="lazy". For an image above the fold, drop the lazy loading and write sizes yourself, for example sizes="100vw".', 'sf-image-resizer' ); ?></p>

		<h3><?php esc_html_e( '5. A custom responsive srcset', 'sf-image-resizer' ); ?></h3>
		<p><?php esc_html_e( 'Use this variant only when you know exactly what size the image is rendered at in each place of your site, and want to control the widths and the sizes attribute yourself. In every other case example 4 is simpler and harder to get wrong.', 'sf-image-resizer' ); ?></p>
		<pre class="sfir-code"><code><?php echo esc_html( $custom ); ?></code></pre>

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

		<h3><?php esc_html_e( 'How the URL is built', 'sf-image-resizer' ); ?></h3>
		<p><?php esc_html_e( 'Cached copies mirror the directory tree of their source inside the cache directory, and the file name carries the parameters plus a short signature:', 'sf-image-resizer' ); ?></p>
		<pre class="sfir-code"><code><?php echo esc_html( SFIR_Cache::get_cache_url() . '/2026/08/photo-800x0-c0-q75-a3f9c1.webp' ); ?></code></pre>
		<p>
			<code>{name}-{w}x{h}-c{crop}-q{q}[-bg{BG}]-{hash}.{format}</code><br />
			<?php esc_html_e( 'The bg part appears only when a background colour was requested. The hash is six hexadecimal characters of an HMAC over the source path and the parameters; it lets the plugin create only the sizes your templates actually ask for, and cannot be guessed to fill the disk.', 'sf-image-resizer' ); ?>
		</p>

		<h3><?php esc_html_e( 'Notes', 'sf-image-resizer' ); ?></h3>
		<ul class="sfir-notes">
			<li><?php esc_html_e( 'WebP fallback: if this PHP build has no WebP support, JPG is produced instead and the cached file gets a .jpg extension. A single notice is written to the log.', 'sf-image-resizer' ); ?></li>
			<li><?php esc_html_e( 'Animated GIF: only the first frame is used, animation is not preserved.', 'sf-image-resizer' ); ?></li>
			<li><?php esc_html_e( 'SVG files are not accepted as input.', 'sf-image-resizer' ); ?></li>
			<li><?php esc_html_e( 'Every call returns the same plain URL of the cached file, from the very first render. When that file does not exist yet, the request falls through to WordPress and the plugin generates it; afterwards the web server serves it as a static file and PHP is no longer involved.', 'sf-image-resizer' ); ?></li>
			<li><?php esc_html_e( 'On nginx, .htaccess files are ignored. Deny direct access to the logs directory in your server configuration.', 'sf-image-resizer' ); ?></li>
		</ul>

		<?php self::render_markdown_block(); ?>
		<?php
	}

	/**
	 * Renders the copyable Markdown reference.
	 *
	 * @return void
	 */
	protected static function render_markdown_block() {
		$markdown = self::get_markdown();

		if ( '' === $markdown ) {
			return;
		}
		?>
		<h3><?php esc_html_e( 'Reference for an AI assistant', 'sf-image-resizer' ); ?></h3>
		<p><?php esc_html_e( 'The whole reference below is written for a language model. Copy it into Claude, ChatGPT or any other assistant together with your own template, and it will have everything it needs to output correct src and srcset markup with this plugin.', 'sf-image-resizer' ); ?></p>

		<p class="sfir-markdown-actions">
			<button type="button" class="button" id="sfir-copy-markdown" data-target="sfir-markdown">
				<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
				<?php esc_html_e( 'Copy', 'sf-image-resizer' ); ?>
			</button>
			<a class="button" href="<?php echo esc_url( SFIR_PLUGIN_URL . 'docs/sf-image-resizer.md' ); ?>" download="sf-image-resizer.md">
				<span class="dashicons dashicons-download" aria-hidden="true"></span>
				<?php esc_html_e( 'Download .md', 'sf-image-resizer' ); ?>
			</a>
			<span id="sfir-copy-feedback" class="sfir-copy-feedback" role="status"></span>
		</p>

		<textarea id="sfir-markdown" class="sfir-markdown" readonly="readonly" rows="18" spellcheck="false"><?php echo esc_textarea( $markdown ); ?></textarea>
		<?php
	}
}
