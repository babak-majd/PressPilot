<?php
/**
 * Block-theme (Full Site Editing) support.
 *
 * Read/write `wp_template` and `wp_template_part` for the active block theme so
 * an agent can build a header, footer, and single/archive templates entirely with
 * Gutenberg blocks — the missing piece for a no-Elementor site. Also exposes the
 * Customizer's Additional CSS and the block theme's global styles (theme.json)
 * so global CSS and fonts survive without the Elementor Kit.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_FSE {

	/** The active theme's stylesheet slug (the wp_theme term for FSE posts). */
	private static function theme_slug() {
		return get_stylesheet();
	}

	/**
	 * Is the active theme a block theme (FSE-capable)?
	 *
	 * @return bool
	 */
	public static function is_block_theme() {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}

	/* ------------------------------------------------------------------ */
	/* Templates & template parts                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * List customized templates (or template parts) for the active theme.
	 *
	 * @param string $post_type wp_template | wp_template_part.
	 * @return array
	 */
	public static function list_templates( $post_type = 'wp_template' ) {
		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'auto-draft', 'draft' ),
				'posts_per_page' => 200,
				'tax_query'      => array(
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => self::theme_slug(),
					),
				),
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'      => $post->ID,
				'slug'    => $post->post_name,
				'title'   => get_the_title( $post ),
				'area'    => 'wp_template_part' === $post_type ? get_post_meta( $post->ID, 'area', true ) : null,
				'content' => $post->post_content,
			);
		}
		return array(
			'theme'        => self::theme_slug(),
			'is_block_theme'=> self::is_block_theme(),
			'items'        => $items,
		);
	}

	/**
	 * Create or update a wp_template / wp_template_part for the active theme.
	 *
	 * @param string $post_type wp_template | wp_template_part.
	 * @param array  $args      slug, title, content (block markup) or blocks (tree), area, description.
	 * @return array|WP_Error
	 */
	public static function upsert( $post_type, $args ) {
		$slug = isset( $args['slug'] ) ? sanitize_title( $args['slug'] ) : '';
		if ( '' === $slug ) {
			return PP_Helpers::error( 'pp_missing_slug', 'A template "slug" is required (e.g. "header", "index", "single").', 400 );
		}
		$content = '';
		if ( ! empty( $args['blocks'] ) && is_array( $args['blocks'] ) ) {
			$content = PP_Gutenberg::serialize_tree( $args['blocks'] );
		} elseif ( isset( $args['content'] ) ) {
			$content = (string) $args['content'];
		}
		$title = isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : $slug;

		$existing = self::find( $post_type, $slug );

		$postarr = array(
			'post_type'    => $post_type,
			'post_name'    => $slug,
			'post_title'   => $title,
			'post_status'  => 'publish',
			'post_content' => $content,
			'post_excerpt' => isset( $args['description'] ) ? sanitize_text_field( $args['description'] ) : '',
		);
		if ( $existing ) {
			$postarr['ID'] = $existing;
		}

		$post_id = PP_Gutenberg::without_kses(
			function () use ( $postarr ) {
				return wp_insert_post( $postarr, true );
			}
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Tie the post to the active theme so the block editor resolves it.
		wp_set_object_terms( $post_id, self::theme_slug(), 'wp_theme' );

		if ( 'wp_template_part' === $post_type ) {
			$area = ! empty( $args['area'] ) ? sanitize_key( $args['area'] ) : 'uncategorized'; // header | footer | sidebar | uncategorized
			update_post_meta( $post_id, 'area', $area );
			if ( taxonomy_exists( 'wp_template_part_area' ) ) {
				wp_set_object_terms( $post_id, $area, 'wp_template_part_area' );
			}
		}

		self::flush_template_caches( $post_id );

		return array(
			'id'      => (int) $post_id,
			'type'    => $post_type,
			'slug'    => $slug,
			'theme'   => self::theme_slug(),
			'updated' => (bool) $existing,
		);
	}

	/**
	 * Clear the caches WordPress uses for block templates/parts so an upsert is
	 * reflected on the front-end immediately (previously only theme.json was
	 * cleared, so template edits could lag behind).
	 *
	 * @param int $post_id Template post id.
	 * @return void
	 */
	private static function flush_template_caches( $post_id ) {
		clean_post_cache( $post_id );
		// get_block_templates() caches per-query under the 'blocks' object-cache group.
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'blocks' );
			wp_cache_flush_group( 'theme_json' );
		}
		if ( class_exists( 'WP_Theme_JSON_Resolver' ) && method_exists( 'WP_Theme_JSON_Resolver', 'clean_cached_data' ) ) {
			WP_Theme_JSON_Resolver::clean_cached_data();
		}
		// Bust the plugin-agnostic template query cache used since WP 6.1.
		wp_cache_delete( 'wp_get_block_templates', 'blocks' );
	}

	/**
	 * Find an existing FSE post id by slug for the active theme.
	 *
	 * @param string $post_type Post type.
	 * @param string $slug      Slug.
	 * @return int|null
	 */
	private static function find( $post_type, $slug ) {
		$found = get_posts(
			array(
				'post_type'      => $post_type,
				'name'           => $slug,
				'post_status'    => array( 'publish', 'auto-draft', 'draft' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => self::theme_slug(),
					),
				),
			)
		);
		return ! empty( $found ) ? (int) $found[0] : null;
	}

	/* ------------------------------------------------------------------ */
	/* Additional CSS (Customizer) — works on classic AND block themes     */
	/* ------------------------------------------------------------------ */

	/**
	 * Read the Customizer "Additional CSS" for the active theme.
	 *
	 * @return array
	 */
	public static function get_custom_css( $theme = '' ) {
		$theme = $theme ? sanitize_key( $theme ) : self::theme_slug();
		return array(
			'theme' => $theme,
			'css'   => wp_get_custom_css( $theme ),
		);
	}

	/**
	 * Set the Customizer "Additional CSS". This is the KSES-free, no-Elementor
	 * home for global CSS and @font-face fonts. Optionally target a non-active
	 * theme (e.g. to stage CSS on an incoming theme before switching to it).
	 *
	 * @param string $css     CSS.
	 * @param bool   $append  Append to existing instead of replacing.
	 * @param string $theme   Target theme stylesheet (default: active theme).
	 * @return array|WP_Error
	 */
	public static function set_custom_css( $css, $append = false, $theme = '' ) {
		$theme = $theme ? sanitize_key( $theme ) : self::theme_slug();
		$css   = (string) $css;
		if ( $append ) {
			$css = trim( (string) wp_get_custom_css( $theme ) . "\n" . $css );
		}
		$post = wp_update_custom_css_post( $css, array( 'stylesheet' => $theme ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return array(
			'theme'   => $theme,
			'post_id' => (int) $post->ID,
			'bytes'   => strlen( $css ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Global styles (theme.json) — block themes                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Read the user global-styles JSON + resolved custom CSS for a block theme.
	 *
	 * @return array|WP_Error
	 */
	public static function get_global_styles() {
		if ( ! self::is_block_theme() ) {
			return PP_Helpers::error( 'pp_not_block_theme', 'The active theme is not a block theme; use /settings/custom-css instead.', 409 );
		}
		$post_id = self::global_styles_post_id();
		$json    = array();
		if ( $post_id ) {
			$decoded = json_decode( (string) get_post( $post_id )->post_content, true );
			$json    = is_array( $decoded ) ? $decoded : array();
		}
		return array(
			'theme'      => self::theme_slug(),
			'post_id'    => (int) $post_id,
			'global'     => $json,
			'custom_css' => function_exists( 'wp_get_global_styles_custom_css' ) ? wp_get_global_styles_custom_css() : '',
		);
	}

	/**
	 * Merge settings/styles/css into the user global styles for a block theme.
	 *
	 * @param array $args settings (obj), styles (obj), css (string).
	 * @return array|WP_Error
	 */
	public static function set_global_styles( $args ) {
		if ( ! self::is_block_theme() ) {
			return PP_Helpers::error( 'pp_not_block_theme', 'The active theme is not a block theme; use /settings/custom-css instead.', 409 );
		}
		$post_id = self::global_styles_post_id( true );
		if ( ! $post_id ) {
			return PP_Helpers::error( 'pp_no_global_styles', 'Could not resolve the global-styles record for this theme.', 500 );
		}

		$post    = get_post( $post_id );
		$current = json_decode( (string) $post->post_content, true );
		$current = is_array( $current ) ? $current : array();
		if ( empty( $current['version'] ) ) {
			$current['version'] = 2;
		}
		$current['isGlobalStylesUserThemeJSON'] = true;

		if ( isset( $args['settings'] ) && is_array( $args['settings'] ) ) {
			$current['settings'] = self::deep_merge( isset( $current['settings'] ) ? $current['settings'] : array(), $args['settings'] );
		}
		if ( isset( $args['styles'] ) && is_array( $args['styles'] ) ) {
			$current['styles'] = self::deep_merge( isset( $current['styles'] ) ? $current['styles'] : array(), $args['styles'] );
		}
		if ( isset( $args['css'] ) ) {
			$current['styles']              = isset( $current['styles'] ) ? $current['styles'] : array();
			$current['styles']['css']       = (string) $args['css'];
		}

		$updated = PP_Gutenberg::without_kses(
			function () use ( $post_id, $current ) {
				return wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => wp_json_encode( $current ),
					),
					true
				);
			}
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		// Bust the theme.json cache so the change renders immediately.
		if ( class_exists( 'WP_Theme_JSON_Resolver' ) && method_exists( 'WP_Theme_JSON_Resolver', 'clean_cached_data' ) ) {
			WP_Theme_JSON_Resolver::clean_cached_data();
		}
		return array( 'theme' => self::theme_slug(), 'post_id' => (int) $post_id, 'global' => $current );
	}

	/**
	 * Find (or create) the wp_global_styles post id for the active theme.
	 *
	 * @param bool $create Create it if missing.
	 * @return int
	 */
	private static function global_styles_post_id( $create = false ) {
		if ( class_exists( 'WP_Theme_JSON_Resolver' ) && method_exists( 'WP_Theme_JSON_Resolver', 'get_user_global_styles_post_id' ) ) {
			$id = (int) WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
			if ( $id ) {
				return $id;
			}
		}
		$found = get_posts(
			array(
				'post_type'      => 'wp_global_styles',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'post_status'    => 'publish',
				'tax_query'      => array(
					array( 'taxonomy' => 'wp_theme', 'field' => 'name', 'terms' => self::theme_slug() ),
				),
			)
		);
		if ( ! empty( $found ) ) {
			return (int) $found[0];
		}
		if ( ! $create ) {
			return 0;
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'wp_global_styles',
				'post_title'   => 'Custom Styles',
				'post_name'    => 'wp-global-styles-' . self::theme_slug(),
				'post_status'  => 'publish',
				'post_content' => wp_json_encode( array( 'version' => 2, 'isGlobalStylesUserThemeJSON' => true ) ),
			)
		);
		if ( ! is_wp_error( $id ) ) {
			wp_set_object_terms( $id, self::theme_slug(), 'wp_theme' );
			return (int) $id;
		}
		return 0;
	}

	/**
	 * Recursively merge $b into $a (associative arrays), $b winning on scalars.
	 *
	 * @param array $a Base.
	 * @param array $b Overrides.
	 * @return array
	 */
	private static function deep_merge( $a, $b ) {
		foreach ( $b as $k => $v ) {
			if ( is_array( $v ) && isset( $a[ $k ] ) && is_array( $a[ $k ] ) && self::is_assoc( $v ) ) {
				$a[ $k ] = self::deep_merge( $a[ $k ], $v );
			} else {
				$a[ $k ] = $v;
			}
		}
		return $a;
	}

	private static function is_assoc( $arr ) {
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}
}
