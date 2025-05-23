<?php


namespace EverPress;

if ( ! class_exists( 'EverPress\WPUpdater' ) ) {

	class WPUpdater {

		private static $instance = null;
		private static $plugins  = array();
		private $version         = '0.1.8';

		private function __construct() {

			// hook into the update check
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ), PHP_INT_MAX );

			// for the plugin information screen
			add_filter( 'plugins_api', array( $this, 'plugin_info' ), PHP_INT_MAX, 3 );

			// filenames need to be cleaned up
			add_filter( 'upgrader_source_selection', array( $this, 'rename_github_zip' ), PHP_INT_MAX, 4 );

			// handle the switch action on the plugins page
			add_action( 'load-plugins.php', array( $this, 'handle_actions' ) );

			// handle the post install and cleanup
			add_filter( 'upgrader_post_install', array( $this, 'upgrader_post_install' ), PHP_INT_MAX, 3 );

			// hook into download to add the token if needed
			add_filter( 'upgrader_pre_download', array( &$this, 'upgrader_pre_download' ), 100, 4 );

			// check for updates on the version check
			add_action( 'wp_version_check', array( $this, 'self_check' ) );
		}

		public function upgrader_pre_download( $res, $package, $upgrader, $hook_extra ) {

			// no need to add it if it's not defined
			if ( ! defined( 'GITHUB_TOKEN' ) ) {
				return $res;
			}

			if ( ! isset( $hook_extra['plugin'] ) ) {
				return $res;
			}

			if ( isset( self::$plugins[ $hook_extra['plugin'] ] ) ) {
				add_filter( 'http_request_args', array( &$this, 'add_github_token_to_headers' ), 100, 2 );
			}

			return $res;
		}

		public function add_github_token_to_headers( $args, $url ) {

			if ( strpos( $url, 'github.com' ) !== false ) {
				$args['headers']['Authorization'] = 'token ' . \GITHUB_TOKEN;
				$args['headers']['Accept']        = 'application/octet-stream';
			}
			remove_filter( 'http_request_args', array( &$this, 'add_github_token_to_headers' ), 100, 2 );

			return $args;
		}


		public static function add( $slug = null, $args = array() ) {

			if ( self::$instance === null ) {
				self::$instance = new self();
			}

			if ( ! $slug ) {
				return self::$instance;
			}

			// allow to pass only the repository
			if ( is_string( $args ) ) {
				$args = array( 'repository' => $args );
			}

			if ( isset( self::$plugins[ $slug ] ) ) {
				_doing_it_wrong( __METHOD__, 'Plugin already registered', '1.0' );
			} else {
				self::$plugins[ $slug ] = wp_parse_args( $args, self::default_args() );
				register_activation_hook( $slug, array( self::$instance, 'register_activation_hook' ) );
				register_deactivation_hook( $slug, array( self::$instance, 'register_deactivation_hook' ) );

			}

			return self::$instance;
		}


		private static function default_args() {
			return array();
			return array( 'upgrade_notice' => true );
		}


		/*
		 * Get the plugin data from the plugin file
		 *
		 * @param string $slug
		 * @return array
		 */
		private function get_plugin_data( $slug ) {

			$plugin_file = WP_PLUGIN_DIR . '/' . $slug;
			$plugin_data = get_plugin_data( $plugin_file );

			return $plugin_data;
		}


		/**
		 * Get the options from the database
		 *
		 * @return array
		 */
		private function get_option() {
			$options = get_option( 'wp_updater_plugins', array() );
			if ( ! is_array( $options ) ) {
				$options = array();
			}
			return $options;
		}


		/**
		 * Get the plugin_arguments from the database
		 *
		 * @return array
		 */
		private function get_plugin_args( $slug ) {
			$options = $this->get_option();

			return isset( $options[ $slug ] ) ? $options[ $slug ] : null;
		}

		private function update_plugin_args( $slug, $plugin_args ) {

			$options = $this->get_option();

			if ( ! isset( $options[ $slug ] ) ) {
				$options[ $slug ] = array();
			}

			$options[ $slug ] = wp_parse_args( $plugin_args, $options[ $slug ] );
			// $options[ $slug ] = $options[ $slug ];

			update_option( 'wp_updater_plugins', $options, false );

			return $options[ $slug ];
		}


		/**
		 * Prepare the plugin arguments
		 *
		 * @param string $slug
		 * @return array
		 */
		private function prepare_plugin_args( $slug ) {

			$options = $this->get_option();

			if ( ! isset( $options[ $slug ] ) ) {
				$options[ $slug ] = array();
			}

			// check if the data is still valid
			if ( isset( $options[ $slug ]['last_updated'] ) && time() - $options[ $slug ]['last_updated'] < 60 ) {
				return $options[ $slug ];
			}

			// basic info should be always there (offline)
			$plugin_data = $this->get_plugin_data( $slug );

			$update_info = array(
				'name'         => $plugin_data['Name'],
				'version'      => $plugin_data['Version'],
				'author'       => $plugin_data['Author'],
				'homepage'     => $plugin_data['PluginURI'],
				'requires'     => $plugin_data['RequiresWP'],
				'requires_php' => $plugin_data['RequiresPHP'],
				'tested'       => null,
				'icons'        => array(),
				'banners'      => array(),
			);

			$repo = $this->get_repo( $slug );

			if ( is_wp_error( $repo ) ) {
				$this->error( $slug, $repo->get_error_message(), true );
				return $options[ $slug ];
			}

			if ( $repo ) {
				$update_info['last_updated'] = $repo->updated_at;
				$update_info['added']        = $repo->created_at;
			}

			// 'repository_url'         => $plugin_args['repository_url'],
			// 'commercial_support_url' => $plugin_args['commercial_support_url'],
			// 'donate_link'            => $plugin_args['donate_link'],
			// 'preview_link'           => $plugin_args['preview_link'],

			$remote_info = $this->get_remote_info( $slug );

			if ( $remote_info ) {
				$update_info['new_version'] = $remote_info->tag_name;
				$update_info['changelog']   = $remote_info->body;
				$update_info['url']         = $remote_info->html_url;

				// get the package
				$package = $remote_info->zipball_url;

				// preferable from the assets
				// when private use the zipball_url
				if ( isset( $remote_info->assets ) ) {
					foreach ( $remote_info->assets as $asset ) {
						// must be a zip file
						// TODO: check if the the file is the right one
						// also not accessible with a private repo
						if ( $asset->content_type === 'application/octet-stream' ) {
							$package = $asset->url;
							break;
						}
					}
				}

				$update_info['package'] = $package;

			}

			$assets = $this->get_assets( $slug );

			if ( $assets && ! is_wp_error( $assets ) ) {
				$icons   = array();
				$banners = array();
				foreach ( (array) $assets as $asset ) {
					if ( strpos( $asset->name, 'icon' ) !== false ) {
						$icons['default'] = $asset->download_url;
					}
					if ( strpos( $asset->name, 'banner' ) !== false ) {
						$banners['low']  = $asset->download_url;
						$banners['high'] = $asset->download_url;
					}
				}
				$update_info['icons']   = $icons;
				$update_info['banners'] = $banners;

			}

			// new version available or not clear yet
			if ( ! isset( $update_info['new_version'] ) || version_compare( $update_info['version'], $update_info['new_version'], '<' ) ) {
				$readme = $this->get_readme( $slug );
			} else {
				$readme = $this->get_local_readme( $slug );
			}

			if ( is_wp_error( $readme ) ) {
				$this->error( $slug, $readme->get_error_message(), true );
				// return $options[ $slug ];
			} else {
				$update_info['requires']     = $readme['requires'];
				$update_info['requires_php'] = $readme['requires_php'];
				$update_info['tested']       = $readme['tested'];
			}

			// reload as it may have changed
			$old_data = $this->get_option();

			$options[ $slug ] = array(
				// 'version'      => $this->version,
				'repository'   => $options[ $slug ]['repository'],
				'last_updated' => time(),
				'serve_from'   => 'github',
				'update_info'  => $update_info,
			);

			// get args preferable from the plugin fallback to stored data if plugin is disabled
			if ( isset( self::$plugins[ $slug ] ) ) {
				$options[ $slug ]['args'] = self::$plugins[ $slug ];
			} elseif ( isset( $old_data[ $slug ]['args'] ) ) {
				$options[ $slug ]['args'] = $old_data[ $slug ]['args'];
			}

			update_option( 'wp_updater_plugins', $options, false );

			return $update_info;
		}


		public function set_plugin_arg( $slug, $key, $value ) {

			$options = $this->get_option();

			if ( ! isset( $options[ $slug ] ) ) {
				$options[ $slug ] = array();
			}

			$options[ $slug ][ $key ] = $value;

			// update_option( 'wp_updater_plugins', $options, false );
		}


		public function check_for_update( $transient ) {

			$options = $this->get_option();

			// add missing plugins to options
			$missing_in_options = array_diff_key( self::$plugins, $options );

			foreach ( $missing_in_options as $slug => $plugin ) {
				$options[ $slug ] = $plugin;
				$this->update_plugin_args( $slug, $plugin );
			}

			// iterate through all registererd plugins
			foreach ( $options as $slug => $options ) {
				// foreach (  $options as $slug => $args ) {

				if ( empty( $transient->checked[ $slug ] ) ) {
					// continue;
				}

				// refresh options
				$options = $this->prepare_plugin_args( $slug );

				if ( ! isset( $options['update_info'] ) ) {
					continue;
				}

				$update_info = $options['update_info'];

				$plugin_data = array(
					// 'slug'           => dirname( $slug ), // wouuld be required, but doesn't work
					'slug'        => $slug,
					'new_version' => $update_info['version'],
					'url'         => $update_info['url'],
					'icons'       => $update_info['icons'],

				);

				if ( version_compare( $update_info['version'], $update_info['new_version'], '<' ) ) {

					// https://github.com/WordPress/wordpress-develop/blob/2e5e2131a145e593173a7b2c57fb84fa93deabba/src/wp-admin/update-core.php#L514

						// 'slug'           => dirname( $slug ), // wouuld be required, but doesn't work
					$plugin_data['new_version'] = $update_info['new_version'];
					$plugin_data['package']     = $update_info['package'];
					if ( isset( $options['args']['upgrade_notice'] ) && $options['args']['upgrade_notice'] ) {
						$plugin_data['upgrade_notice'] = $update_info['changelog'];
					}
					$plugin_data['requires']     = $update_info['requires'];
					$plugin_data['requires_php'] = $update_info['requires_php'];
					$plugin_data['tested']       = $update_info['tested'];

					$transient->response[ $slug ] = (object) $plugin_data;

				} else {

					// required for the readme if no update is available
					$transient->no_update[ $slug ] = (object) $plugin_data;
				}
			}

			return $transient;
		}


		/**
		 * Filter the information about a plugin.
		 *
		 * @param object $result
		 * @param string $action
		 * @param object $args
		 *
		 * @return object
		 */
		public function plugin_info( $result, $action, $args ) {

			if ( $action !== 'plugin_information' ) {
				return $result;
			}

			// TODO: Fix
			// $args->slug is onlyplugin folder name. We need to get the whole slug
			// $slug = $args->slug.'/test-plugin.php';
			$slug = $args->slug;

			$plugin_args = $this->get_plugin_args( $slug );

			if ( ! $plugin_args ) {
				return $result;
			}

			$update_info = $plugin_args['update_info'];

			// new version available or not clear yet
			if ( ! isset( $update_info['new_version'] ) || version_compare( $update_info['version'], $update_info['new_version'], '<' ) ) {
				$readme = $this->get_readme( $args->slug );
			} else {
				$readme = $this->get_local_readme( $args->slug );
			}

			// define the default values
			$readme = wp_parse_args(
				$readme,
				array(
					'tested'       => '',
					'requires_php' => '',
					'requires'     => '',
					'sections'     => array(),
				)
			);

			if ( ! is_wp_error( $readme ) ) {
				$section = wp_parse_args( $readme['sections'], array() );
			} else {
				$section = array( 'description' => sprintf( '<div class="notice notice-error"><p>%s</p></div>', __( 'Not able to load Readme file', 'wp-updater' ) ) );
			}

			$repo = $this->get_repo( $slug );

			$contributors = array();

			if ( $repo ) {

				$contributors[] = array(
					'display_name' => $repo->owner->login,
					'profile'      => $repo->owner->html_url,
					'avatar'       => $repo->owner->avatar_url,
				);
			}

			$plugin_data = $this->get_plugin_data( $slug );

			$default = array(
				'homepage'       => $plugin_data['PluginURI'],
				'author'         => $plugin_data['Author'],
				'author_profile' => $plugin_data['AuthorURI'],
				'tested'         => $readme['tested'],
				'requires_php'   => $readme['requires_php'],
				'requires'       => $readme['requires'],
			);

			// from https://github.com/WordPress/wordpress-develop/blob/412658097d7a71f16a4662f5a23cfed067b356d0/src/wp-admin/includes/plugin-install.php#L10
			return (object) wp_parse_args(
				array(

					'name'              => $update_info['name'],
					'slug'              => dirname( $slug ), // makes the update button break
					'slug'              => $slug,

					// 'description'       => $repo->description,
					'short_description' => $repo->description,
					'version'           => $update_info['new_version'],
					'author'            => $update_info['author'],
					'homepage'          => $update_info['homepage'],
					'author_profile'    => $update_info['url'],
					'download_link'     => $update_info['package'],
					'sections'          => $section,
					'banners'           => $update_info['banners'],
					// 'donate_link'     => 'https://www.paypal.com',
					'last_updated'      => $update_info['last_updated'],
					'added'             => $update_info['added'],

					// 'active_installs' => 1000,
					'contributors'      => $contributors,

					// 'rating'          => 90,
					// 'num_ratings'     => 123,

					'tested'            => $readme['tested'],
					'requires_php'      => $readme['requires_php'],
					'requires'          => $readme['requires'],

					// maybe later
					// 'business_model'         => 'freemium',
					// 'repository_url'         => $plugin_args['repository_url'],
					// 'commercial_support_url' => $plugin_args['commercial_support_url'],
					// 'donate_link'            => $plugin_args['donate_link'],
					// 'preview_link'           => $plugin_args['preview_link'],

				),
				$default
			);
		}

		private function get_plugin( $slug ) {

			if ( ! isset( self::$plugins[ $slug ] ) ) {
				return false;
			}

			return wp_parse_args( self::$plugins[ $slug ], self::default_args() );
		}

		private function get_remote_info( $slug ) {

			$plugin_args = $this->get_plugin_args( $slug );

			if ( ! $plugin_args ) {
				return false;
			}

			$url = sprintf( 'https://api.github.com/repos/%s/releases/latest', $plugin_args['repository'] );

			return $this->request( $url, array(), MINUTE_IN_SECONDS );
		}

		private function get_repo( $slug ) {

			$plugin_args = $this->get_plugin_args( $slug );

			if ( ! $plugin_args ) {
				return false;
			}

			$url = sprintf( 'https://api.github.com/repos/%s', $plugin_args['repository'] );

			return $this->request( $url, array(), MINUTE_IN_SECONDS, $slug );
		}

		private function get_assets( $slug ) {

			$plugin_args = $this->get_plugin_args( $slug );

			if ( ! $plugin_args ) {
				return false;
			}

			$url = sprintf( 'https://api.github.com/repos/%s/contents/.wordpress-org/', $plugin_args['repository'] );

			return $this->request( $url, array(), HOUR_IN_SECONDS * 3, $slug );
		}

		private function get_readme( $slug ) {

			$plugin_args = $this->get_plugin_args( $slug );

			if ( ! $plugin_args ) {
				return false;
			}

			// TODO: check if this is correct
			if ( file_exists( WP_PLUGIN_DIR . '/' . dirname( $slug ) . '/README.md' ) ) {
				$file = 'README.md';
			} else {
				$file = 'readme.txt';
			}

			$file = 'README.md';

			$url = sprintf( 'https://api.github.com/repos/%s/contents/%s', $plugin_args['repository'], $file );

			$response = $this->request( $url, array(), HOUR_IN_SECONDS * 3, $slug );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( ! $response ) {
				return $this->get_local_readme( $slug );
			}

			$data = base64_decode( $response->content );

			return $this->parse_readme( $data );
		}

		private function get_local_readme( $slug ) {

			$plugin_file = WP_PLUGIN_DIR . '/' . $slug;
			$folder      = dirname( $plugin_file );

			if ( file_exists( $folder . '/README.md' ) ) {
				$data = file_get_contents( $folder . '/README.md' );
				return $this->parse_readme( $data );
			}
			if ( file_exists( $folder . '/readme.txt' ) ) {
				$data = file_get_contents( $folder . '/readme.txt' );
				return $this->parse_readme( $data );
			}

			return false;
		}


		private function parse_readme( $text ) {

			if ( ! class_exists( 'EverPress\ReadmeParser' ) ) {
				include_once __DIR__ . '/ReadmeParser.php';
			}

			$parser = \EverPress\ReadmeParser::parse( $text );

			return $parser->get_data();
		}


		private function request( $url, $headers = array(), $expiration = HOUR_IN_SECONDS, $slug = null ) {
			$cache_key = 'evp_updatess_' . md5( $url . serialize( $headers ) );
			$cache     = get_transient( $cache_key );

			// serve cached version if possible
			if ( $cache ) {
				return $cache;
			}

			// rate limit
			if ( $rate_limit = get_transient( 'evp_update_rate_limit' ) ) {
				$this->error( $slug, 'Rate limit reached. Try again at ' . wp_date( get_option( 'time_format' ), $rate_limit ) . '.' );
				return false;
			}

			$default_headers = array(
				'Accept'               => 'application/vnd.github+json',
				// https://docs.github.com/en/rest/about-the-rest-api/api-versions
				'X-GitHub-Api-Version' => '2022-11-28',
			);

			// add authentification
			if ( defined( 'GITHUB_TOKEN' ) ) {
				$default_headers['Authorization'] = 'Bearer ' . \GITHUB_TOKEN;
			}

			/**
			 * Filters the headers for a request.
			 *
			 * @param array $headers
			 */
			$headers = apply_filters( 'wp_updater_request_headers', wp_parse_args( $headers, $default_headers ) );

			$response = wp_remote_get(
				$url,
				array(
					'headers'    => $headers,
					'user-agent' => sprintf( 'EverPress/WPUpdater %s', $this->version ),
				)
			);

			// http error or other
			if ( is_wp_error( $response ) ) {
				$this->error( $slug, $response->get_error_message(), true );
				return $response;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );
			$code = wp_remote_retrieve_response_code( $response );

			$headers = wp_remote_retrieve_headers( $response );

			$limit_remaining = $headers['x-ratelimit-remaining'];
			$rate_limit      = $headers['x-ratelimit-reset'];

			if ( $limit_remaining <= 0 ) {
				$this->error( $slug, 'Rate limit reached. Try again in ' . human_time_diff( $rate_limit ), true );
				set_transient( 'evp_update_rate_limit', $rate_limit, $rate_limit - time() );
				wp_admin_notice(
					'WP Updater Error: ' . $body->message . '<br>' . $body->documentation_url,
					array( 'type' => 'error' )
				);
			}

			if ( $code !== 200 ) {

				$expiration = 15;

				$body = new \WP_Error( $code, $body->message );

				// return false;
			} else {
				// add random seconds to expiration to avoid all requests at the same time
				$expiration += rand( 0, 360 );

			}

			// remove any error messages
			delete_transient( 'wp_updater_plugins_error_' . $slug );

			set_transient( $cache_key, $body, $expiration );

			return $body;
		}

		public function rename_github_zip( $source, $remote_source, $upgrader, $extra ) {

			global $wp_filesystem;

			// bail if install is in progress
			if ( isset( $extra['action'] ) && $extra['action'] == 'install' ) {
				return $source;
			}

			if ( ! ( $upgrader instanceof \Plugin_Upgrader ) ) {
				return $source;
			}

			$options = $this->get_option();

			// iterate through all registererd plugins
			foreach ( $options as $slug => $options ) {

				// not our plugin
				if ( $extra['plugin'] != $slug ) {
					continue;
				}

				$new_source = trailingslashit( $remote_source ) . trailingslashit( dirname( $slug ) );

				if ( strtolower( $source ) !== strtolower( $new_source ) ) {
					$wp_filesystem->move( $source, $new_source, true );
				}

				return $new_source;
			}

			return $source;
		}


		public function plugin_action_links( $actions, $slug, $plugin_data, $context ) {

			$plugin_args = $this->get_plugin_args( $slug );

			if ( ! $plugin_args ) {
				return $actions;
			}

			$current  = $plugin_args['serve_from'] ?? 'github';
			$label    = $current === 'github' ? 'Github' : 'WordPress.org';
			$opposite = $current === 'github' ? 'WordPress.org' : 'Github';

			if ( isset( $_REQUEST['switched'] ) ) {
				wp_admin_notice( sprintf( __( 'WP Updater: %1$s is now serverd from %2$s.', 'wp-updater' ), '<strong>' . $plugin_args['update_info']['name'] . '</strong>', $label ), array( 'type' => 'info' ) );
			}

			$link = add_query_arg(
				array(
					'action'   => 'switch',
					'to'       => $current === 'github' ? 'wp' : 'github',
					'plugin'   => $slug,
					'wp_nonce' => wp_create_nonce( 'switch-plugin_' . $slug ),
				)
			);

			$anchor = sprintf( '<a href="%s">%s</a>', esc_url( $link ), sprintf( __( 'Serve from %s', 'wp-update' ), $opposite ) );

			$actions += array(
				'wpupdater_source' => '<details><summary>WP Updater</summary><span>' . sprintf( __( 'Served from %s', 'wp-update' ), $label ) . ' (' . $anchor . ')</span></details>',
			);

			return $actions;
		}

		public function handle_actions() {

			// add a information to the plugin meta row
			add_action( 'after_plugin_row_meta', array( $this, 'plugin_row_meta' ), PHP_INT_MAX, 2 );
			// add a link to the plugin row
			add_filter( 'plugin_action_links', array( $this, 'plugin_action_links' ), PHP_INT_MAX, 4 );

			if ( ! isset( $_REQUEST['action'] ) || $_REQUEST['action'] !== 'switch' ) {
				return;
			}

			if ( ! isset( $_REQUEST['plugin'] ) ) {
				return;
			}

			if ( ! isset( $_REQUEST['wp_nonce'] ) ) {
				return;
			}

			$slug = $_REQUEST['plugin'];

			if ( ! wp_verify_nonce( $_REQUEST['wp_nonce'], 'switch-plugin_' . $slug ) ) {
				return;
			}

			$to = sanitize_key( $_REQUEST['to'] );

			$this->update_plugin_args( $slug, array( 'serve_from' => $to ) );

			$url = remove_query_arg( array( 'action', 'plugin', 'wp_nonce', 'to' ) );
			$url = add_query_arg( 'switched', $to, $url );

			wp_redirect( $url );
			exit;
		}

		public function plugin_row_meta( $slug, $plugin_data ) {

			$plugin_args = $this->get_plugin_args( $slug );

			if ( ! $plugin_args ) {
				return;
			}

			if ( $message = get_transient( 'wp_updater_plugins_error_' . $slug ) ) {
				printf( '<div class="notice notice-error inline notice-alt"><p>%s</p></div>', '[WP Updater] ' . esc_html( $message ) );
			}

			$relative_path = str_replace( ABSPATH, '', __FILE__ );

			printf( '<sup>%s</sup>', '<strong>' . $relative_path . ' ' . $this->version . '</strong>' );
		}


		public function upgrader_post_install( $res, $hook_extra, $result ) {
			if ( ! $res ) {
				return $res;
			}
			if ( ! isset( $hook_extra['plugin'] ) ) {
				return $res;
			}

			$plugin_args = $this->get_plugin_args( $hook_extra['plugin'] );

			// exclude unmanged slugs and bail out
			if ( ! $plugin_args ) {
				return $res;
			}
			// reset and force a refresh of the data
			$this->update_plugin_args( $hook_extra['plugin'], array( 'last_updated' => 0 ) );
			$this->prepare_plugin_args( $hook_extra['plugin'] );

			return $res;
		}


		private function error( $slug, $message, $admin_notice = false ) {

			set_transient( 'wp_updater_plugins_error_' . $slug, $message, DAY_IN_SECONDS );

			$plugin_data = $this->get_plugin_data( $slug );
			$link        = sprintf( '<a href="%s">%s</a>', add_query_arg( 's', dirname( $slug ), admin_url( 'plugins.php' ) ), esc_html( $plugin_data['Name'] ) );

			$error_message = sprintf( '[%s]: %s', $link, $message );

			if ( current_user_can( 'manage_plugins' ) && $admin_notice ) {
				wp_admin_notice( $error_message, array( 'type' => 'error' ) );
			}
		}



		public static function register_deactivation_hook() {
			$slug = str_replace( 'deactivate_', '', current_filter() );
		}

		public static function register_activation_hook( $network_wide ) {

			$slug = str_replace( 'activate_', '', current_filter() );
			// needs to be static
			register_uninstall_hook( WP_PLUGIN_DIR . '/' . $slug, array( __CLASS__, 'register_uninstall_hook' ) );

			// this triggers the first check
			wp_schedule_single_event( time(), 'wp_update_plugins' );
		}

		public static function register_uninstall_hook() {

			$slug = str_replace( 'uninstall_', '', current_filter() );

			// cleanup
			$options = (array) get_option( 'wp_updater_plugins', array() );

			if ( isset( $options[ $slug ] ) ) {
				unset( $options[ $slug ] );
			}

			if ( empty( $options ) ) {
				delete_option( 'wp_updater_plugins' );
			} else {
				update_option( 'wp_updater_plugins', $options, false );
			}
		}

		/**
		 * Run occassianally to check if the plugin needs to be updated
		 *
		 * @return void
		 */
		public function self_check() {

			// don't update if the folder contains a .git folder
			if ( file_exists( __DIR__ . '/.git' ) ) {
				return;
			}

			$url = sprintf( 'https://api.github.com/repos/%s/releases/latest', 'evrpress/wp-updater' );

			$response = $this->request( $url, array(), MINUTE_IN_SECONDS );

			if ( is_wp_error( $response ) ) {
				return;
			}

			$latest_version = $response->tag_name;

			// don't update if the version is the same
			if ( version_compare( $this->version, $latest_version, '>=' ) ) {
				return;
			}

			$download_url = $response->assets[0]->browser_download_url;

			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$temp_file = download_url( $download_url );

			if ( is_wp_error( $temp_file ) ) {
				return;
			}

			$temp_dir = WP_CONTENT_DIR . '/upgrade/' . basename( __DIR__ ) . '-' . uniqid();

			// init fileSystem
			WP_Filesystem();
			global $wp_filesystem;

			wp_mkdir_p( $temp_dir );

			// unzip the file
			$unzipped = unzip_file( $temp_file, $temp_dir );

			if ( is_wp_error( $unzipped ) ) {
				return;
			}

			$wp_filesystem->move( $temp_dir, __DIR__, true );
		}
	}
}
