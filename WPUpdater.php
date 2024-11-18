<?php

namespace EverPress;

if ( class_exists( 'EverPress\WPUpdater' ) ) {
	return;
}


class WPUpdater {

	private static $instance = null;
	private static $plugins  = array();

	private function __construct() {

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ), PHP_INT_MAX );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), PHP_INT_MAX, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'rename_github_zip' ), PHP_INT_MAX, 4 );
	}

	public static function add( $slug = null, $args = array() ) {

		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		if ( isset( $slug ) && ! empty( $slug ) ) {

			if ( isset( self::$plugins[ $slug ] ) ) {
				_doing_it_wrong( __METHOD__, 'Plugin already registered', '1.0' );
			} else {
				self::$plugins[ $slug ] = wp_parse_args( $args, self::default_args() );
				register_activation_hook( $slug, array( self::$instance, 'register_activation_hook' ) );
				register_deactivation_hook( $slug, array( self::$instance, 'register_deactivation_hook' ) );
			}
		}

		return self::$instance;
	}

	public static function register_deactivation_hook() {
		$slug = str_replace( 'deactivate_', '', current_filter() );
	}

	public static function register_activation_hook( $network_wide ) {

		$slug = str_replace( 'activate_', '', current_filter() );
		// needs to be static
		register_uninstall_hook( WP_PLUGIN_DIR . '/' . $slug, array( __CLASS__, 'register_uninstall_hook' ) );
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

	private static function default_args() {
		return array( 'readme' => 'README.md' );
	}


	private function get_plugin_args( $slug ) {

		$options = get_option( 'wp_updater_plugins', array() );

		if ( ! isset( $options[ $slug ] ) ) {
			$options[ $slug ] = array();
		} else {
			// check if the data is still valid
			if ( isset( $options[ $slug ]['_last_updated'] ) && $options[ $slug ]['_last_updated'] > strtotime( '5 min' ) ) {
				return $options[ $slug ];
			}
		}

		$plugin_file = WP_PLUGIN_DIR . '/' . $slug;
		$plugin_data = get_plugin_data( $plugin_file );

		// fetching data from github
		$repo        = $this->get_repo( $slug );
		$readme      = $this->get_readme( $slug );
		$remote_info = $this->get_remote_info( $slug );

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

		$assets  = $this->get_assets( $slug );
		$icons   = array();
		$banners = array();
		foreach ( $assets as $asset ) {
			if ( strpos( $asset->name, 'icon' ) !== false ) {
				$icons['default'] = $asset->download_url;
			}
			if ( strpos( $asset->name, 'banner' ) !== false ) {
				$banners['low']  = $asset->download_url;
				$banners['high'] = $asset->download_url;
			}
		}

		$args = array(
			'_last_updated'  => time(),
			'name'           => $plugin_data['Name'],
			'version'        => $plugin_data['Version'],
			'author'         => $repo->owner->login,
			'homepage'       => $repo->html_url,
			'author_profile' => $repo->homepage,
			'new_version'    => $remote_info->tag_name,
			'last_updated'   => $repo->updated_at,
			'added'          => $repo->created_at,
			'package'        => $package,
			'icons'          => $icons,
			'banners'        => $banners,
			'changelog'      => $remote_info->body,
			'url'            => $remote_info->html_url,
			'readme'         => $readme,

		);

		$options[ $slug ] = $args;

		update_option( 'wp_updater_plugins', $options, false );

		return $options[ $slug ];
	}


	public function check_for_update( $transient ) {

		if ( ! isset( $transient->checked ) ) {
			// return $transient;
		}

		// iterate through all registererd plugins
		foreach ( self::$plugins as $slug => $args ) {

			if ( empty( $transient->checked[ $slug ] ) ) {
				// continue;
			}

			$plugin_args = $this->get_plugin_args( $slug );

			if ( $plugin_args && version_compare( $plugin_args['version'], $plugin_args['new_version'], '<' ) ) {

				// https://github.com/WordPress/wordpress-develop/blob/2e5e2131a145e593173a7b2c57fb84fa93deabba/src/wp-admin/update-core.php#L514

				$plugin_data = array(
					'slug'           => $slug,
					'new_version'    => $plugin_args['new_version'],
					'url'            => $plugin_args['url'],
					'package'        => $plugin_args['package'],
					'upgrade_notice' => 'Upgrade to the latest version for new features and bugfixes.',
					'requires'       => '5.8',
					'icons'          => $plugin_args['icons'],
					'banners'        => $plugin_args['banners'],
					'banners_rtl'    => array(
						'1x' => 'https://via.placeholder.com/772x250',
						'2x' => 'https://via.placeholder.com/1544x600',
					),

				);

				$transient->response[ $slug ] = (object) $plugin_data;

			}
		}

		return $transient;
	}

	public function plugin_info( $result, $action, $args ) {

		if ( $action !== 'plugin_information' ) {
			return $result;
		}

				// iterate through all registererd plugins
		foreach ( self::$plugins as $slug => $plugin_args ) {
			if ( $args->slug !== $slug ) {
				continue;
			}

			$plugin_args = $this->get_plugin_args( $slug );

			$section = wp_parse_args( $plugin_args['readme']['sections'], array() );

			// from https://github.com/WordPress/wordpress-develop/blob/412658097d7a71f16a4662f5a23cfed067b356d0/src/wp-admin/includes/plugin-install.php#L10
			$result = (object) array(

				'name'           => $plugin_args['name'],

				// 'description'       => $repo->description,
				// 'short_description' => $repo->description,
				'slug'           => $slug,
				'version'        => $plugin_args['version'],
				'author'         => $plugin_args['author'],
				'homepage'       => $plugin_args['homepage'],
				'author_profile' => $plugin_args['author_profile'],
				'download_link'  => $plugin_args['package'],
				'sections'       => $section,
				'banners'        => $plugin_args['banners'],
				// 'icons'           => false,
				// 'rating'          => 86,
				// 'ratings'         => true,
				// 'versions'        => array(),
				// 'donate_link'     => 'https://www.paypal.com',
				'last_updated'   => $plugin_args['last_updated'],
				'added'          => $plugin_args['added'],

				// 'active_installs' => 1000,
				// 'contributors'    => 'asdf',

				// 'upgrade_notice'  => 'Upgrade to the latest version for new features and bugfixes.',

				// 'num_ratings'     => 1825,
				// 'support_threads' => 10,
				'tested'         => $plugin_args['readme']['tested'],
				'requires_php'   => $plugin_args['readme']['requires_php'],
				'requires'       => $plugin_args['readme']['requires'],

			);

		}

		return $result;
	}

	private function get_remote_info( $slug ) {

		$plugin = self::$plugins[ $slug ];

		$url = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', $plugin['username'], $plugin['repository'] );

		return $this->request( $url );
	}

	private function get_repo( $slug ) {

		$plugin = self::$plugins[ $slug ];

		$url = sprintf( 'https://api.github.com/repos/%s/%s', $plugin['username'], $plugin['repository'] );

		return $this->request( $url );
	}

	private function get_assets( $slug ) {

		$plugin = self::$plugins[ $slug ];

		$url = sprintf( 'https://api.github.com/repos/%s/%s/contents/.wordpress-org/', $plugin['username'], $plugin['repository'] );

		return $this->request( $url, array(), HOUR_IN_SECONDS * 3 );
	}

	private function get_readme( $slug ) {

		$plugin = self::$plugins[ $slug ];

		$url = sprintf( 'https://api.github.com/repos/%s/%s/contents/README.md', $plugin['username'], $plugin['repository'] );

		$response = $this->request( $url, array(), HOUR_IN_SECONDS * 3 );

		if ( is_wp_error( $response ) ) {
			$url      = sprintf( 'https://api.github.com/repos/%s/%s/contents/readme.txt', $plugin['username'], $plugin['repository'] );
			$response = $this->request( $url, array(), HOUR_IN_SECONDS * 3 );
		}

		if ( ! $response ) {
			return false;
		}

		$data = base64_decode( $response->content );

		include_once __DIR__ . '/ReadmeParser.php';

		$parser = \EverPress\ReadmeParser::parse( $data );

		return $parser->get_data();
	}

	private function request( $url, $headers = array(), $expiration = HOUR_IN_SECONDS ) {
		$cache_key = 'evp_update_' . md5( $url . serialize( $headers ) );
		$cache     = get_transient( $cache_key );

		if ( $cache ) {
			return $cache;
		}

		$default_headers = array();

		$headers = wp_parse_args( $headers, $default_headers );

		$response = wp_remote_get( $url, $headers );

		if ( is_wp_error( $response ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( $response->get_error_message() );
			}
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			wp_admin_notice(
				'WP Updater Error: ' . $body->message . '<br>' . $body->documentation_url,
				array( 'type' => 'error' )
			);
			error_log( print_r( $body, true ) );
			return false;
		}

		// add random sconds to expiration to avoid all requests at the same time
		$expiration += rand( 0, 360 );

		set_transient( $cache_key, $body, $expiration );

		return $body;
	}

	public function rename_github_zip( $source, $remote_source, $upgrader, $extra ) {

		// iterate through all registererd plugins
		foreach ( self::$plugins as $slug => $plugin_args ) {
			// looks like the right file
			if ( strpos( $source, strtolower( $plugin_args['username'] . '-' . $plugin_args['repository'] ) ) !== false ) {

				$slug       = dirname( $extra['plugin'] );
				$new_source = dirname( $source ) . '/' . trailingslashit( $slug );
				if ( move_dir( $source, $new_source ) ) {
					return $new_source;
				}
			}
		}

		return $source;
	}
}
