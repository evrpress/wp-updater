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
				self::$plugins[ $slug ] = $args;
			}
		}

		return self::$instance;
	}


	private function get_plugin_args( $slug ) {

		$options = get_option( 'evp_plugin_args', array() );

		if ( ! isset( $options[ $slug ] ) ) {
			$options[ $slug ] = array();
		}

		$plugin_file = WP_PLUGIN_DIR . '/' . $slug;
		$plugin_data = get_plugin_data( $plugin_file );

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

		$args             = array(
			'version' => $plugin_data['Version'],
			'icons'   => $icons,
			'banners' => $banners,
		);
		$options[ $slug ] = $args;

		update_option( 'evp_plugin_args', $options );

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
			$remote_info = $this->get_remote_info( $slug );

			if ( $remote_info && version_compare( $plugin_args['version'], $remote_info->tag_name, '<' ) ) {
				$repo = $this->get_repo( $slug );

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

				// https://github.com/WordPress/wordpress-develop/blob/2e5e2131a145e593173a7b2c57fb84fa93deabba/src/wp-admin/update-core.php#L514

				$plugin_data = array(
					'id'             => $remote_info->id, // maybe optional
					'slug'           => $slug,
					'new_version'    => $remote_info->tag_name,
					'url'            => $remote_info->html_url,
					'package'        => $package,
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

			$remote_info = $this->get_remote_info( $slug );
			if ( ! $remote_info ) {
				continue;
			}

			$repo = $this->get_repo( $slug );
			if ( ! $repo ) {
				continue;
			}

			$plugin_args = $this->get_plugin_args( $slug );

			error_log( print_r( $plugin_args, true ) );

			// from https://github.com/WordPress/wordpress-develop/blob/412658097d7a71f16a4662f5a23cfed067b356d0/src/wp-admin/includes/plugin-install.php#L10
			$result = (object) array(

				'name'            => $repo->name,

				// 'description'       => $repo->description,
				// 'short_description' => $repo->description,
				'slug'            => $slug,
				'version'         => $remote_info->tag_name,
				'author'          => $repo->owner->login,
				'homepage'        => $repo->html_url,
				'author_profile'  => $repo->homepage,
				'download_link'   => $remote_info->zipball_url,
				'sections'        => array(
					'description' => wpautop( $remote_info->body ),
					'github'      => wpautop( $remote_info->body ),
				),
				'banners'         => $plugin_args['banners'],
				'icons'           => array(
					'default' => 'https://via.placeholder.com/128x128',
					'2x'      => 'https://via.placeholder.com/256x256',
				),
				'rating'          => 86,
				'ratings'         => true,
				// 'versions'        => array(),
				'donate_link'     => 'https://www.paypal.com',
				'last_updated'    => $repo->updated_at,
				'added'           => $repo->created_at,
				'active_installs' => 1000,
				// 'contributors'    => 'asdf',

				'upgrade_notice'  => 'Upgrade to the latest version for new features and bugfixes.',

				'num_ratings'     => 1825,
				'support_threads' => 10,
				'tested'          => '6.7',
				'requires_php'    => '7.4',
				'requires'        => '5.8',

			);

		}

		return $result;
	}

	private function get_remote_info( $slug ) {

		$plugin_args = self::$plugins[ $slug ];

		$url = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', $plugin_args['username'], $plugin_args['repository'] );

		return $this->request( $url );
	}

	private function get_repo( $slug ) {

		$plugin_args = self::$plugins[ $slug ];

		$url = sprintf( 'https://api.github.com/repos/%s/%s', $plugin_args['username'], $plugin_args['repository'] );

		return $this->request( $url );
	}

	private function get_assets( $slug ) {

		$plugin_args = self::$plugins[ $slug ];

		$url = sprintf( 'https://api.github.com/repos/%s/%s/contents/.wordpress-org/', $plugin_args['username'], $plugin_args['repository'] );

		return $this->request( $url, array(), HOUR_IN_SECONDS * 3 );
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
