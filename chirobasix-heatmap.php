<?php
/**
 * Plugin Name: CHIROBASIX Heatmaps
 * Plugin URI: https://copilot.chirobasix.com
 * Description: Injects the CHIROBASIX Heatmaps tracking script to collect user behavior data for click maps, scroll depth, and mouse movement analysis.
 * Version: 1.0.0
 * Author: ChiroBasix
 * License: Proprietary
 * GitHub Repo: chirobasix/chirobasix-heatmaps
 */

// Prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── GitHub Self-Updater ──────────────────────────────────────────────────────
// Checks GitHub releases for a newer version and overwrites this file when one
// is available. Mirrors the pattern used in allow-copilot-iframe.php.

class CBX_Heatmap_Updater {

	private const GITHUB_OWNER    = 'chirobasix';
	private const GITHUB_REPO     = 'chirobasix-heatmaps';
	private const TRANSIENT        = 'cbx_heatmap_update';
	private const CHECK_INTERVAL   = 6 * HOUR_IN_SECONDS;

	private $current_version;
	private $plugin_file;

	public function __construct() {
		$this->plugin_file     = __FILE__;
		$this->current_version = $this->get_local_version();

		add_action( 'admin_init', array( $this, 'maybe_update' ) );
		add_action( 'admin_notices', array( $this, 'update_notice' ) );
	}

	private function get_local_version() {
		$data = get_file_data( __FILE__, array( 'Version' => 'Version' ) );
		return $data['Version'] ?? '0.0.0';
	}

	private function get_latest_release() {
		$cached = get_transient( self::TRANSIENT );
		if ( false !== $cached ) {
			return $cached;
		}

		$url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			self::GITHUB_OWNER,
			self::GITHUB_REPO
		);

		$response = wp_remote_get( $url, array(
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'CBX-Heatmap-Updater',
			),
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::TRANSIENT, array( 'tag_name' => '0.0.0' ), 30 * MINUTE_IN_SECONDS );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['tag_name'] ) ) {
			return false;
		}

		set_transient( self::TRANSIENT, $body, self::CHECK_INTERVAL );
		return $body;
	}

	public function maybe_update() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Manual trigger: ?cbx_heatmap_update=1
		if ( ! isset( $_GET['cbx_heatmap_update'] ) ) {
			$release = $this->get_latest_release();
			if ( ! $release ) {
				return;
			}

			$remote_version = ltrim( $release['tag_name'], 'vV' );
			if ( ! version_compare( $remote_version, $this->current_version, '>' ) ) {
				return;
			}

			$this->do_update( $release );
			return;
		}

		// Manual trigger
		check_admin_referer( 'cbx_heatmap_update' );

		delete_transient( self::TRANSIENT );
		$release = $this->get_latest_release();
		if ( ! $release ) {
			add_settings_error( 'cbx_heatmap', 'update_failed', 'Could not fetch latest release from GitHub.', 'error' );
			return;
		}

		$remote_version = ltrim( $release['tag_name'], 'vV' );
		if ( ! version_compare( $remote_version, $this->current_version, '>' ) ) {
			add_settings_error( 'cbx_heatmap', 'up_to_date', 'Already running the latest version (' . $this->current_version . ').', 'info' );
			return;
		}

		$this->do_update( $release );
	}

	private function do_update( $release ) {
		$remote_version = ltrim( $release['tag_name'], 'vV' );

		$raw_url = sprintf(
			'https://raw.githubusercontent.com/%s/%s/%s/chirobasix-heatmap.php',
			self::GITHUB_OWNER,
			self::GITHUB_REPO,
			$release['tag_name']
		);

		$response = wp_remote_get( $raw_url, array(
			'timeout' => 15,
			'headers' => array( 'User-Agent' => 'CBX-Heatmap-Updater' ),
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return;
		}

		$new_content = wp_remote_retrieve_body( $response );

		// Sanity check: must contain our plugin header
		if ( false === strpos( $new_content, 'CHIROBASIX Heatmaps' ) ) {
			return;
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$result = $wp_filesystem->put_contents( $this->plugin_file, $new_content, FS_CHMOD_FILE );

		if ( $result ) {
			delete_transient( self::TRANSIENT );
			update_option( 'cbx_heatmap_updated', $remote_version );
		}
	}

	public function update_notice() {
		$updated_version = get_option( 'cbx_heatmap_updated' );
		if ( $updated_version ) {
			delete_option( 'cbx_heatmap_updated' );
			printf(
				'<div class="notice notice-success is-dismissible"><p><strong>CHIROBASIX Heatmaps</strong> has been updated to version %s.</p></div>',
				esc_html( $updated_version )
			);
		}
	}
}

new CBX_Heatmap_Updater();

/**
 * ChiroBasix Heatmap Tracker class.
 */
class ChiroBasixHeatmap {

	/**
	 * Singleton instance of the class.
	 *
	 * @var ChiroBasixHeatmap
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 *
	 * Registers all necessary hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_tracker_script' ] );
		add_filter( 'script_loader_tag', [ $this, 'add_script_attributes' ], 10, 2 );
		add_action( 'send_headers', [ $this, 'allow_copilot_framing' ] );
	}

	/**
	 * Get singleton instance.
	 *
	 * @return ChiroBasixHeatmap
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize the plugin on plugins_loaded hook.
	 *
	 * @return void
	 */
	public static function init() {
		self::instance();
	}

	/**
	 * Register the admin menu.
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		add_options_page(
			'CHIROBASIX Heatmaps',
			'CHIROBASIX Heatmaps',
			'manage_options',
			'chirobasix_heatmap',
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Register settings and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		// Register the setting.
		register_setting(
			'chirobasix_heatmap_settings',
			'chirobasix_heatmap_site_id',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => false,
			]
		);

		// Add settings section.
		add_settings_section(
			'chirobasix_heatmap_section',
			'Heatmap Configuration',
			[ $this, 'render_section_description' ],
			'chirobasix_heatmap_settings'
		);

		// Add settings field.
		add_settings_field(
			'chirobasix_heatmap_site_id',
			'Site ID',
			[ $this, 'render_site_id_field' ],
			'chirobasix_heatmap_settings',
			'chirobasix_heatmap_section'
		);
	}

	/**
	 * Render section description.
	 *
	 * @return void
	 */
	public function render_section_description() {
		echo '<p>Configure your CHIROBASIX Heatmaps settings below.</p>';
	}

	/**
	 * Render the Site ID field.
	 *
	 * @return void
	 */
	public function render_site_id_field() {
		$site_id = get_option( 'chirobasix_heatmap_site_id' );
		?>
		<input
			type="text"
			name="chirobasix_heatmap_site_id"
			value="<?php echo esc_attr( $site_id ); ?>"
			placeholder="Enter your Site ID"
		/>
		<p class="description">Enter the Site ID from your ChiroBasix Copilot dashboard.</p>
		<?php
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		// Check user capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'chirobasix_heatmap_settings' );
				do_settings_sections( 'chirobasix_heatmap_settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Allow copilot.chirobasix.com (and Vercel preview deployments) to embed
	 * this site in an iframe for the heatmap viewer. Runs on frontend only —
	 * is_admin() returns false inside send_headers but we skip wp-login too.
	 *
	 * @return void
	 */
	public function allow_copilot_framing() {
		// Only modify frontend responses, not wp-admin or wp-login.
		if ( is_admin() ) {
			return;
		}

		// Remove WordPress's default X-Frame-Options header.
		header_remove( 'X-Frame-Options' );

		// Allow framing only from our own origin, production Copilot, and
		// any Vercel preview deployment (*.vercel.app).
		header( "Content-Security-Policy: frame-ancestors 'self' https://copilot.chirobasix.com https://*.vercel.app", false );
	}

	/**
	 * Enqueue the heatmap tracker script.
	 *
	 * @return void
	 */
	public function enqueue_tracker_script() {
		$site_id = get_option( 'chirobasix_heatmap_site_id' );

		// Only enqueue if site ID is set.
		if ( empty( $site_id ) ) {
			return;
		}

		wp_enqueue_script(
			'chirobasix-heatmap-tracker',
			'https://copilot.chirobasix.com/heatmap-tracker.js',
			[],
			null,
			true // Load in footer
		);
	}

	/**
	 * Add script attributes to the enqueued script.
	 *
	 * @param string $tag    The script tag.
	 * @param string $handle The script handle.
	 *
	 * @return string
	 */
	public function add_script_attributes( $tag, $handle ) {
		if ( 'chirobasix-heatmap-tracker' !== $handle ) {
			return $tag;
		}

		$site_id = get_option( 'chirobasix_heatmap_site_id' );

		if ( empty( $site_id ) ) {
			return $tag;
		}

		// Add data-site-id attribute and defer attribute.
		$tag = str_replace(
			'<script',
			'<script data-site-id="' . esc_attr( $site_id ) . '" defer',
			$tag
		);

		return $tag;
	}
}

// Initialize the plugin.
ChiroBasixHeatmap::init();
