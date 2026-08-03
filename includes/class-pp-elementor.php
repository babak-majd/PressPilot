<?php
/**
 * Elementor read/write service.
 *
 * Everything that touches Elementor's data model lives here so the REST layer
 * stays thin. Elementor stores a page layout as a JSON string in the post meta
 * `_elementor_data`, plus a few companion meta keys.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Elementor {

	const META_DATA          = '_elementor_data';
	const META_EDIT_MODE     = '_elementor_edit_mode';
	const META_TEMPLATE_TYPE = '_elementor_template_type';
	const META_VERSION       = '_elementor_version';
	const META_PAGE_SETTINGS = '_elementor_page_settings';

	/**
	 * Is a given post built with Elementor?
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function is_built_with_elementor( $post_id ) {
		return 'builder' === get_post_meta( $post_id, self::META_EDIT_MODE, true );
	}

	/**
	 * Read the Elementor elements tree for a post as a PHP array.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public static function get_data( $post_id ) {
		$raw = get_post_meta( $post_id, self::META_DATA, true );
		if ( empty( $raw ) ) {
			return array();
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Read the Elementor page settings (page layout, hide title, custom css, etc).
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public static function get_page_settings( $post_id ) {
		$settings = get_post_meta( $post_id, self::META_PAGE_SETTINGS, true );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Write an Elementor elements tree to a post and flip it into builder mode.
	 *
	 * IMPORTANT: `_elementor_data` must be stored *slashed*. WordPress runs
	 * wp_unslash() on meta on the way in, so without wp_slash() the JSON quotes
	 * get corrupted and Elementor silently drops the layout. This is the single
	 * most common mistake when writing Elementor data programmatically.
	 *
	 * @param int   $post_id  Post id.
	 * @param array $elements Elementor elements tree.
	 * @return true|WP_Error
	 */
	public static function save_data( $post_id, $elements ) {
		if ( ! PP_Helpers::elementor_active() ) {
			return PP_Helpers::error( 'pp_no_elementor', 'Elementor is not active on this site.', 409 );
		}

		$elements = PP_Helpers::ensure_ids( $elements );
		$json     = wp_json_encode( $elements );
		if ( false === $json ) {
			return PP_Helpers::error( 'pp_bad_json', 'Could not encode the Elementor elements.', 400 );
		}

		// Store slashed — see method docblock.
		update_post_meta( $post_id, self::META_DATA, wp_slash( $json ) );
		update_post_meta( $post_id, self::META_EDIT_MODE, 'builder' );
		update_post_meta( $post_id, self::META_VERSION, defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : PP_VERSION );

		$template_type = get_post_meta( $post_id, self::META_TEMPLATE_TYPE, true );
		if ( empty( $template_type ) ) {
			$post_type = get_post_type( $post_id );
			update_post_meta( $post_id, self::META_TEMPLATE_TYPE, 'page' === $post_type ? 'wp-page' : 'wp-post' );
		}

		self::flush_css( $post_id );
		return true;
	}

	/**
	 * Completely detach a post from Elementor so WordPress renders `post_content`
	 * (Gutenberg blocks / classic HTML) again instead of the Elementor layout.
	 *
	 * This is the key to an in-place Elementor → Gutenberg migration: without it
	 * Elementor keeps hijacking `the_content` even after you write block markup.
	 *
	 * @param int $post_id Post id.
	 * @return array List of meta keys that were removed.
	 */
	public static function clear( $post_id ) {
		$keys = array(
			self::META_DATA,
			self::META_EDIT_MODE,
			self::META_TEMPLATE_TYPE,
			self::META_VERSION,
			self::META_PAGE_SETTINGS,
			'_elementor_pro_version',
			'_elementor_css',                 // cached compiled CSS
			'_elementor_controls_usage',
		);
		$removed = array();
		foreach ( $keys as $key ) {
			if ( metadata_exists( 'post', $post_id, $key ) ) {
				delete_post_meta( $post_id, $key );
				$removed[] = $key;
			}
		}
		// Reset the page template to the theme default (drops elementor_canvas etc.).
		delete_post_meta( $post_id, '_wp_page_template' );

		// Drop Elementor's per-post cached CSS file if the API is available.
		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			try {
				\Elementor\Core\Files\CSS\Post::create( $post_id )->delete();
			} catch ( \Throwable $e ) {
				// best effort.
			}
		}
		return $removed;
	}

	/**
	 * Report how much of the site still depends on Elementor — the definitive
	 * "did the Gutenberg migration finish?" check. Scans every post type for
	 * content still in Elementor builder mode, plus Theme Builder / library
	 * templates and whether the plugin is still active.
	 *
	 * @param int $sample Max leftover items to list per bucket.
	 * @return array
	 */
	public static function usage_report( $sample = 50 ) {
		// Content (pages/posts/CPTs) still rendered by Elementor.
		$content = new WP_Query( array(
			'post_type'      => 'any',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'meta_query'     => array(
				array( 'key' => self::META_EDIT_MODE, 'value' => 'builder' ),
			),
		) );

		$items = array();
		foreach ( array_slice( $content->posts, 0, $sample ) as $pid ) {
			$items[] = array(
				'id'     => (int) $pid,
				'type'   => get_post_type( $pid ),
				'title'  => get_the_title( $pid ),
				'status' => get_post_status( $pid ),
				'url'    => get_permalink( $pid ),
			);
		}

		// Elementor library entries (Theme Builder headers/footers, saved templates).
		$library = new WP_Query( array(
			'post_type'      => 'elementor_library',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		$library_items = array();
		foreach ( array_slice( $library->posts, 0, $sample ) as $pid ) {
			$library_items[] = array(
				'id'    => (int) $pid,
				'title' => get_the_title( $pid ),
				'type'  => get_post_meta( $pid, self::META_TEMPLATE_TYPE, true ),
			);
		}

		// Any lingering Elementor meta anywhere (belt-and-braces).
		global $wpdb;
		$orphan_meta = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_elementor\_%'"
		);

		$active         = PP_Helpers::elementor_active();
		$content_count  = (int) $content->found_posts;
		$library_count  = (int) $library->found_posts;
		$fully_migrated = ( 0 === $content_count && 0 === $library_count && ! $active );

		return array(
			'fully_migrated'          => $fully_migrated,
			'elementor_plugin_active' => $active,
			'elementor_pro_active'    => PP_Helpers::elementor_pro_active(),
			'content_using_elementor' => array(
				'count' => $content_count,
				'items' => $items,
			),
			'elementor_library'       => array(
				'count' => $library_count,
				'items' => $library_items,
			),
			'posts_with_elementor_meta' => $orphan_meta,
			'next_steps'              => self::migration_next_steps( $content_count, $library_count, $active ),
		);
	}

	/**
	 * Human-readable remaining steps for the agent to reach a clean migration.
	 *
	 * @param int  $content_count Content still on Elementor.
	 * @param int  $library_count Library/Theme-Builder entries left.
	 * @param bool $active        Elementor still active.
	 * @return array
	 */
	private static function migration_next_steps( $content_count, $library_count, $active ) {
		$steps = array();
		if ( $content_count > 0 ) {
			$steps[] = sprintf( 'Convert %d item(s) still in Elementor builder mode: PUT /content/{id} with builder:"gutenberg".', $content_count );
		}
		if ( $library_count > 0 ) {
			$steps[] = sprintf( 'Rebuild %d Theme Builder/library template(s) as block-theme parts (/fse-template-parts, /fse-templates), then DELETE the elementor_library items.', $library_count );
		}
		if ( $active ) {
			$steps[] = 'Deactivate Elementor: POST /plugins/deactivate {"slug":"elementor"} (and elementor-pro), then delete when confident.';
		}
		if ( empty( $steps ) ) {
			$steps[] = 'Nothing left — the site is fully on Gutenberg.';
		}
		return $steps;
	}

	/**
	 * Purge every lingering `_elementor_*` postmeta row (e.g. left on revisions
	 * after an in-place migration), so `posts_with_elementor_meta` reaches 0.
	 *
	 * @param bool $revisions_only Only purge meta on revisions/autosaves (safer).
	 * @return array Removed rows + affected posts.
	 */
	public static function purge_meta( $revisions_only = false ) {
		global $wpdb;

		if ( $revisions_only ) {
			$affected = (int) $wpdb->query(
				"DELETE pm FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key LIKE '\_elementor\_%'
				   AND p.post_type IN ('revision')"
			);
		} else {
			$affected = (int) $wpdb->query(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_elementor\_%'"
			);
		}

		$remaining = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_elementor\_%'"
		);

		return array(
			'removed_rows'              => $affected,
			'revisions_only'            => (bool) $revisions_only,
			'posts_with_elementor_meta' => $remaining,
		);
	}

	/**
	 * Merge/replace the Elementor page settings meta.
	 *
	 * @param int   $post_id  Post id.
	 * @param array $settings Settings to merge.
	 * @return void
	 */
	public static function save_page_settings( $post_id, $settings ) {
		if ( ! is_array( $settings ) ) {
			return;
		}
		$existing = self::get_page_settings( $post_id );
		$merged   = array_merge( $existing, $settings );
		update_post_meta( $post_id, self::META_PAGE_SETTINGS, $merged );
	}

	/**
	 * Regenerate the cached CSS so the new layout renders on the front-end.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function flush_css( $post_id ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}
		try {
			$plugin = \Elementor\Plugin::$instance;
			if ( isset( $plugin->files_manager ) ) {
				$plugin->files_manager->clear_cache();
			}
			if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
				$css = \Elementor\Core\Files\CSS\Post::create( $post_id );
				$css->update();
			}
		} catch ( \Throwable $e ) {
			// Cache regeneration is best-effort; the layout is already saved.
			return;
		}
	}

	/**
	 * List the registered Elementor widget types (respects Pro if active).
	 *
	 * @return array
	 */
	public static function list_widgets() {
		$out = array();
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return $out;
		}
		$manager = \Elementor\Plugin::$instance->widgets_manager;
		if ( ! $manager ) {
			return $out;
		}
		foreach ( $manager->get_widget_types() as $name => $widget ) {
			$out[] = array(
				'name'       => $name,
				'title'      => $widget->get_title(),
				'categories' => $widget->get_categories(),
				'keywords'   => $widget->get_keywords(),
				'is_pro'     => false !== strpos( get_class( $widget ), 'ElementorPro' ),
			);
		}
		return $out;
	}

	/**
	 * Return a simplified control schema for a single widget, so the assistant
	 * knows which settings keys are valid and their defaults/options.
	 *
	 * @param string $name Widget type name.
	 * @return array|WP_Error
	 */
	public static function widget_controls( $name ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return PP_Helpers::error( 'pp_no_elementor', 'Elementor is not active.', 409 );
		}
		$manager = \Elementor\Plugin::$instance->widgets_manager;
		$widget  = $manager ? $manager->get_widget_types( $name ) : null;
		if ( ! $widget ) {
			return PP_Helpers::error( 'pp_no_widget', sprintf( 'Unknown widget "%s".', $name ), 404 );
		}

		$controls = array();
		foreach ( $widget->get_controls() as $key => $control ) {
			$row = array(
				'name'    => $key,
				'type'    => isset( $control['type'] ) ? $control['type'] : '',
				'label'   => isset( $control['label'] ) ? $control['label'] : '',
				'default' => isset( $control['default'] ) ? $control['default'] : null,
			);
			if ( ! empty( $control['options'] ) ) {
				$row['options'] = array_keys( $control['options'] );
			}
			$controls[] = $row;
		}

		return array(
			'name'       => $widget->get_name(),
			'title'      => $widget->get_title(),
			'categories' => $widget->get_categories(),
			'controls'   => $controls,
		);
	}
}
