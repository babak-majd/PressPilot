<?php
/**
 * Site-level operations: themes and front-page settings.
 *
 * These use WordPress' own installer/upgrader APIs so everything works over the
 * REST channel with no shell access on the host.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Site {

	/**
	 * List installed themes.
	 *
	 * @return array
	 */
	public static function list_themes() {
		$active = wp_get_theme();
		$out    = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$out[] = array(
				'slug'    => $slug,
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'active'  => ( $theme->get_stylesheet() === $active->get_stylesheet() ),
			);
		}
		return $out;
	}

	/**
	 * Activate a theme, installing it from wordpress.org first if requested and
	 * it is not already present.
	 *
	 * @param string $slug    Theme slug (e.g. "hello-elementor").
	 * @param bool   $install Install from wp.org if missing.
	 * @return array|WP_Error
	 */
	public static function activate_theme( $slug, $install = true ) {
		$slug = sanitize_key( $slug );
		if ( empty( $slug ) ) {
			return PP_Helpers::error( 'pp_missing_slug', 'A theme "slug" is required.', 400 );
		}

		$installed = wp_get_theme( $slug )->exists();

		if ( ! $installed ) {
			if ( ! $install ) {
				return PP_Helpers::error( 'pp_theme_missing', sprintf( 'Theme "%s" is not installed.', $slug ), 404 );
			}
			$result = self::install_theme_from_wporg( $slug );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( ! wp_get_theme( $slug )->exists() ) {
			return PP_Helpers::error( 'pp_theme_missing', sprintf( 'Theme "%s" could not be found after install.', $slug ), 500 );
		}

		switch_theme( $slug );

		$now = wp_get_theme();
		return array(
			'activated'    => ( $now->get_stylesheet() === $slug || $now->get_template() === $slug ),
			'active_theme' => array(
				'slug'    => $now->get_stylesheet(),
				'name'    => $now->get( 'Name' ),
				'version' => $now->get( 'Version' ),
			),
			'was_installed'=> ! $installed,
		);
	}

	/**
	 * Download + install a theme from the wordpress.org theme directory.
	 *
	 * @param string $slug Theme slug.
	 * @return true|WP_Error
	 */
	private static function install_theme_from_wporg( $slug ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$api = themes_api( 'theme_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		if ( empty( $api->download_link ) ) {
			return PP_Helpers::error( 'pp_no_download', sprintf( 'No download link for theme "%s".', $slug ), 502 );
		}

		$skin     = class_exists( 'WP_Ajax_Upgrader_Skin' ) ? new WP_Ajax_Upgrader_Skin() : new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( true !== $result ) {
			$messages = method_exists( $skin, 'get_errors' ) && is_wp_error( $skin->get_errors() ) ? $skin->get_errors()->get_error_message() : 'unknown error';
			return PP_Helpers::error( 'pp_install_failed', 'Theme install failed: ' . $messages, 500 );
		}
		return true;
	}

	/**
	 * Read the current front-page configuration.
	 *
	 * @return array
	 */
	public static function get_homepage() {
		return array(
			'show_on_front' => get_option( 'show_on_front' ),
			'page_on_front' => (int) get_option( 'page_on_front' ),
			'page_for_posts'=> (int) get_option( 'page_for_posts' ),
		);
	}

	/**
	 * Set the static front page (and optionally the posts page).
	 *
	 * @param int      $page_id       Page to use as the static front page.
	 * @param int|null $posts_page_id Optional page to use for the blog index.
	 * @return array|WP_Error
	 */
	public static function set_homepage( $page_id, $posts_page_id = null ) {
		$page_id = (int) $page_id;
		if ( $page_id <= 0 || 'page' !== get_post_type( $page_id ) ) {
			return PP_Helpers::error( 'pp_bad_page', 'A valid page "page_id" is required.', 400 );
		}
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );
		if ( null !== $posts_page_id ) {
			update_option( 'page_for_posts', (int) $posts_page_id );
		}
		return self::get_homepage();
	}

	/** Options that may be changed over the API (safe allowlist). */
	const ALLOWED_OPTIONS = array(
		'permalink_structure', 'elementor_google_fonts', 'elementor_load_fa4_shim',
		'blogname', 'blogdescription', 'elementor_css_print_method', 'timezone_string',
		'start_of_week', 'elementor_optimized_dom_output', 'WPLANG', 'date_format', 'time_format',
	);

	/**
	 * Set a batch of allowlisted options. permalink_structure also flushes rewrite.
	 *
	 * @param array $options key => value.
	 * @return array|WP_Error
	 */
	public static function set_options( $options ) {
		if ( ! is_array( $options ) || empty( $options ) ) {
			return PP_Helpers::error( 'pp_no_options', 'An "options" object is required.', 400 );
		}
		$applied = array();
		foreach ( $options as $key => $value ) {
			// Special: keep the admin UI in one locale while the site front-end uses another.
			if ( 'admin_locale' === $key ) {
				foreach ( get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) as $uid ) {
					update_user_meta( $uid, 'locale', sanitize_text_field( $value ) );
				}
				$applied['admin_locale'] = $value;
				continue;
			}
			if ( ! in_array( $key, self::ALLOWED_OPTIONS, true ) ) {
				continue;
			}
			if ( 'permalink_structure' === $key ) {
				self::set_permalinks( (string) $value );
			} else {
				update_option( $key, is_string( $value ) ? sanitize_text_field( $value ) : $value );
			}
			$applied[ $key ] = get_option( $key );
		}
		return array( 'applied' => $applied );
	}

	/**
	 * Set the permalink structure and rebuild rewrite rules + .htaccess.
	 *
	 * @param string $structure e.g. "/%postname%/".
	 * @return void
	 */
	public static function set_permalinks( $structure ) {
		global $wp_rewrite;
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		update_option( 'permalink_structure', $structure );
		if ( $wp_rewrite ) {
			$wp_rewrite->set_permalink_structure( $structure );
			$wp_rewrite->flush_rules( true );
		}
	}

	/**
	 * Map a friendly template name to the WP page template value and set it.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $template canvas | full_width | default.
	 * @return void
	 */
	public static function set_page_template( $post_id, $template ) {
		$map = array(
			'canvas'       => 'elementor_canvas',
			'full_width'   => 'elementor_header_footer',
			'header_footer'=> 'elementor_header_footer',
			'default'      => '',
		);
		if ( ! array_key_exists( $template, $map ) ) {
			return;
		}
		$value = $map[ $template ];
		if ( '' === $value ) {
			delete_post_meta( $post_id, '_wp_page_template' );
		} else {
			update_post_meta( $post_id, '_wp_page_template', $value );
		}
		// keep Elementor's own page setting in sync
		$settings = get_post_meta( $post_id, '_elementor_page_settings', true );
		$settings = is_array( $settings ) ? $settings : array();
		$settings['template'] = '' === $value ? 'default' : $value;
		update_post_meta( $post_id, '_elementor_page_settings', $settings );
	}

	/**
	 * Create or update an Elementor Theme Builder part (header/footer/etc.) and
	 * assign its display conditions so it shows site-wide.
	 *
	 * @param array $args slug, title, type, elements, conditions, settings.
	 * @return array|WP_Error
	 */
	public static function upsert_template( $args ) {
		if ( ! PP_Helpers::elementor_active() ) {
			return PP_Helpers::error( 'pp_no_elementor', 'Elementor is not active.', 409 );
		}
		$slug       = sanitize_title( isset( $args['slug'] ) ? $args['slug'] : ( isset( $args['title'] ) ? $args['title'] : 'part' ) );
		$title      = isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : $slug;
		$type       = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : 'header';
		$elements   = isset( $args['elements'] ) && is_array( $args['elements'] ) ? $args['elements'] : array();
		$conditions = isset( $args['conditions'] ) && is_array( $args['conditions'] ) ? $args['conditions'] : array( 'include/general' );

		// Find existing by slug (post_name) within elementor_library.
		$existing = get_posts( array(
			'post_type'      => 'elementor_library',
			'name'           => $slug,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		) );

		$postarr = array(
			'post_title'  => $title,
			'post_name'   => $slug,
			'post_type'   => 'elementor_library',
			'post_status' => 'publish',
		);
		if ( ! empty( $existing ) ) {
			$post_id            = (int) $existing[0];
			$postarr['ID']      = $post_id;
			wp_update_post( $postarr );
		} else {
			$post_id = wp_insert_post( $postarr, true );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
		}

		// Type must be set BEFORE save_data so it is not overwritten with a default.
		update_post_meta( $post_id, '_elementor_template_type', $type );
		wp_set_object_terms( $post_id, $type, 'elementor_library_type' );

		$saved = PP_Elementor::save_data( $post_id, $elements );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		if ( isset( $args['settings'] ) && is_array( $args['settings'] ) ) {
			PP_Elementor::save_page_settings( $post_id, $args['settings'] );
		}

		$debug = self::assign_conditions( $post_id, $conditions );

		return array(
			'id'         => $post_id,
			'type'       => $type,
			'conditions' => $conditions,
			'debug'      => $debug,
			'edit_url'   => get_edit_post_link( $post_id, 'raw' ),
		);
	}

	/**
	 * Assign Theme Builder display conditions and clear the conditions cache.
	 *
	 * @param int   $post_id    Template id.
	 * @param array $conditions e.g. ["include/general"].
	 * @return void
	 */
	private static function assign_conditions( $post_id, $conditions ) {
		$conditions = array_values( $conditions );
		update_post_meta( $post_id, '_elementor_conditions', $conditions );
		$debug = array();
		try {
			if ( class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
				$module          = \ElementorPro\Modules\ThemeBuilder\Module::instance();
				$debug['module'] = true;
				if ( method_exists( $module, 'get_conditions_manager' ) ) {
					$cm          = $module->get_conditions_manager();
					$debug['cm'] = get_class( $cm );
					// Rebuild the conditions cache from every template's meta (which we
					// just set). save_conditions() is buggy for our shape, so skip it.
					if ( method_exists( $cm, 'get_cache' ) ) {
						$cache          = $cm->get_cache();
						$debug['cache'] = is_object( $cache ) ? get_class( $cache ) : gettype( $cache );
						if ( is_object( $cache ) && method_exists( $cache, 'regenerate' ) ) {
							// regenerate() clears + rebuilds. Do NOT call clear() after it.
							$cache->regenerate();
							$debug['regen'] = true;
						}
					}
				}
			} else {
				$debug['module'] = false;
			}
		} catch ( \Throwable $e ) {
			$debug['error'] = $e->getMessage();
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		// Diagnostics.
		$debug['meta_readback'] = get_post_meta( $post_id, '_elementor_conditions', true );
		$debug['tax']           = wp_get_object_terms( $post_id, 'elementor_library_type', array( 'fields' => 'names' ) );
		try {
			if ( isset( $module ) && method_exists( $module, 'get_locations_manager' ) ) {
				$lm = $module->get_locations_manager();
				if ( method_exists( $lm, 'get_locations' ) ) {
					$debug['locations'] = array_keys( $lm->get_locations() );
				}
			}
		} catch ( \Throwable $e ) {
			$debug['loc_error'] = $e->getMessage();
		}
		return $debug;
	}
}
