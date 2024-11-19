<?php

namespace EverPress;

if ( ! class_exists( 'EverPress\WPUpdater' ) ) {

	class WPUpdater {

		private static $instance = null;
		private static $plugins  = array();
		private $version         = '0.1.0';

		private function __construct() {

			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ), PHP_INT_MAX );
			add_filter( 'plugins_api', array( $this, 'plugin_info' ), PHP_INT_MAX, 3 );
			add_filter( 'upgrader_source_selection', array( $this, 'rename_github_zip' ), PHP_INT_MAX, 4 );
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
		}


		private function get_plugin_data( $slug ) {

			$plugin_file = WP_PLUGIN_DIR . '/' . $slug;
			$plugin_data = get_plugin_data( $plugin_file );

			return $plugin_data;
		}


		private function get_plugin_args( $slug ) {
			$options = get_option( 'wp_updater_plugins', array() );

			return isset( $options[ $slug ] ) ? $options[ $slug ] : null;
		}

		private function update_plugin_args( $slug, $plugin_args ) {

			$options = get_option( 'wp_updater_plugins', array() );

			if ( ! isset( $options[ $slug ] ) ) {
				$options[ $slug ] = array();
			}

			$options[ $slug ] = wp_parse_args( $plugin_args, $options[ $slug ] );
			// $options[ $slug ] = $options[ $slug ];

			update_option( 'wp_updater_plugins', $options, false );

			return $options[ $slug ];
		}


		private function prepare_plugin_args( $slug ) {

			$options = get_option( 'wp_updater_plugins', array() );

			if ( ! isset( $options[ $slug ] ) ) {
				$options[ $slug ] = array();
			}

			// check if the data is still valid
			if ( isset( $options[ $slug ]['last_updated'] ) && time() - $options[ $slug ]['last_updated'] < 60 ) {

				// $options[ $slug ] = wp_parse_args( $plugin, $options[ $slug ] );

				return $options[ $slug ];
			}

			$old_data = $options[ $slug ];

			// basic info should be always there (offline)

			$plugin_data = $this->get_plugin_data( $slug );

			$update_info = array(
				'name'     => $plugin_data['Name'],
				'version'  => $plugin_data['Version'],
				'author'   => $plugin_data['Author'],
				'homepage' => $plugin_data['PluginURI'],
			);

			// $plugin = $this->get_plugin( $slug );
			// $update_info = wp_parse_args( $plugin, $update_info );

			$repo = $this->get_repo( $slug );

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

				// preferable from the asstes
				if ( isset( $remote_info->assets ) ) {
					foreach ( $remote_info->assets as $asset ) {
						// must be a zip file
						// TODO: check if the the file is the right one
						if ( $asset->content_type === 'application/octet-stream' ) {
							$package = $asset->browser_download_url;
							break;
						}
					}
				}

				$update_info['package'] = $package;
			}

			$assets = $this->get_assets( $slug );

			if ( $assets ) {
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
			if ( ! $update_info['new_version'] || version_compare( $update_info['version'], $update_info['new_version'], '<' ) ) {
				$readme = $this->get_readme( $slug );
			} else {
				$readme = $this->get_local_readme( $slug );
			}
			if ( $readme ) {
				$update_info['requires']     = $readme['requires'];
				$update_info['tested']       = $readme['tested'];
				$update_info['requires_php'] = $readme['requires_php'];
			}

			$options[ $slug ] = array(
				'version'      => $this->version,
				'repository'   => $options[ $slug ]['repository'],
				'last_updated' => time(),
				'last_error'   => isset( $old_data['last_error'] ) ? $old_data['last_error'] : 'ERROR',
				'update_info'  => $update_info,

				// get args preferable from the plugin fallback to stored data if plugin is disabled
				'args'         => isset( self::$plugins[ $slug ] ) ? self::$plugins[ $slug ] : $old_data['args'], // info wee need to store
			);

			// $this->update_plugin_args( $slug, $options[ $slug ] );

			update_option( 'wp_updater_plugins', $options, false );

			return $update_info;
		}


		public function set_plugin_arg( $slug, $key, $value ) {

			$options = get_option( 'wp_updater_plugins', array() );

			if ( ! isset( $options[ $slug ] ) ) {
				$options[ $slug ] = array();
			}

			$options[ $slug ][ $key ] = $value;

			// update_option( 'wp_updater_plugins', $options, false );
		}


		public function check_for_update( $transient ) {

			if ( ! isset( $transient->checked ) ) {
				// return $transient;
			}
			error_log( print_r( 'check_for_update', true ) );

			$options = get_option( 'wp_updater_plugins', array() );

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

				if ( isset( $update_info['new_version'] ) && version_compare( $update_info['version'], $update_info['new_version'], '<' ) ) {

					// https://github.com/WordPress/wordpress-develop/blob/2e5e2131a145e593173a7b2c57fb84fa93deabba/src/wp-admin/update-core.php#L514

					$plugin_data = array(
						// 'slug'           => dirname( $slug ), // wouuld be required, but doesn't work
						'slug'           => $slug,
						'new_version'    => $update_info['new_version'],
						'url'            => $update_info['url'],
						'package'        => $update_info['package'],
						'upgrade_notice' => $update_info['changelog'],
						'requires'       => $update_info['requires'],
						'requires_php'   => $update_info['requires_php'],
						'tested'         => $update_info['tested'],
						'icons'          => $update_info['icons'],

					);

					$transient->response[ $slug ] = (object) $plugin_data;

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
			if ( ! $update_info['new_version'] || version_compare( $update_info['version'], $update_info['new_version'], '<' ) ) {
				$readme = $this->get_readme( $args->slug );
			} else {
				$readme = $this->get_local_readme( $args->slug );
			}
			if ( $readme ) {
				$section = wp_parse_args( $readme['sections'], array() );
			} else {
				$section = array( 'description' => sprintf( '<div class="notice notice-error"><p>%s</p></div>', __( 'Not able to load Readme file', 'wp-updater' ) ) );
			}

			$repo = $this->get_repo( $slug );

			$contributors = array();

			if ( $repo ) {

				$contributors[] = array(
					'display_name' => $repo->name,
					'profile'      => $repo->owner->html_url,
					'avatar'       => $update_info['icons']['default'],
				);
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
				'last_updated'   => 0,
				'added'          => 0,

			);

			// from https://github.com/WordPress/wordpress-develop/blob/412658097d7a71f16a4662f5a23cfed067b356d0/src/wp-admin/includes/plugin-install.php#L10
			return (object) wp_parse_args(
				array(

					'name'              => $update_info['name'],
					'slug'              => str_replace( '/', '-', $slug ),
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

			return $this->request( $url );
		}

		private function get_repo( $slug ) {

			$plugin_args = $this->get_plugin_args( $slug );

			if ( ! $plugin_args ) {
				return false;
			}

			$url = sprintf( 'https://api.github.com/repos/%s', $plugin_args['repository'] );

			return $this->request( $url, array(), MINUTE_IN_SECONDS * 3, $slug );
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

			if ( file_exists( WP_PLUGIN_DIR . '/' . dirname( $slug ) . '/README.md' ) ) {
				$file = 'README.md';
			} else {
				$file = 'readme.txt';
			}

			$url = sprintf( 'https://api.github.com/repos/%s/contents/%s', $plugin_args['repository'], $file );

			$response = $this->request( $url, array(), HOUR_IN_SECONDS * 3, $slug );

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
			$cache_key = 'evp_update_' . md5( $url . serialize( $headers ) );
			$cache     = get_transient( $cache_key );

			if ( $cache ) {
				return $cache;
			}

			if ( get_transient( 'evp_update_rate_limit' ) ) {
				return false;
			}

			$default_headers = array(
				'Accept'               => 'application/vnd.github.v3+json',
				'X-GitHub-Api-Version' => '2020-01-01',
				'User-Agent'           => 'EverPress/WPUpdater',
			);

			$headers = wp_parse_args( $headers, $default_headers );

			$response = wp_remote_get( $url, $headers );

			if ( is_wp_error( $response ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// error_log( $response->get_error_message() );
				}
				$this->set_plugin_arg( $slug, 'last_error', $response->get_error_message() );
				return false;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );
			$code = wp_remote_retrieve_response_code( $response );

			if ( $code !== 200 ) {

				if ( $code === 403 ) {
					set_transient( 'evp_update_rate_limit', true, 60 );
				}

				$this->set_plugin_arg( $slug, 'last_error', $body->message );

				// error_log( print_r( $response, true ) );
				wp_admin_notice(
					'WP Updater Error: ' . $body->message . '<br>' . $body->documentation_url,
					array( 'type' => 'error' )
				);
				return false;
			}

			// add random sconds to expiration to avoid all requests at the same time
			$expiration += rand( 0, 360 );

			set_transient( $cache_key, $body, $expiration );

			return $body;
		}

		public function rename_github_zip( $source, $remote_source, $upgrader, $extra ) {

			$options = get_option( 'wp_updater_plugins', array() );

			// iterate through all registererd plugins
			foreach ( $options as $slug => $options ) {

				$needle = str_replace( '/', '-', strtolower( $options['repository'] ) );

				// looks like the right file
				if ( strpos( $source, $needle ) !== false ) {

					$slug       = dirname( $extra['plugin'] );
					$new_source = dirname( $source ) . '/' . trailingslashit( $slug );
					if ( move_dir( $source, $new_source ) ) {
						return $new_source;
					}
				}
			}

			return $source;
		}



		public static function register_deactivation_hook() {
			$slug = str_replace( 'deactivate_', '', current_filter() );
		}

		public static function register_activation_hook( $network_wide ) {

			$slug = str_replace( 'activate_', '', current_filter() );
			// needs to be static
			register_uninstall_hook( WP_PLUGIN_DIR . '/' . $slug, array( __CLASS__, 'register_uninstall_hook' ) );

			// this triggers the first check
			wp_schedule_single_event( time(), 'wp_update_plugins', array( 'init' => $slug ) );
		}

		public static function register_uninstall_hook() {

			$slug = str_replace( 'uninstall_', '', current_filter() );

			// cleanup
			$options = get_option( 'wp_updater_plugins', array() );

			if ( isset( $options[ $slug ] ) ) {
				unset( $options[ $slug ] );
			}

			if ( empty( $options ) ) {
				delete_option( 'wp_updater_plugins' );
			} else {
				update_option( 'wp_updater_plugins', $options, false );
			}
		}
	}
}
