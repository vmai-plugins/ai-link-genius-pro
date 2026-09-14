<?php
/**
 * GitHub Updater for AI Link Genius Pro.
 *
 * Checks for updates from https://github.com/vmai-plugins/ai-link-genius-pro
 * and integrates directly with WordPress Core updater and 1-click admin updates.
 *
 * @package AI_Link_Genius_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles GitHub repository polling, version comparison, download packaging,
 * directory normalization, and seamless in-place updates.
 */
class AILG_Github_Updater {

	/**
	 * GitHub Repository owner/repo.
	 */
	const GITHUB_REPO = 'vmai-plugins/ai-link-genius-pro';

	/**
	 * Default GitHub branch.
	 */
	const GITHUB_BRANCH = 'master';

	/**
	 * Transient key for caching GitHub release info.
	 */
	const TRANSIENT_KEY = 'ailg_github_update_info';

	/**
	 * Cache TTL in seconds (12 hours).
	 */
	const CACHE_TTL = 43200;

	/**
	 * Transient key set for 15 minutes after GitHub probes fail, so
	 * rate-limited or outage conditions do not hang every admin page load.
	 */
	const FAIL_LOCK_KEY = 'ailg_github_update_fail';

	/**
	 * Singleton instance.
	 *
	 * @var AILG_Github_Updater|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return AILG_Github_Updater
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Static initializer helper.
	 */
	public static function init() {
		return self::instance();
	}

	/**
	 * Constructor. Registers WordPress hooks.
	 */
	public function __construct() {
		// Hook into WordPress Plugin Update Checks
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update_transient' ] );
		add_filter( 'site_transient_update_plugins',         [ $this, 'inject_update_transient' ] );
		add_filter( 'plugins_api',                            [ $this, 'plugins_api_info' ], 20, 3 );
		add_filter( 'upgrader_source_selection',              [ $this, 'fix_source_folder' ], 10, 4 );

		// Plugin screen links
		if ( is_admin() ) {
			add_filter( 'plugin_action_links_' . AILG_BASENAME, [ $this, 'action_links' ] );
			add_filter( 'plugin_row_meta',                       [ $this, 'row_meta' ], 10, 2 );

			// AJAX actions
			add_action( 'wp_ajax_ailg_check_github_update',   [ $this, 'ajax_check_update' ] );
			add_action( 'wp_ajax_ailg_perform_github_update', [ $this, 'ajax_perform_update' ] );
		}
	}

	/**
	 * Add "GitHub Updates" link to plugin actions on plugins.php.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function action_links( $links ) {
		$update_url = admin_url( 'admin.php?page=ailg-settings#ailg-section-updates' );
		$new_links  = [
			'github_update' => '<a href="' . esc_url( $update_url ) . '" style="color:#6366f1;font-weight:600;">' . esc_html__( 'GitHub Updates', 'ai-link-genius-pro' ) . '</a>',
		];
		return array_merge( $new_links, $links );
	}

	/**
	 * Add repository link to plugin row meta on plugins.php.
	 *
	 * @param array  $links Existing row meta links.
	 * @param string $file  Plugin file basename.
	 * @return array
	 */
	public function row_meta( $links, $file ) {
		if ( AILG_BASENAME === $file ) {
			$links[] = '<a href="https://github.com/' . esc_attr( self::GITHUB_REPO ) . '" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-external" style="font-size:14px;vertical-align:text-top;"></span> ' . esc_html__( 'GitHub Repo', 'ai-link-genius-pro' ) . '</a>';
		}
		return $links;
	}

	/**
	 * Get GitHub Personal Access Token if configured.
	 */
	public static function token() {
		if ( class_exists( 'AILG_Secrets' ) ) {
			$token = AILG_Secrets::get( 'ailg_github_token' );
			if ( ! empty( $token ) ) {
				return trim( $token );
			}
		}
		if ( defined( 'AILG_GITHUB_TOKEN' ) && AILG_GITHUB_TOKEN ) {
			return trim( AILG_GITHUB_TOKEN );
		}
		if ( defined( 'VMSB_GITHUB_TOKEN' ) && VMSB_GITHUB_TOKEN ) {
			return trim( VMSB_GITHUB_TOKEN );
		}
		if ( defined( 'VMSAI_GITHUB_TOKEN' ) && VMSAI_GITHUB_TOKEN ) {
			return trim( VMSAI_GITHUB_TOKEN );
		}
		return (string) apply_filters( 'ailg_github_updater_token', '' );
	}

	/**
	 * Request headers including User-Agent and optional Authorization Bearer.
	 */
	private static function request_headers() {
		$headers = [
			'Accept'     => 'application/vnd.github.v3+json',
			'User-Agent' => 'AI-Link-Genius-Pro/' . AILG_VERSION . ' (WordPress; +' . home_url() . ')',
		];
		$token = self::token();
		if ( ! empty( $token ) ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		return $headers;
	}

	/**
	 * Fetch the latest release/tag information or raw master metadata from GitHub.
	 *
	 * Multi-tier discovery:
	 * Tier 1: GitHub API Releases (/releases/latest)
	 * Tier 2: GitHub API Tags (/tags)
	 * Tier 3: Raw master file header (raw.githubusercontent.com/.../master/ai-link-genius-pro.php)
	 *
	 * @param bool $force Bypass cache.
	 * @return array
	 */
	public function get_remote_info( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
				return $cached;
			}

			// Back-off window: return cached no-update fallback immediately
			if ( get_transient( self::FAIL_LOCK_KEY ) ) {
				return [
					'version'       => AILG_VERSION,
					'current'       => AILG_VERSION,
					'has_update'    => false,
					'download_url'  => '',
					'release_notes' => '',
					'published_at'  => '',
					'is_release'    => false,
					'repo'          => self::GITHUB_REPO,
					'branch'        => self::GITHUB_BRANCH,
					'last_checked'  => current_time( 'mysql' ),
				];
			}
		}

		$headers        = self::request_headers();
		$latest_version = '';
		$download_url   = '';
		$release_notes  = '';
		$published_at   = '';
		$is_release     = false;

		// ─── Tier 1: GitHub Releases API (/releases/latest) ───────────────────
		$releases_url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
		$res = wp_remote_get( $releases_url, [ 'headers' => $headers, 'timeout' => 12 ] );

		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $body ) && ! empty( $body['tag_name'] ) ) {
				$latest_version = ltrim( (string) $body['tag_name'], 'vV' );
				$release_notes  = (string) ( $body['body'] ?? '' );
				$published_at   = (string) ( $body['published_at'] ?? '' );
				$is_release     = true;

				// Check for attached .zip asset first
				if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
					foreach ( $body['assets'] as $asset ) {
						if ( isset( $asset['browser_download_url'] ) && '.zip' === substr( (string) $asset['browser_download_url'], -4 ) ) {
							$download_url = (string) $asset['browser_download_url'];
							break;
						}
					}
				}

				if ( empty( $download_url ) && ! empty( $body['zipball_url'] ) ) {
					$download_url = (string) $body['zipball_url'];
				}
			}
		}

		// ─── Tier 2: GitHub Tags API (/tags) ─────────────────────────────────
		if ( empty( $latest_version ) ) {
			$tags_url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/tags';
			$tags_res = wp_remote_get( $tags_url, [ 'headers' => $headers, 'timeout' => 12 ] );

			if ( ! is_wp_error( $tags_res ) && 200 === wp_remote_retrieve_response_code( $tags_res ) ) {
				$tags = json_decode( wp_remote_retrieve_body( $tags_res ), true );
				if ( is_array( $tags ) && ! empty( $tags[0]['name'] ) ) {
					$latest_version = ltrim( (string) $tags[0]['name'], 'vV' );
					$download_url   = (string) ( $tags[0]['zipball_url'] ?? '' );
					$release_notes  = sprintf( __( 'Release tag %s from GitHub.', 'ai-link-genius-pro' ), $latest_version );
					$is_release     = true;
				}
			}
		}

		// ─── Tier 3: GitHub Raw File Header (master/main) ─────────────────────
		if ( empty( $latest_version ) ) {
			$raw_url = 'https://raw.githubusercontent.com/' . self::GITHUB_REPO . '/' . self::GITHUB_BRANCH . '/ai-link-genius-pro.php';
			$raw_res = wp_remote_get( $raw_url, [ 'headers' => $headers, 'timeout' => 15 ] );

			if ( ! is_wp_error( $raw_res ) && 200 === wp_remote_retrieve_response_code( $raw_res ) ) {
				$file_contents = wp_remote_retrieve_body( $raw_res );
				if ( preg_match( '/^[ \t\/*#@]*Version:\s*(.+)$/m', $file_contents, $matches ) ) {
					$latest_version = trim( $matches[1] );
					$release_notes  = __( 'Latest updates directly from GitHub master branch.', 'ai-link-genius-pro' );
				}
			}
		}

		// Fallback download URL points directly to master archive
		if ( empty( $download_url ) ) {
			$download_url = 'https://github.com/' . self::GITHUB_REPO . '/archive/refs/heads/' . self::GITHUB_BRANCH . '.zip';
		}

		// Lockout backoff window if probes failed completely
		if ( '' === $latest_version ) {
			set_transient( self::FAIL_LOCK_KEY, 1, 15 * MINUTE_IN_SECONDS );
			$latest_version = AILG_VERSION;
		}

		$has_update = version_compare( $latest_version, AILG_VERSION, '>' );

		$info = [
			'version'       => $latest_version,
			'current'       => AILG_VERSION,
			'has_update'    => $has_update,
			'download_url'  => $download_url,
			'release_notes' => $release_notes,
			'published_at'  => $published_at,
			'is_release'    => $is_release,
			'repo'          => self::GITHUB_REPO,
			'branch'        => self::GITHUB_BRANCH,
			'last_checked'  => current_time( 'mysql' ),
		];

		set_transient( self::TRANSIENT_KEY, $info, self::CACHE_TTL );

		return $info;
	}

	/**
	 * Inject GitHub update info into WordPress core update transient.
	 *
	 * @param object $transient Update plugins transient.
	 * @return object
	 */
	public function inject_update_transient( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		$info = $this->get_remote_info( false );

		if ( ! empty( $info['has_update'] ) ) {
			$obj              = new stdClass();
			$obj->slug        = AILG_SLUG;
			$obj->plugin      = AILG_BASENAME;
			$obj->new_version = $info['version'];
			$obj->url         = 'https://github.com/' . self::GITHUB_REPO;
			$obj->package     = $info['download_url'];
			$obj->icons       = [
				'1x' => AILG_URL . 'assets/images/icon-128.png',
				'2x' => AILG_URL . 'assets/images/icon-256.png',
			];

			$transient->response[ AILG_BASENAME ] = $obj;
		} else {
			$item              = new stdClass();
			$item->slug        = AILG_SLUG;
			$item->plugin      = AILG_BASENAME;
			$item->new_version = AILG_VERSION;
			$item->url         = 'https://github.com/' . self::GITHUB_REPO;
			$item->package     = '';
			$transient->no_update[ AILG_BASENAME ] = $item;
		}

		return $transient;
	}

	/**
	 * Provide detailed plugin information modal for WordPress updates screen.
	 *
	 * @param false|object|array $result Default result.
	 * @param string             $action Action being performed.
	 * @param object             $args   Arguments.
	 * @return false|object
	 */
	public function plugins_api_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || AILG_SLUG !== $args->slug ) {
			return $result;
		}

		$info = $this->get_remote_info( false );

		$res                = new stdClass();
		$res->name          = 'AI Link Genius Pro';
		$res->slug          = AILG_SLUG;
		$res->version       = $info['version'];
		$res->author        = '<a href="https://ailinkgenius.com">AI Link Genius</a>';
		$res->homepage      = 'https://github.com/' . self::GITHUB_REPO;
		$res->download_link = $info['download_url'];
		$res->sections      = [
			'description' => '<p>The most advanced AI-powered internal linking plugin for WordPress. Features OpenAI, Google Gemini, OpenRouter & Ollama integration, smart automation, semantic matching, shared ecosystem key vault, striking distance booster, and anchor text cannibalization radar.</p>',
			'changelog'   => ! empty( $info['release_notes'] ) ? nl2br( esc_html( $info['release_notes'] ) ) : '<p>Updates and refinements synchronized from GitHub repository.</p>',
		];

		return $res;
	}

	/**
	 * Rename the extracted GitHub zipball directory to 'ai-link-genius-pro'.
	 *
	 * When GitHub creates zipballs, they unpack into directories like
	 * `vmai-plugins-ai-link-genius-pro-7b2aa3f` or `ai-link-genius-pro-master`.
	 * WordPress needs the folder to match the existing plugin directory `ai-link-genius-pro`.
	 *
	 * @param string      $source        Path on local filesystem for extracted archive.
	 * @param string      $remote_source Remote source path.
	 * @param WP_Upgrader $upgrader      WP_Upgrader instance.
	 * @param array       $hook_extra    Extra hook data.
	 * @return string
	 */
	public function fix_source_folder( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		global $wp_filesystem;

		if ( ! is_object( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$is_our_plugin = false;
		if ( isset( $hook_extra['plugin'] ) && AILG_BASENAME === $hook_extra['plugin'] ) {
			$is_our_plugin = true;
		} elseif ( isset( $hook_extra['slug'] ) && AILG_SLUG === $hook_extra['slug'] ) {
			$is_our_plugin = true;
		} else {
			$dir = basename( untrailingslashit( $source ) );
			$is_our_plugin = (
				'ai-link-genius-pro' === $dir
				|| 0 === strpos( $dir, 'vmai-plugins-ai-link-genius-pro' )
				|| preg_match( '/^ai-link-genius-pro-[A-Za-z0-9._-]+$/', $dir )
			);
		}

		if ( ! $is_our_plugin ) {
			return $source;
		}

		$corrected_source = trailingslashit( $remote_source ) . 'ai-link-genius-pro/';

		if ( trailingslashit( $source ) === $corrected_source ) {
			return $source;
		}

		$wp_filesystem->move( $source, $corrected_source, true );

		return $corrected_source;
	}

	/**
	 * Perform direct 1-click in-place update from GitHub.
	 *
	 * @return array Result array with status, message, and updated version.
	 */
	public function perform_direct_update() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return [
				'ok'      => false,
				'message' => __( 'You do not have permission to update plugins.', 'ai-link-genius-pro' ),
			];
		}

		include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		include_once ABSPATH . 'wp-admin/includes/file.php';
		include_once ABSPATH . 'wp-admin/includes/misc.php';

		// Force fetch fresh GitHub info
		$info = $this->get_remote_info( true );
		$download_url = $info['download_url'];

		if ( empty( $download_url ) ) {
			return [
				'ok'      => false,
				'message' => __( 'Could not find a valid download package URL from GitHub.', 'ai-link-genius-pro' ),
			];
		}

		global $wp_filesystem;
		if ( ! WP_Filesystem() ) {
			return [
				'ok'      => false,
				'message' => __( 'Unable to initialize WordPress filesystem credentials.', 'ai-link-genius-pro' ),
			];
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		// Set transient for the upgrade run
		$this->inject_update_transient( get_site_transient( 'update_plugins' ) );

		// Perform upgrade using Plugin_Upgrader
		$result = $upgrader->upgrade( AILG_BASENAME, [
			'clear_update_cache' => true,
		] );

		delete_transient( self::TRANSIENT_KEY );
		delete_site_transient( 'update_plugins' );
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}

		// Re-activate plugin if it was deactivated during upgrade
		if ( ! is_plugin_active( AILG_BASENAME ) ) {
			activate_plugin( AILG_BASENAME );
		}

		if ( is_wp_error( $result ) ) {
			return [
				'ok'      => false,
				'message' => $result->get_error_message(),
			];
		}

		if ( false === $result || null === $result ) {
			// Fallback direct package download & replace
			return $this->fallback_manual_update( $download_url );
		}

		return [
			'ok'           => true,
			'message'      => sprintf( __( 'Successfully updated AI Link Genius Pro from GitHub to version %s!', 'ai-link-genius-pro' ), $info['version'] ),
			'version'      => $info['version'],
			'last_checked' => current_time( 'mysql' ),
		];
	}

	/**
	 * Fallback direct downloader in case Plugin_Upgrader is blocked by environment constraints.
	 *
	 * @param string $download_url Package zip url.
	 * @return array
	 */
	private function fallback_manual_update( $download_url ) {
		global $wp_filesystem;

		$token = self::token();
		$args  = [
			'timeout' => 60,
			'headers' => [
				'User-Agent' => 'AI-Link-Genius-Pro/' . AILG_VERSION,
			],
		];
		if ( ! empty( $token ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		// Download temporary package
		$temp_file = download_url( $download_url, 60 );
		if ( is_wp_error( $temp_file ) ) {
			return [
				'ok'      => false,
				'message' => sprintf( __( 'Download failed: %s', 'ai-link-genius-pro' ), $temp_file->get_error_message() ),
			];
		}

		$temp_dir = trailingslashit( WP_CONTENT_DIR . '/upgrade' ) . 'ailg_update_' . time();
		$unzip    = unzip_file( $temp_file, $temp_dir );
		@unlink( $temp_file );

		if ( is_wp_error( $unzip ) ) {
			return [
				'ok'      => false,
				'message' => sprintf( __( 'Unzip failed: %s', 'ai-link-genius-pro' ), $unzip->get_error_message() ),
			];
		}

		$unpacked_dirs = glob( $temp_dir . '/*', GLOB_ONLYDIR );
		$source_dir    = ! empty( $unpacked_dirs ) ? $unpacked_dirs[0] : $temp_dir;
		$destination_dir = AILG_DIR;

		$copied = copy_dir( $source_dir, $destination_dir );
		$wp_filesystem->delete( $temp_dir, true );

		if ( is_wp_error( $copied ) ) {
			return [
				'ok'      => false,
				'message' => sprintf( __( 'Filesystem copy failed: %s', 'ai-link-genius-pro' ), $copied->get_error_message() ),
			];
		}

		// Read new version from updated file
		$new_plugin_data = get_plugin_data( AILG_FILE, false, false );
		$new_version     = $new_plugin_data['Version'] ?? AILG_VERSION;

		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}

		if ( ! is_plugin_active( AILG_BASENAME ) ) {
			activate_plugin( AILG_BASENAME );
		}

		return [
			'ok'           => true,
			'message'      => sprintf( __( 'Updated AI Link Genius Pro from GitHub to version %s!', 'ai-link-genius-pro' ), $new_version ),
			'version'      => $new_version,
			'last_checked' => current_time( 'mysql' ),
		];
	}

	/**
	 * AJAX handler: Check for GitHub update.
	 */
	public function ajax_check_update() {
		check_ajax_referer( 'ailg_nonce', 'nonce' );

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'ai-link-genius-pro' ) ] );
		}

		$info = $this->get_remote_info( true );
		wp_send_json_success( $info );
	}

	/**
	 * AJAX handler: Trigger direct GitHub update.
	 */
	public function ajax_perform_update() {
		check_ajax_referer( 'ailg_nonce', 'nonce' );

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'ai-link-genius-pro' ) ] );
		}

		$result = $this->perform_direct_update();
		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}
}
