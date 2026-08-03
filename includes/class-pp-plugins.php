<?php
/**
 * Plugin management over REST.
 *
 * Lets an agent install the plugin it needs (e.g. a form plugin to replace an
 * Elementor Pro form) and, at the end of a migration, deactivate/delete Elementor
 * itself — using WordPress' own upgrader so it works with no shell access.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Plugins {

	/** Guard: never let the API disable/delete itself and lock the agent out. */
	private static function is_self( $file ) {
		return plugin_basename( PP_FILE ) === $file;
	}

	private static function load() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * List installed plugins with active state and update availability.
	 *
	 * @return array
	 */
	public static function list_all() {
		self::load();
		$updates = get_site_transient( 'update_plugins' );
		$out     = array();
		foreach ( get_plugins() as $file => $data ) {
			$out[] = array(
				'file'          => $file,
				'slug'          => dirname( $file ) !== '.' ? dirname( $file ) : preg_replace( '/\.php$/', '', $file ),
				'name'          => $data['Name'],
				'version'       => $data['Version'],
				'active'        => is_plugin_active( $file ),
				'is_self'       => self::is_self( $file ),
				'update'        => isset( $updates->response[ $file ] ),
			);
		}
		return $out;
	}

	/**
	 * Install a plugin from the wordpress.org repo (by slug) or a base64 zip.
	 *
	 * @param array $args slug | zip (base64), activate (bool).
	 * @return array|WP_Error
	 */
	public static function install( $args ) {
		self::load();
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$slug     = isset( $args['slug'] ) ? sanitize_key( $args['slug'] ) : '';
		$zip_b64  = isset( $args['zip'] ) ? (string) $args['zip'] : '';
		$activate = ! empty( $args['activate'] );

		if ( '' !== $slug ) {
			$source = self::wporg_download_link( $slug );
			if ( is_wp_error( $source ) ) {
				return $source;
			}
			$cleanup = null;
		} elseif ( '' !== $zip_b64 ) {
			$source = self::stash_zip( $zip_b64 );
			if ( is_wp_error( $source ) ) {
				return $source;
			}
			$cleanup = $source;
		} else {
			return PP_Helpers::error( 'pp_missing_source', 'Provide a repo "slug" or a base64 "zip".', 400 );
		}

		$skin     = class_exists( 'WP_Ajax_Upgrader_Skin' ) ? new WP_Ajax_Upgrader_Skin() : new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $source, array( 'overwrite_package' => true ) );
		if ( $cleanup ) {
			@unlink( $cleanup );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( true !== $result ) {
			$msg = method_exists( $skin, 'get_errors' ) && is_wp_error( $skin->get_errors() )
				? $skin->get_errors()->get_error_message() : 'unknown error';
			return PP_Helpers::error( 'pp_install_failed', 'Plugin install failed: ' . $msg, 500 );
		}

		$file = $upgrader->plugin_info();
		$out  = array(
			'installed' => true,
			'file'      => $file,
			'activated' => false,
		);
		if ( $activate && $file ) {
			$act = activate_plugin( $file );
			$out['activated'] = ! is_wp_error( $act );
			if ( is_wp_error( $act ) ) {
				$out['activation_error'] = $act->get_error_message();
			}
		}
		return $out;
	}

	/**
	 * Activate / deactivate / delete a plugin by file or slug.
	 *
	 * @param string $action activate|deactivate|delete.
	 * @param string $ref    Plugin file (dir/file.php) or slug.
	 * @return array|WP_Error
	 */
	public static function manage( $action, $ref ) {
		self::load();
		$file = self::resolve_file( $ref );
		if ( ! $file ) {
			return PP_Helpers::error( 'pp_no_plugin', sprintf( 'Plugin "%s" is not installed.', $ref ), 404 );
		}
		if ( self::is_self( $file ) && 'activate' !== $action ) {
			return PP_Helpers::error( 'pp_self_protected', 'PressPilot cannot deactivate or delete itself over its own API.', 409 );
		}

		switch ( $action ) {
			case 'activate':
				$res = activate_plugin( $file );
				return is_wp_error( $res ) ? $res : array( 'file' => $file, 'active' => true );

			case 'deactivate':
				deactivate_plugins( $file );
				return array( 'file' => $file, 'active' => false );

			case 'delete':
				require_once ABSPATH . 'wp-admin/includes/file.php';
				if ( is_plugin_active( $file ) ) {
					deactivate_plugins( $file );
				}
				$res = delete_plugins( array( $file ) );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				return array( 'file' => $file, 'deleted' => true );
		}
		return PP_Helpers::error( 'pp_bad_action', 'Unknown plugin action.', 400 );
	}

	/**
	 * Resolve a plugin file from a file path or a folder slug.
	 *
	 * @param string $ref File or slug.
	 * @return string|null
	 */
	private static function resolve_file( $ref ) {
		$ref = (string) $ref;
		$all = array_keys( get_plugins() );
		if ( in_array( $ref, $all, true ) ) {
			return $ref;
		}
		foreach ( $all as $file ) {
			if ( dirname( $file ) === $ref || 0 === strpos( $file, $ref . '/' ) ) {
				return $file;
			}
		}
		return null;
	}

	/**
	 * Resolve the wordpress.org download link for a plugin slug.
	 *
	 * @param string $slug Plugin slug.
	 * @return string|WP_Error
	 */
	private static function wporg_download_link( $slug ) {
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		$api = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		if ( empty( $api->download_link ) ) {
			return PP_Helpers::error( 'pp_no_download', sprintf( 'No download link for plugin "%s".', $slug ), 502 );
		}
		return $api->download_link;
	}

	/**
	 * Write a base64 zip to a temp file for the upgrader.
	 *
	 * @param string $b64 Base64 zip.
	 * @return string|WP_Error Temp file path.
	 */
	private static function stash_zip( $b64 ) {
		$bytes = base64_decode( $b64, true );
		if ( false === $bytes ) {
			return PP_Helpers::error( 'pp_bad_zip', 'The "zip" is not valid base64.', 400 );
		}
		$tmp = wp_tempnam( 'pp-plugin.zip' );
		if ( ! $tmp || false === file_put_contents( $tmp, $bytes ) ) {
			return PP_Helpers::error( 'pp_tmp_failed', 'Could not write the uploaded zip.', 500 );
		}
		return $tmp;
	}
}
