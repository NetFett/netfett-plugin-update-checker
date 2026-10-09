<?php
/**
 * Netfett Plugin Update Checker
 *
 * A lightweight, dependency-free update checker for WordPress plugins
 * hosted on GitHub Releases.
 *
 * @package   Netfett\PluginUpdateChecker
 * @author    netfett
 * @license   GPL-2.0-or-later
 * @version   1.0.0
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Netfett_Update_Checker' ) ) {

	/**
	 * Class Netfett_Update_Checker
	 */
	class Netfett_Update_Checker {

		/**
		 * Absolute path to the main plugin file.
		 *
		 * @var string
		 */
		protected string $plugin_file;

		/**
		 * Plugin basename (e.g. 'my-plugin/my-plugin.php').
		 *
		 * @var string
		 */
		protected string $plugin_basename;

		/**
		 * Plugin slug / directory name (e.g. 'my-plugin').
		 *
		 * @var string
		 */
		protected string $plugin_slug;

		/**
		 * GitHub repository in 'owner/repo' format.
		 *
		 * @var string
		 */
		protected string $github_repo;

		/**
		 * Optional GitHub Personal Access Token (for private repositories).
		 *
		 * @var string|null
		 */
		protected ?string $access_token;

		/**
		 * Cache duration in seconds (Default: 6 hours = 21600 seconds).
		 *
		 * @var int
		 */
		protected int $cache_ttl = 21600;

		/**
		 * Transient cache key.
		 *
		 * @var string
		 */
		protected string $cache_key;

		/**
		 * Cached installed plugin version.
		 *
		 * @var string|null
		 */
		protected ?string $installed_version = null;

		/**
		 * Constructor.
		 *
		 * @param string      $plugin_file  Full path to the main plugin file (__FILE__).
		 * @param string      $github_repo  GitHub repository in 'owner/repo' format.
		 * @param string|null $access_token Optional GitHub Personal Access Token.
		 */
		public function __construct( string $plugin_file, string $github_repo, ?string $access_token = null ) {
			$this->plugin_file     = $plugin_file;
			$this->plugin_basename = plugin_basename( $plugin_file );
			$this->plugin_slug     = dirname( $this->plugin_basename );

			// Single-file plugin fallback: use file name without .php extension
			if ( '.' === $this->plugin_slug || empty( $this->plugin_slug ) ) {
				$this->plugin_slug = sanitize_title( basename( $plugin_file, '.php' ) );
			}

			$this->github_repo  = trim( $github_repo, '/' );
			$this->access_token = $access_token;
			$this->cache_key    = 'netfett_upd_' . md5( $this->github_repo );

			// Register WordPress hooks
			$this->init_hooks();
		}

		/**
		 * Registers required WordPress filters and actions.
		 *
		 * @return void
		 */
		protected function init_hooks(): void {
			// 1. Hook into plugin update checks (both on transient write and transient read)
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
			add_filter( 'site_transient_update_plugins', array( $this, 'check_for_update' ) );

			// 2. Plugin information modal ("View version details")
			add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );

			// 3. Normalize extracted GitHub zip folder name
			add_filter( 'upgrader_source_selection', array( $this, 'fix_zip_source_dir' ), 10, 4 );

			// 4. Purge cache on update completion or manual force-check
			add_action( 'upgrader_process_complete', array( $this, 'purge_cache_on_update' ), 10, 2 );
			add_action( 'load-update-core.php', array( $this, 'maybe_purge_on_force_check' ) );

			// 5. Authorize zip download requests for private repositories
			if ( ! empty( $this->access_token ) ) {
				add_filter( 'http_request_args', array( $this, 'authenticate_download' ), 10, 2 );
			}
		}

		/**
		 * Determines the installed version of the plugin.
		 *
		 * @return string Installed version string.
		 */
		public function get_installed_version(): string {
			if ( null !== $this->installed_version ) {
				return $this->installed_version;
			}

			if ( ! function_exists( 'get_plugin_data' ) ) {
				if ( defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
			}

			if ( function_exists( 'get_plugin_data' ) && file_exists( $this->plugin_file ) ) {
				$plugin_data = get_plugin_data( $this->plugin_file, false, false );
				if ( ! empty( $plugin_data['Version'] ) ) {
					$this->installed_version = $plugin_data['Version'];
					return $this->installed_version;
				}
			}

			// Fallback: parse Version header directly from plugin file
			if ( file_exists( $this->plugin_file ) ) {
				$content = file_get_contents( $this->plugin_file, false, null, 0, 8192 );
				if ( preg_match( '/^[ \t\/*#@]*Version\s*:\s*([^\r\n]+)/mi', $content, $matches ) ) {
					$this->installed_version = trim( $matches[1] );
					return $this->installed_version;
				}
			}

			$this->installed_version = '0.0.0';
			return $this->installed_version;
		}

		/**
		 * Queries the latest release via the GitHub REST API.
		 * Uses WordPress transients for caching.
		 *
		 * @param bool $force_check Whether to bypass the transient cache.
		 * @return array|false Release payload or false on failure.
		 */
		public function get_latest_release( $force_check = false ) {
			if ( ! $force_check ) {
				$cached = get_site_transient( $this->cache_key );
				if ( false !== $cached ) {
					return is_array( $cached ) ? $cached : false;
				}
			}

			$api_url = sprintf( 'https://api.github.com/repos/%s/releases/latest', $this->github_repo );

			$args = array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'netfett-plugin-update-checker/' . $this->get_installed_version() . ' (WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url() . ')',
				),
			);

			if ( ! empty( $this->access_token ) ) {
				$args['headers']['Authorization'] = 'Bearer ' . $this->access_token;
			}

			$response = wp_remote_get( $api_url, $args );

			if ( is_wp_error( $response ) ) {
				// Short transient on network failure (15 minutes) to avoid overloading the server.
				set_site_transient( $this->cache_key, 'error', 15 * MINUTE_IN_SECONDS );
				return false;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $status_code ) {
				// Cache 404 or 403 rate-limiting responses for 1 hour.
				set_site_transient( $this->cache_key, 'not_found', HOUR_IN_SECONDS );
				return false;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( empty( $data ) || ! isset( $data['tag_name'] ) ) {
				return false;
			}

			// Normalize version (e.g. 'v1.2.0' -> '1.2.0')
			$version = ltrim( $data['tag_name'], 'vV' );

			// Determine download URL: prefer dedicated .zip release asset, fallback to source zipball
			$download_url = '';
			if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
				foreach ( $data['assets'] as $asset ) {
					if ( isset( $asset['name'] ) && substr( strtolower( $asset['name'] ), -4 ) === '.zip' ) {
						$download_url = $asset['browser_download_url'];
						break;
					}
				}
			}

			if ( empty( $download_url ) && ! empty( $data['zipball_url'] ) ) {
				$download_url = $data['zipball_url'];
			}

			$release_data = array(
				'version'      => $version,
				'tag_name'     => $data['tag_name'],
				'download_url' => $download_url,
				'url'          => isset( $data['html_url'] ) ? $data['html_url'] : '',
				'body'         => isset( $data['body'] ) ? $data['body'] : '',
				'published_at' => isset( $data['published_at'] ) ? $data['published_at'] : '',
			);

			// Cache successfully retrieved release data
			set_site_transient( $this->cache_key, $release_data, $this->cache_ttl );

			return $release_data;
		}

		/**
		 * Filter callback for 'pre_set_site_transient_update_plugins'.
		 * Checks for updates and populates the WordPress update transient.
		 *
		 * @param object $transient WordPress update transient object.
		 * @return object Modified transient object.
		 */
		public function check_for_update( $transient ) {
			if ( ! is_object( $transient ) ) {
				$transient = new \stdClass();
			}

			if ( empty( $transient->response ) ) {
				$transient->response = array();
			}

			if ( empty( $transient->no_update ) ) {
				$transient->no_update = array();
			}

			// Detect force-check trigger (e.g. user clicked "Check again" in WP admin)
			$force_check = false;
			if ( is_admin() && ( isset( $_GET['force-check'] ) || ( isset( $_GET['action'] ) && 'check-update' === $_GET['action'] ) ) ) {
				$force_check = true;
			}

			$release = $this->get_latest_release( $force_check );
			if ( ! $release || empty( $release['version'] ) ) {
				return $transient;
			}

			$installed_version = $this->get_installed_version();
			$assets            = $this->get_plugin_assets();

			$plugin_payload = (object) array(
				'id'            => $this->plugin_basename,
				'slug'          => $this->plugin_slug,
				'plugin'        => $this->plugin_basename,
				'new_version'   => $release['version'],
				'url'           => $release['url'],
				'package'       => $release['download_url'],
				'icons'         => $assets['icons'],
				'banners'       => $assets['banners'],
				'banners_rtl'   => array(),
				'tested'        => '',
				'requires_php'  => '',
				'compatibility' => new \stdClass(),
			);

			if ( version_compare( $installed_version, $release['version'], '<' ) ) {
				// Update available
				$transient->response[ $this->plugin_basename ] = $plugin_payload;
				unset( $transient->no_update[ $this->plugin_basename ] );
			} else {
				// Plugin is up-to-date (standard since WP 5.5)
				$plugin_payload->package = '';
				$transient->no_update[ $this->plugin_basename ] = $plugin_payload;
				unset( $transient->response[ $this->plugin_basename ] );
			}

			return $transient;
		}

		/**
		 * Filter callback for 'plugins_api'.
		 * Supplies plugin information for the WordPress "View version details" modal.
		 *
		 * @param false|object|array $result Default result.
		 * @param string             $action Invoked action (e.g. 'plugin_information').
		 * @param object             $args   Arguments containing the plugin slug.
		 * @return false|object Plugin data object or original result.
		 */
		public function plugin_info( $result, $action, $args ) {
			if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->plugin_slug ) {
				return $result;
			}

			$release = $this->get_latest_release();
			if ( ! $release ) {
				return $result;
			}

			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$plugin_data = get_plugin_data( $this->plugin_file, false, false );

			$response = new \stdClass();
			$response->name          = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : $this->plugin_slug;
			$response->slug          = $this->plugin_slug;
			$response->version       = $release['version'];
			$response->author        = ! empty( $plugin_data['AuthorName'] ) ? $plugin_data['AuthorName'] : '';
			$response->author_profile= ! empty( $plugin_data['AuthorURI'] ) ? $plugin_data['AuthorURI'] : '';
			$response->homepage      = ! empty( $plugin_data['PluginURI'] ) ? $plugin_data['PluginURI'] : $release['url'];
			$response->download_link = $release['download_url'];
			$response->trunk         = $release['download_url'];
			$response->last_updated  = $release['published_at'];

			// Banners and Icons
			$assets = $this->get_plugin_assets();
			if ( ! empty( $assets['banners'] ) ) {
				$response->banners = $assets['banners'];
			}
			if ( ! empty( $assets['icons'] ) ) {
				$response->icons = $assets['icons'];
			}

			// Parse readme sections (Description, Installation, etc.)
			$readme_sections = $this->parse_readme_sections();

			$sections = array();

			// 1. Description: from readme.txt if available, fallback to plugin header
			if ( ! empty( $readme_sections['description'] ) ) {
				$sections['description'] = $readme_sections['description'];
			} elseif ( ! empty( $plugin_data['Description'] ) ) {
				$sections['description'] = wp_kses_post( $plugin_data['Description'] );
			}

			// 2. Installation: from readme.txt
			if ( ! empty( $readme_sections['installation'] ) ) {
				$sections['installation'] = $readme_sections['installation'];
			}

			// 3. Changelog: latest GitHub release notes + historical changelog from readme.txt
			$changelog_parts = array();
			if ( ! empty( $release['body'] ) ) {
				$changelog_parts[] = '<h4>Version ' . esc_html( $release['version'] ) . '</h4>' . $this->parse_markdown( $release['body'] );
			}
			if ( ! empty( $readme_sections['changelog'] ) ) {
				$changelog_parts[] = $readme_sections['changelog'];
			}

			if ( ! empty( $changelog_parts ) ) {
				$sections['changelog'] = implode( "<hr style='margin: 25px 0;' />\n", $changelog_parts );
			}

			$response->sections = $sections;

			return $response;
		}

		/**
		 * Reads and parses sections from the plugin's readme.txt (or README.md) file.
		 *
		 * @return array<string, string> Associative array of formatted sections.
		 */
		protected function parse_readme_sections(): array {
			$readme_file = dirname( $this->plugin_file ) . '/readme.txt';
			if ( ! file_exists( $readme_file ) ) {
				$readme_file = dirname( $this->plugin_file ) . '/README.md';
			}

			if ( ! file_exists( $readme_file ) ) {
				return array();
			}

			$content = file_get_contents( $readme_file );
			if ( empty( $content ) ) {
				return array();
			}

			$sections = array();

			// Split sections by == Section Name == (exactly 2 equals signs)
			if ( preg_match_all( '/^==\s*([^=\r\n]+?)\s*==\s*$(.*?)(?=^==\s*[^=\r\n]+?\s*==|\z)/ms', $content, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$title = sanitize_title( trim( $match[1] ) );
					$body  = trim( $match[2] );
					if ( ! empty( $title ) && ! empty( $body ) ) {
						$sections[ $title ] = $this->parse_markdown( $body );
					}
				}
			}

			return $sections;
		}

		/**
		 * Lightweight, safe Markdown-to-HTML converter for release notes and readmes.
		 *
		 * @param string $text Raw Markdown text.
		 * @return string Formatted HTML string.
		 */
		protected function parse_markdown( string $text ): string {
			$text = trim( $text );
			if ( empty( $text ) ) {
				return '';
			}

			// Headings: === H4 ===, == H3 ==, = Version =, ### H4, ## H3
			$text = preg_replace( '/^===\s*(.*?)\s*===$/m', '<h4>$1</h4>', $text );
			$text = preg_replace( '/^==\s*(.*?)\s*==$/m', '<h3>$1</h3>', $text );
			$text = preg_replace( '/^=\s*(.*?)\s*=\s*$/m', '<h4>Version $1</h4>', $text );
			$text = preg_replace( '/^###\s*(.*?)$/m', '<h4>$1</h4>', $text );
			$text = preg_replace( '/^##\s*(.*?)$/m', '<h3>$1</h3>', $text );

			// Bold, italic, and inline code
			$text = preg_replace( '/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text );
			$text = preg_replace( '/\*(.*?)\*/', '<em>$1</em>', $text );
			$text = preg_replace( '/`(.*?)`/', '<code>$1</code>', $text );

			// Bullet lists (- or *)
			$lines   = explode( "\n", str_replace( "\r", '', $text ) );
			$in_list = false;
			$output  = array();

			foreach ( $lines as $line ) {
				if ( preg_match( '/^[*\\-]\s+(.*)$/', trim( $line ), $m ) ) {
					if ( ! $in_list ) {
						$output[] = '<ul style="margin: 8px 0 16px 20px; list-style-type: disc;">';
						$in_list  = true;
					}
					$output[] = '<li>' . $m[1] . '</li>';
				} else {
					if ( $in_list ) {
						$output[] = '</ul>';
						$in_list  = false;
					}
					$output[] = $line;
				}
			}
			if ( $in_list ) {
				$output[] = '</ul>';
			}

			$html = implode( "\n", $output );
			return wpautop( $html );
		}

		/**
		 * Discovers plugin banners and icons from the assets/ directory.
		 *
		 * @return array{banners: array<string, string>, icons: array<string, string>}
		 */
		protected function get_plugin_assets(): array {
			$plugin_dir = dirname( $this->plugin_file );
			$assets     = array(
				'banners' => array(),
				'icons'   => array(),
			);

			$banner_candidates = array(
				'high' => array( 'assets/banner-1544x500.png', 'assets/banner-1544x500.jpg' ),
				'low'  => array( 'assets/banner-772x250.png', 'assets/banner-772x250.jpg' ),
			);

			foreach ( $banner_candidates['high'] as $candidate ) {
				if ( file_exists( $plugin_dir . '/' . $candidate ) ) {
					$assets['banners']['high'] = plugins_url( $candidate, $this->plugin_file );
					break;
				}
			}
			foreach ( $banner_candidates['low'] as $candidate ) {
				if ( file_exists( $plugin_dir . '/' . $candidate ) ) {
					$assets['banners']['low'] = plugins_url( $candidate, $this->plugin_file );
					break;
				}
			}
			if ( ! empty( $assets['banners']['high'] ) && empty( $assets['banners']['low'] ) ) {
				$assets['banners']['low'] = $assets['banners']['high'];
			}

			$icon_candidates = array(
				'2x' => array( 'assets/icon-256x256.png', 'assets/icon-256x256.jpg' ),
				'1x' => array( 'assets/icon-128x128.png', 'assets/icon-128x128.jpg' ),
			);

			foreach ( $icon_candidates['2x'] as $candidate ) {
				if ( file_exists( $plugin_dir . '/' . $candidate ) ) {
					$assets['icons']['2x'] = plugins_url( $candidate, $this->plugin_file );
					break;
				}
			}
			foreach ( $icon_candidates['1x'] as $candidate ) {
				if ( file_exists( $plugin_dir . '/' . $candidate ) ) {
					$assets['icons']['1x'] = plugins_url( $candidate, $this->plugin_file );
					break;
				}
			}
			if ( ! empty( $assets['icons']['2x'] ) && empty( $assets['icons']['1x'] ) ) {
				$assets['icons']['1x'] = $assets['icons']['2x'];
			}

			return $assets;
		}

		/**
		 * Filter callback for 'upgrader_source_selection'.
		 * Normalizes the extracted GitHub zip folder to the expected plugin slug.
		 * GitHub packages archives as '{repo}-{tag}' or '{repo}-{commit}'.
		 *
		 * @param string       $source        Path to the unzipped temporary folder.
		 * @param string       $remote_source Remote source path.
		 * @param \WP_Upgrader $upgrader      Upgrader instance.
		 * @param array        $hook_extra    Extra upgrade parameters.
		 * @return string|\WP_Error Corrected destination directory or WP_Error.
		 */
		public function fix_zip_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
			global $wp_filesystem;

			// Intervene only if updating this exact plugin
			if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
				return $source;
			}

			$correct_source = trailingslashit( $remote_source ) . trailingslashit( $this->plugin_slug );

			// Already correctly named
			if ( trailingslashit( $source ) === $correct_source ) {
				return $source;
			}

			// Rename extracted folder
			$renamed = $wp_filesystem->move( $source, $correct_source, true );
			if ( ! $renamed ) {
				return new \WP_Error(
					'netfett_rename_failed',
					sprintf( 'Failed to rename extracted directory from "%s" to "%s".', basename( $source ), $this->plugin_slug )
				);
			}

			return $correct_source;
		}

		/**
		 * Sets the cache time-to-live in seconds (Default: 21600 = 6 hours).
		 *
		 * @param int $ttl Cache duration in seconds.
		 * @return $this
		 */
		public function set_cache_ttl( $ttl ) {
			$this->cache_ttl = (int) $ttl;
			return $this;
		}

		/**
		 * Manually purges the transient cache.
		 *
		 * @return bool True if deleted, false otherwise.
		 */
		public function purge_cache() {
			return delete_site_transient( $this->cache_key );
		}

		/**
		 * Adds authorization headers for downloading release zip files from private repositories.
		 *
		 * @param array  $args Request arguments.
		 * @param string $url  Target URL.
		 * @return array Modified request arguments.
		 */
		public function authenticate_download( $args, $url ) {
			if ( ! empty( $this->access_token ) && false !== strpos( $url, 'api.github.com/repos/' . $this->github_repo ) ) {
				$args['headers']['Authorization'] = 'Bearer ' . $this->access_token;
				$args['headers']['Accept']        = 'application/octet-stream';
			}
			return $args;
		}

		/**
		 * Purges transient cache when user triggers a manual check in WP admin.
		 *
		 * @return void
		 */
		public function maybe_purge_on_force_check(): void {
			if ( ! empty( $_GET['force-check'] ) ) {
				$this->purge_cache();
			}
		}

		/**
		 * Purges the update cache once the plugin update has completed.
		 *
		 * @param \WP_Upgrader $upgrader   Upgrader instance.
		 * @param array        $hook_extra Extra hook arguments.
		 * @return void
		 */
		public function purge_cache_on_update( $upgrader, $hook_extra ) {
			if (
				isset( $hook_extra['action'], $hook_extra['type'] )
				&& 'update' === $hook_extra['action']
				&& 'plugin' === $hook_extra['type']
				&& isset( $hook_extra['plugins'] )
				&& in_array( $this->plugin_basename, (array) $hook_extra['plugins'], true )
			) {
				delete_site_transient( $this->cache_key );
			}
		}
	}
}
