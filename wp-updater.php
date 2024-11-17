<?php

namespace EverPress;

die('HERE!!!!!!');

if ( class_exists( __NAMESPACE__ . '\Updater' ) ) {
	return;
}

class Updater {

	private static $instance = null;
	private $username;
	private $repository;
	private $current_version;
	private $plugin_slug;

	private function __construct( $args ) {

		$this->username        = $args['username'];
		$this->repository      = $args['repository'];
		$this->current_version = $args['current_version'];
		$this->plugin_slug     = $args['plugin_slug'];

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ), PHP_INT_MAX );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'rename_github_zip' ), 10, 3 );
	}

	public static function get_instance( $args ) {
		if ( self::$instance === null ) {
			self::$instance = new self( $args );
		}
		return self::$instance;
	}


	public function check_for_update( $transient ) {

		error_log( print_r( __FUNCTION__, true ) );

		if ( empty( $transient->checked[ $this->plugin_slug ] ) ) {
			return $transient;
		}

		$remote_info = $this->get_remote_info();
		// error_log( print_r( $remote_info, true ) );
		if ( $remote_info && version_compare( $this->current_version, $remote_info->tag_name, '<' ) ) {

			$repo = $this->get_repo();

			// https://github.com/WordPress/wordpress-develop/blob/2e5e2131a145e593173a7b2c57fb84fa93deabba/src/wp-admin/update-core.php#L514

			$plugin_data = array(
				'id'             => 'randomid',
				'slug'           => $this->plugin_slug,
				'new_version'    => $remote_info->tag_name,
				'url'            => $remote_info->html_url,
				'package'        => $remote_info->zipball_url,
				'upgrade_notice' => 'Upgrade to the latest version for new features and bugfixes.',
				'requires'       => '5.8',
				'icons'          => array(
					'svg'     => 'https://via.placeholder.com/256x256',
					'default' => 'https://via.placeholder.com/128x128',
				),
				'banners'        => array(
					'1x' => 'https://via.placeholder.com/772x250',
					'2x' => 'https://via.placeholder.com/1544x500',
				),
				'banners_rtl'    => array(
					'1x' => 'https://via.placeholder.com/772x250',
					'2x' => 'https://via.placeholder.com/1544x500',
				),

			);
			$transient->response[ $this->plugin_slug ] = (object) $plugin_data;

			error_log( print_r( $transient, true ) );

		}

		return $transient;
	}

	public function plugin_info( $result, $action, $args ) {
		if ( $action !== 'plugin_information' || $args->slug !== $this->plugin_slug ) {
			return $result;
		}

		$remote_info = $this->get_remote_info();
		if ( ! $remote_info ) {
			return $result;
		}

		$repo = $this->get_repo();
		if ( ! $repo ) {
			return $result;
		}

		// from https://github.com/WordPress/wordpress-develop/blob/412658097d7a71f16a4662f5a23cfed067b356d0/src/wp-admin/includes/plugin-install.php#L10

		return (object) array(

			'name'            => $repo->name,

			// 'description'       => $repo->description,
			// 'short_description' => $repo->description,
			'slug'            => $this->plugin_slug,
			'version'         => $remote_info->tag_name,
			'author'          => $repo->owner->login,
			'homepage'        => $repo->html_url,
			'author_profile'  => $repo->homepage,
			'download_link'   => $remote_info->zipball_url,
			'sections'        => array(
				'description' => wpautop( $remote_info->body ),
				'github'      => wpautop( $remote_info->body ),
			),
			'banners'         => array(
				'low'  => 'https://via.placeholder.com/772x250',
				'high' => 'https://via.placeholder.com/1544x500',
			),
			'icons'           => array(
				'default' => 'https://via.placeholder.com/128x128',
				'2x'      => 'https://via.placeholder.com/256x256',
			),
			'rating'          => 86,
			'ratings'         => true,
			// 'versions'        => array(),
			'donate_link'     => 'https://www.paypal.com',
			'last_updated'    => $remote_info->updated_at,
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

	private function get_remote_info() {

		$url = "https://api.github.com/repos/{$this->username}/{$this->repository}/releases/latest";

		$response = wp_remote_get( $url );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return $body;
	}

	private function get_repo() {

		$url = "https://api.github.com/repos/{$this->username}/{$this->repository}";

		$response = wp_remote_get( $url );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return $body;
	}

	public function rename_github_zip( $source, $remote_source, $upgrader ) {
		if ( strpos( $source, strtolower( $this->username . '-' . $this->repository ) ) !== false ) {
			return trailingslashit( $upgrader->skin->plugin_info['slug'] );
		}
		return $source;
	}
}
