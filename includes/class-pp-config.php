<?php
/**
 * Configuration assistant — generic, safe access to WordPress state so an agent can
 * read and change plugin/site configuration without a plugin-specific integration.
 *
 * Layers:
 *   1. State     — read/write options, post/term/user meta, and terms.
 *   2. Discovery — enumerate a plugin's option keys, registered settings and REST routes
 *                  so the agent can learn what to set instead of hard-coding it.
 *   3. Passthrough — dispatch a request to any registered REST route (call a plugin's own API).
 *   4. Safety    — every write auto-creates a restore point; dry-run previews the diff;
 *                  a denylist guards the options that can lock a site out.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Config {

	const SNAP_OPTION = 'presspilot_config_snapshots'; // stored snapshots / restore points.
	const MAX_SNAPS   = 25;

	/** Options that can break access to the site — refused unless force=true. */
	const DENY = array( 'siteurl', 'home', 'active_plugins', 'template', 'stylesheet', 'db_version' );

	/** Option-name fragments whose values are hidden unless reveal=true. */
	const SECRET = array( 'key', 'secret', 'token', 'password', 'pwd', 'salt', 'nonce', 'auth', 'private' );

	/* ------------------------------------------------------------------ */
	/* Layer 1 — options                                                  */
	/* ------------------------------------------------------------------ */

	private static function is_secret( $name ) {
		foreach ( self::SECRET as $frag ) {
			if ( false !== stripos( $name, $frag ) ) {
				return true;
			}
		}
		return false;
	}

	private static function reveal_val( $name, $value, $reveal ) {
		if ( ! $reveal && self::is_secret( $name ) ) {
			return '[redacted]';
		}
		return maybe_unserialize( $value );
	}

	/**
	 * Read options by explicit keys and/or a name prefix.
	 *
	 * @param array $args { keys:[], prefix:'', reveal:bool }
	 * @return array
	 */
	public static function get_options( $args ) {
		global $wpdb;
		$reveal = ! empty( $args['reveal'] );
		$names  = array();
		if ( ! empty( $args['keys'] ) && is_array( $args['keys'] ) ) {
			$names = array_map( 'strval', $args['keys'] );
		}
		if ( ! empty( $args['prefix'] ) ) {
			$like  = $wpdb->esc_like( (string) $args['prefix'] ) . '%';
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT 500", $like ) );
			$names = array_merge( $names, (array) $found );
		}
		$names = array_values( array_unique( $names ) );
		$out   = array();
		$none  = new stdClass();
		foreach ( $names as $name ) {
			$val = get_option( $name, $none );
			$out[ $name ] = array(
				'exists' => $val !== $none,
				'value'  => $val === $none ? null : self::reveal_val( $name, $val, $reveal ),
			);
		}
		return array( 'options' => $out, 'count' => count( $out ) );
	}

	/**
	 * Write options. Auto-creates a restore point; dry-run only previews the diff.
	 *
	 * @param array $args { options:{name:value}, dry_run:bool, force:bool, label:'' }
	 * @return array|WP_Error
	 */
	public static function set_options( $args ) {
		$map = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();
		if ( empty( $map ) ) {
			return PP_Helpers::error( 'pp_missing_options', 'Provide an "options" map { name: value }.', 400 );
		}
		$dry   = ! empty( $args['dry_run'] );
		$force = ! empty( $args['force'] );
		$none  = new stdClass();

		$changes = array();
		foreach ( $map as $name => $new ) {
			$name = (string) $name;
			if ( ! $force && in_array( $name, self::DENY, true ) ) {
				return PP_Helpers::error( 'pp_option_protected', sprintf( 'Option "%s" is protected (can lock the site). Pass force:true to override.', $name ), 400 );
			}
			$old = get_option( $name, $none );
			$changes[] = array(
				'key'      => $name,
				'exists'   => $old !== $none,
				'old'      => $old === $none ? null : maybe_unserialize( $old ),
				'new'      => $new,
				'changed'  => $old === $none || maybe_serialize( $old ) !== maybe_serialize( $new ),
			);
		}

		if ( $dry ) {
			return array( 'dry_run' => true, 'changes' => $changes );
		}

		// Restore point of the affected keys BEFORE writing.
		$restore = self::snapshot( array( 'keys' => array_keys( $map ), 'label' => isset( $args['label'] ) ? (string) $args['label'] : 'before set_options' ) );

		foreach ( $map as $name => $new ) {
			update_option( (string) $name, $new );
		}
		return array( 'applied' => true, 'restore_id' => $restore['id'], 'changes' => $changes );
	}

	/* ------------------------------------------------------------------ */
	/* Layer 1 — meta (post / term / user) & terms                        */
	/* ------------------------------------------------------------------ */

	private static function meta_type( $type ) {
		$type = strtolower( (string) $type );
		return in_array( $type, array( 'post', 'term', 'user', 'comment' ), true ) ? $type : '';
	}

	public static function get_meta( $args ) {
		$type = self::meta_type( isset( $args['type'] ) ? $args['type'] : 'post' );
		$id   = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		if ( '' === $type || $id <= 0 ) {
			return PP_Helpers::error( 'pp_bad_meta_target', 'Provide "type" (post|term|user|comment) and a positive "id".', 400 );
		}
		$reveal = ! empty( $args['reveal'] );
		$all    = get_metadata( $type, $id );
		$keys   = ! empty( $args['keys'] ) && is_array( $args['keys'] ) ? array_map( 'strval', $args['keys'] ) : array_keys( (array) $all );
		$out    = array();
		foreach ( $keys as $k ) {
			$v = get_metadata( $type, $id, $k, true );
			$out[ $k ] = self::reveal_val( $k, $v, $reveal );
		}
		return array( 'type' => $type, 'id' => $id, 'meta' => $out );
	}

	public static function set_meta( $args ) {
		$type = self::meta_type( isset( $args['type'] ) ? $args['type'] : 'post' );
		$id   = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$map  = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
		if ( '' === $type || $id <= 0 || empty( $map ) ) {
			return PP_Helpers::error( 'pp_bad_meta', 'Provide "type", "id" and a "meta" map.', 400 );
		}
		if ( ! empty( $args['dry_run'] ) ) {
			$changes = array();
			foreach ( $map as $k => $v ) {
				$changes[] = array( 'key' => $k, 'old' => get_metadata( $type, $id, $k, true ), 'new' => $v );
			}
			return array( 'dry_run' => true, 'type' => $type, 'id' => $id, 'changes' => $changes );
		}
		foreach ( $map as $k => $v ) {
			update_metadata( $type, $id, (string) $k, $v );
		}
		return array( 'applied' => true, 'type' => $type, 'id' => $id, 'keys' => array_keys( $map ) );
	}

	/** Create (or fetch) a term, optionally with parent + term meta. */
	public static function create_term( $args ) {
		$tax  = isset( $args['taxonomy'] ) ? sanitize_key( $args['taxonomy'] ) : '';
		$name = isset( $args['name'] ) ? (string) $args['name'] : '';
		if ( '' === $tax || ! taxonomy_exists( $tax ) || '' === $name ) {
			return PP_Helpers::error( 'pp_bad_term', 'Provide an existing "taxonomy" and a "name".', 400 );
		}
		$opts = array();
		if ( ! empty( $args['slug'] ) ) {
			$opts['slug'] = sanitize_title( $args['slug'] );
		}
		if ( ! empty( $args['parent'] ) ) {
			$opts['parent'] = (int) $args['parent'];
		}
		if ( ! empty( $args['description'] ) ) {
			$opts['description'] = (string) $args['description'];
		}
		$res = wp_insert_term( $name, $tax, $opts );
		if ( is_wp_error( $res ) ) {
			// Reuse an existing term instead of failing.
			if ( 'term_exists' === $res->get_error_code() ) {
				$res = array( 'term_id' => (int) $res->get_error_data() );
			} else {
				return $res;
			}
		}
		$term_id = (int) $res['term_id'];
		if ( ! empty( $args['meta'] ) && is_array( $args['meta'] ) ) {
			foreach ( $args['meta'] as $k => $v ) {
				update_term_meta( $term_id, (string) $k, $v );
			}
		}
		return array( 'term_id' => $term_id, 'taxonomy' => $tax, 'name' => $name );
	}

	/** Assign terms to an object (post). */
	public static function assign_terms( $args ) {
		$object_id = (int) ( isset( $args['object_id'] ) ? $args['object_id'] : 0 );
		$tax       = isset( $args['taxonomy'] ) ? sanitize_key( $args['taxonomy'] ) : '';
		$terms     = isset( $args['terms'] ) ? $args['terms'] : null;
		if ( $object_id <= 0 || '' === $tax || null === $terms ) {
			return PP_Helpers::error( 'pp_bad_assign', 'Provide "object_id", "taxonomy" and "terms" (id/name or array).', 400 );
		}
		$append = ! empty( $args['append'] );
		$res    = wp_set_object_terms( $object_id, $terms, $tax, $append );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'object_id' => $object_id, 'taxonomy' => $tax, 'term_taxonomy_ids' => array_map( 'intval', (array) $res ) );
	}

	/* ------------------------------------------------------------------ */
	/* Layer 4 — snapshots & restore                                      */
	/* ------------------------------------------------------------------ */

	private static function store() {
		$s = get_option( self::SNAP_OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Capture option values into a snapshot. Scope: explicit keys, a name prefix,
	 * or (default) every autoloaded option.
	 *
	 * @param array $args { keys:[], prefix:'', label:'' }
	 * @return array { id, count }
	 */
	public static function snapshot( $args = array() ) {
		global $wpdb;
		$names = array();
		if ( ! empty( $args['keys'] ) && is_array( $args['keys'] ) ) {
			$names = array_map( 'strval', $args['keys'] );
		} elseif ( ! empty( $args['prefix'] ) ) {
			$like  = $wpdb->esc_like( (string) $args['prefix'] ) . '%';
			$names = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 2000", $like ) );
		} else {
			$names = array_keys( wp_load_alloptions() );
		}
		$none = new stdClass();
		$vals = array();
		foreach ( array_unique( $names ) as $n ) {
			$v = get_option( $n, $none );
			$vals[ $n ] = $v === $none ? array( '__pp_absent' => true ) : $v;
		}
		$id       = (string) round( microtime( true ) * 1000 );
		$snaps    = self::store();
		$snaps[ $id ] = array(
			'id'      => $id,
			'label'   => isset( $args['label'] ) ? (string) $args['label'] : '',
			'time'    => current_time( 'mysql' ),
			'options' => $vals,
		);
		// Keep only the most recent MAX_SNAPS.
		if ( count( $snaps ) > self::MAX_SNAPS ) {
			ksort( $snaps );
			$snaps = array_slice( $snaps, -self::MAX_SNAPS, null, true );
		}
		update_option( self::SNAP_OPTION, $snaps, false );
		return array( 'id' => $id, 'count' => count( $vals ), 'label' => $snaps[ $id ]['label'] );
	}

	public static function list_snapshots() {
		$out = array();
		foreach ( self::store() as $s ) {
			$out[] = array( 'id' => $s['id'], 'label' => $s['label'], 'time' => $s['time'], 'count' => count( $s['options'] ) );
		}
		return array( 'snapshots' => $out );
	}

	public static function diff( $id ) {
		$snaps = self::store();
		if ( empty( $snaps[ $id ] ) ) {
			return PP_Helpers::error( 'pp_snap_not_found', 'Snapshot not found.', 404 );
		}
		$none    = new stdClass();
		$changed = array();
		foreach ( $snaps[ $id ]['options'] as $name => $was ) {
			$was_absent = is_array( $was ) && ! empty( $was['__pp_absent'] );
			$now        = get_option( $name, $none );
			$now_absent = $now === $none;
			if ( $was_absent && $now_absent ) {
				continue;
			}
			if ( $was_absent !== $now_absent || maybe_serialize( $was ) !== maybe_serialize( $now ) ) {
				$changed[] = array(
					'key'  => $name,
					'from' => $was_absent ? null : maybe_unserialize( $was ),
					'to'   => $now_absent ? null : maybe_unserialize( $now ),
					'state'=> $was_absent ? 'added' : ( $now_absent ? 'removed' : 'changed' ),
				);
			}
		}
		return array( 'id' => $id, 'changed' => $changed, 'count' => count( $changed ) );
	}

	public static function restore( $id, $keys = array() ) {
		$snaps = self::store();
		if ( empty( $snaps[ $id ] ) ) {
			return PP_Helpers::error( 'pp_snap_not_found', 'Snapshot not found.', 404 );
		}
		$only     = ! empty( $keys ) ? array_map( 'strval', $keys ) : null;
		$restored = array();
		foreach ( $snaps[ $id ]['options'] as $name => $was ) {
			if ( null !== $only && ! in_array( $name, $only, true ) ) {
				continue;
			}
			if ( is_array( $was ) && ! empty( $was['__pp_absent'] ) ) {
				delete_option( $name );
			} else {
				update_option( $name, maybe_unserialize( $was ) );
			}
			$restored[] = $name;
		}
		return array( 'restored' => $restored, 'count' => count( $restored ), 'from_snapshot' => $id );
	}

	/* ------------------------------------------------------------------ */
	/* Layer 2 — discovery                                                */
	/* ------------------------------------------------------------------ */

	public static function registered_settings() {
		if ( ! function_exists( 'get_registered_settings' ) ) {
			require_once ABSPATH . WPINC . '/option.php';
		}
		$out = array();
		foreach ( (array) get_registered_settings() as $name => $meta ) {
			$out[ $name ] = array(
				'type'        => isset( $meta['type'] ) ? $meta['type'] : 'string',
				'group'       => isset( $meta['group'] ) ? $meta['group'] : '',
				'description' => isset( $meta['description'] ) ? $meta['description'] : '',
				'default'     => isset( $meta['default'] ) ? $meta['default'] : null,
				'show_in_rest'=> ! empty( $meta['show_in_rest'] ),
			);
		}
		return array( 'settings' => $out, 'count' => count( $out ) );
	}

	public static function rest_routes( $prefix = '' ) {
		$routes = array();
		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( '' !== $prefix && 0 !== strpos( ltrim( $route, '/' ), ltrim( $prefix, '/' ) ) ) {
				continue;
			}
			$methods = array();
			foreach ( $handlers as $h ) {
				if ( ! empty( $h['methods'] ) && is_array( $h['methods'] ) ) {
					$methods = array_merge( $methods, array_keys( array_filter( $h['methods'] ) ) );
				}
			}
			$routes[ $route ] = array_values( array_unique( $methods ) );
		}
		return array( 'routes' => $routes, 'count' => count( $routes ) );
	}

	/**
	 * Best-effort discovery report for a plugin: its option keys (by prefix), the
	 * registered settings that match, and any REST routes it exposes.
	 *
	 * @param array $args { slug:'', prefix:'' }
	 * @return array
	 */
	public static function discover( $args ) {
		$slug   = isset( $args['slug'] ) ? sanitize_key( $args['slug'] ) : '';
		$prefix = isset( $args['prefix'] ) ? (string) $args['prefix'] : '';
		if ( '' === $prefix ) {
			$prefix = $slug ? str_replace( '-', '_', $slug ) : '';
		}
		if ( '' === $prefix ) {
			return PP_Helpers::error( 'pp_missing_prefix', 'Provide a plugin "slug" or an option "prefix".', 400 );
		}
		$opts     = self::get_options( array( 'prefix' => $prefix ) );
		$reg      = self::registered_settings();
		$matching = array();
		foreach ( $reg['settings'] as $name => $meta ) {
			if ( 0 === stripos( $name, $prefix ) ) {
				$matching[ $name ] = $meta;
			}
		}
		$routes = self::rest_routes( '/' . ( $slug ? $slug : $prefix ) );
		return array(
			'prefix'             => $prefix,
			'option_keys'        => array_keys( $opts['options'] ),
			'registered_settings'=> $matching,
			'rest_routes'        => $routes['routes'],
			'hint'               => 'Use POST /options to write settings, or the plugin\'s own REST routes via POST /proxy. Use /config/snapshot + /config/diff to learn keys by observing a change made in wp-admin.',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Layer 3 — REST passthrough                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Dispatch an internal request to any registered REST route, acting as an
	 * administrator, so an agent can drive a plugin's own REST API.
	 *
	 * @param array $args { method, path, body, as_admin }
	 * @return array
	 */
	public static function proxy( $args ) {
		$method = strtoupper( isset( $args['method'] ) ? (string) $args['method'] : 'GET' );
		$path   = isset( $args['path'] ) ? (string) $args['path'] : '';
		if ( '' === $path ) {
			return PP_Helpers::error( 'pp_missing_path', 'Provide a REST "path" (e.g. /wc/v3/settings).', 400 );
		}
		$as_admin = ! isset( $args['as_admin'] ) || ! empty( $args['as_admin'] );
		$prev     = get_current_user_id();
		if ( $as_admin ) {
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
			if ( ! empty( $admins ) ) {
				wp_set_current_user( (int) $admins[0] );
			}
		}
		$req = new WP_REST_Request( $method, '/' . ltrim( $path, '/' ) );
		if ( isset( $args['body'] ) && is_array( $args['body'] ) ) {
			$req->set_body_params( $args['body'] );
			foreach ( $args['body'] as $k => $v ) {
				$req->set_param( $k, $v );
			}
		}
		if ( isset( $args['query'] ) && is_array( $args['query'] ) ) {
			foreach ( $args['query'] as $k => $v ) {
				$req->set_param( $k, $v );
			}
		}
		$resp = rest_do_request( $req );
		if ( $as_admin ) {
			wp_set_current_user( $prev );
		}
		return array(
			'status' => $resp->get_status(),
			'data'   => $resp->get_data(),
		);
	}
}
