<?php
/**
 * Navigation-menu management.
 *
 * Lets an agent build the menus a Gutenberg header/footer needs — create a menu,
 * add page/custom-link/category items, and assign it to a theme location — none
 * of which the read-only /menus listing could do.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Menus {

	/**
	 * Read a single menu with its items.
	 *
	 * @param int $menu_id Menu term id.
	 * @return array|WP_Error
	 */
	public static function get( $menu_id ) {
		$menu = wp_get_nav_menu_object( (int) $menu_id );
		if ( ! $menu ) {
			return PP_Helpers::error( 'pp_no_menu', 'Menu not found.', 404 );
		}
		$items     = wp_get_nav_menu_items( $menu->term_id ) ?: array();
		$locations = array_keys( array_filter( (array) get_nav_menu_locations(), function ( $id ) use ( $menu ) {
			return (int) $id === (int) $menu->term_id;
		} ) );

		return array(
			'id'        => (int) $menu->term_id,
			'name'      => $menu->name,
			'slug'      => $menu->slug,
			'locations' => array_values( $locations ),
			'items'     => array_map(
				function ( $it ) {
					return array(
						'id'        => (int) $it->ID,
						'title'     => $it->title,
						'url'       => $it->url,
						'parent'    => (int) $it->menu_item_parent,
						'order'     => (int) $it->menu_order,
						'type'      => $it->type,
						'object'    => $it->object,
						'object_id' => (int) $it->object_id,
					);
				},
				$items
			),
		);
	}

	/**
	 * Create (or reuse, by name) a menu, then optionally set its items and the
	 * theme locations it should occupy.
	 *
	 * @param array $args name, items[], locations[].
	 * @return array|WP_Error
	 */
	public static function create( $args ) {
		$name = isset( $args['name'] ) ? sanitize_text_field( $args['name'] ) : '';
		if ( '' === $name ) {
			return PP_Helpers::error( 'pp_missing_name', 'A menu "name" is required.', 400 );
		}
		$existing = wp_get_nav_menu_object( $name );
		$menu_id  = $existing ? (int) $existing->term_id : wp_create_nav_menu( $name );
		if ( is_wp_error( $menu_id ) ) {
			return $menu_id;
		}

		if ( isset( $args['items'] ) && is_array( $args['items'] ) ) {
			$res = self::set_items( $menu_id, $args['items'] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		if ( isset( $args['locations'] ) && is_array( $args['locations'] ) ) {
			self::assign_locations( $menu_id, $args['locations'] );
		}
		return self::get( $menu_id );
	}

	/**
	 * Update a menu: rename, replace items, and/or reassign locations.
	 *
	 * @param int   $menu_id Menu term id.
	 * @param array $args    name, items[], locations[], append (bool).
	 * @return array|WP_Error
	 */
	public static function update( $menu_id, $args ) {
		$menu = wp_get_nav_menu_object( (int) $menu_id );
		if ( ! $menu ) {
			return PP_Helpers::error( 'pp_no_menu', 'Menu not found.', 404 );
		}
		if ( ! empty( $args['name'] ) ) {
			wp_update_nav_menu_object( $menu_id, array( 'menu-name' => sanitize_text_field( $args['name'] ) ) );
		}
		if ( isset( $args['items'] ) && is_array( $args['items'] ) ) {
			$append = ! empty( $args['append'] );
			if ( ! $append ) {
				self::clear_items( $menu_id );
			}
			$res = self::set_items( $menu_id, $args['items'] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		if ( isset( $args['locations'] ) && is_array( $args['locations'] ) ) {
			self::assign_locations( $menu_id, $args['locations'] );
		}
		return self::get( $menu_id );
	}

	/**
	 * Delete a menu.
	 *
	 * @param int $menu_id Menu term id.
	 * @return array|WP_Error
	 */
	public static function delete( $menu_id ) {
		$menu = wp_get_nav_menu_object( (int) $menu_id );
		if ( ! $menu ) {
			return PP_Helpers::error( 'pp_no_menu', 'Menu not found.', 404 );
		}
		$res = wp_delete_nav_menu( $menu_id );
		if ( is_wp_error( $res ) || ! $res ) {
			return PP_Helpers::error( 'pp_delete_failed', 'Could not delete the menu.', 500 );
		}
		return array( 'deleted' => true, 'id' => (int) $menu_id );
	}

	/**
	 * The theme's registered menu locations and what is assigned to each.
	 *
	 * @return array
	 */
	public static function locations() {
		$registered = get_registered_nav_menus();
		$assigned   = (array) get_nav_menu_locations();
		$out        = array();
		foreach ( $registered as $slug => $label ) {
			$out[] = array(
				'location'    => $slug,
				'label'       => $label,
				'assigned_id' => isset( $assigned[ $slug ] ) ? (int) $assigned[ $slug ] : 0,
			);
		}
		return $out;
	}

	/**
	 * Add a flat/nested list of items to a menu.
	 *
	 * Each item: { "title", one of: "page_id" | "post_id" | "url" | "category_id",
	 * optional "children": [ ...items ] }.
	 *
	 * @param int   $menu_id Menu term id.
	 * @param array $items   Items.
	 * @param int   $parent  Parent menu-item id (for recursion).
	 * @return true|WP_Error
	 */
	private static function set_items( $menu_id, $items, $parent = 0 ) {
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$menu_item_id = self::add_item( $menu_id, $item, $parent );
			if ( is_wp_error( $menu_item_id ) ) {
				return $menu_item_id;
			}
			if ( ! empty( $item['children'] ) && is_array( $item['children'] ) ) {
				$res = self::set_items( $menu_id, $item['children'], $menu_item_id );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
			}
		}
		return true;
	}

	/**
	 * Add one item to a menu.
	 *
	 * @param int   $menu_id Menu term id.
	 * @param array $item    Item definition.
	 * @param int   $parent  Parent menu-item id.
	 * @return int|WP_Error New menu-item id.
	 */
	private static function add_item( $menu_id, $item, $parent ) {
		$title = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '';
		$data  = array(
			'menu-item-status'    => 'publish',
			'menu-item-title'     => $title,
			'menu-item-parent-id' => (int) $parent,
		);

		if ( ! empty( $item['page_id'] ) || ! empty( $item['post_id'] ) ) {
			$object_id                       = (int) ( ! empty( $item['page_id'] ) ? $item['page_id'] : $item['post_id'] );
			$data['menu-item-type']          = 'post_type';
			$data['menu-item-object']        = ! empty( $item['page_id'] ) ? 'page' : get_post_type( $object_id );
			$data['menu-item-object-id']     = $object_id;
			if ( '' === $title ) {
				$data['menu-item-title'] = get_the_title( $object_id );
			}
		} elseif ( ! empty( $item['category_id'] ) ) {
			$data['menu-item-type']      = 'taxonomy';
			$data['menu-item-object']    = 'category';
			$data['menu-item-object-id'] = (int) $item['category_id'];
		} else {
			$data['menu-item-type'] = 'custom';
			$data['menu-item-url']  = esc_url_raw( isset( $item['url'] ) ? $item['url'] : '#' );
		}

		$id = wp_update_nav_menu_item( $menu_id, 0, $data );
		return is_wp_error( $id ) ? $id : (int) $id;
	}

	/**
	 * Remove all items from a menu (for a full replace).
	 *
	 * @param int $menu_id Menu term id.
	 * @return void
	 */
	private static function clear_items( $menu_id ) {
		foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
			wp_delete_post( $item->ID, true );
		}
	}

	/**
	 * Assign a menu to one or more theme locations (merged with existing).
	 *
	 * @param int   $menu_id   Menu term id.
	 * @param array $locations Location slugs.
	 * @return void
	 */
	private static function assign_locations( $menu_id, $locations ) {
		$current = (array) get_theme_mod( 'nav_menu_locations', array() );
		foreach ( $locations as $slug ) {
			$current[ sanitize_key( $slug ) ] = (int) $menu_id;
		}
		set_theme_mod( 'nav_menu_locations', $current );
	}
}
