<?php
/**
 * Cache management.
 *
 * An agent that edits a page and then fetches the live URL to check its work is
 * usually looking at whatever the page cache decided to serve. That one problem
 * — invisible, and indistinguishable from "my edit did not apply" — is what this
 * layer exists to remove.
 *
 * It gives three things:
 *   1. one call that says which cache layers this site actually has;
 *   2. one purge that reaches all of them, whichever plugin is installed;
 *   3. an automatic purge after PressPilot's own writes, so the live URL an
 *      agent fetches is the page it just wrote.
 *
 * Every driver is probed before it is called, so a plugin that is absent, or an
 * API that moved between versions, is skipped rather than fatal. Nothing here
 * writes a cache plugin's settings; the registry instead reports WHERE each
 * plugin keeps them, so an agent can read and change them through /options,
 * which already snapshots and can roll back.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Cache {

	/** Auto-purge toggle. Default on. */
	const OPTION_AUTO = 'presspilot_cache_auto_purge';

	/** True once we know this request is a PressPilot one. */
	private static $ours = false;

	/** Post ids written during this request; empty array means "something else changed". */
	private static $dirty = array();

	/** Set when anything at all changed, even if no specific post id is known. */
	private static $touched = false;

	/**
	 * Hook the auto-purge. Called once from the plugin bootstrap.
	 *
	 * Purging happens once, at shutdown, and only for PressPilot's own requests:
	 * a batch that writes twenty pages should clear the cache once at the end,
	 * not twenty times in the middle.
	 */
	public static function init() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'mark_request' ), 10, 3 );
		add_action( 'save_post', array( __CLASS__, 'mark_post' ), 10, 1 );
		add_action( 'deleted_post', array( __CLASS__, 'mark_post' ), 10, 1 );
		add_action( 'updated_option', array( __CLASS__, 'mark_option' ), 10, 1 );
		add_action( 'shutdown', array( __CLASS__, 'maybe_auto_purge' ), 99 );
	}

	/**
	 * Note that the request being dispatched belongs to us.
	 *
	 * @param mixed            $result  Dispatch result so far.
	 * @param WP_REST_Server   $server  Server instance.
	 * @param WP_REST_Request  $request The request.
	 * @return mixed Untouched $result — this is a filter used only to observe.
	 */
	public static function mark_request( $result, $server, $request ) {
		if ( is_object( $request ) && method_exists( $request, 'get_route' )
			&& 0 === strpos( (string) $request->get_route(), '/' . PP_REST_NS ) ) {
			self::$ours = true;
		}
		return $result;
	}

	/**
	 * Remember a post that changed, so its own URL can be purged precisely.
	 *
	 * @param int $post_id Post id.
	 */
	public static function mark_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id > 0 && ! in_array( $post_id, self::$dirty, true ) ) {
			self::$dirty[] = $post_id;
		}
		self::$touched = true;
	}

	/**
	 * Treat a change to a presentation option as a reason to purge everything:
	 * CSS and global styles affect every page, not one.
	 *
	 * @param string $option Option name.
	 */
	public static function mark_option( $option ) {
		$option = (string) $option;
		if ( 0 === strpos( $option, 'presspilot_' ) ) {
			return; // our own bookkeeping is not site content
		}
		self::$touched = true;
		self::$dirty   = array(); // site-wide change: a targeted purge is not enough
	}

	/** Is the automatic purge switched on? */
	public static function auto_enabled() {
		$v = get_option( self::OPTION_AUTO, null );
		return ( null === $v ) ? true : (bool) $v;
	}

	/**
	 * Purge once at the end of a PressPilot request that changed something.
	 */
	public static function maybe_auto_purge() {
		if ( ! self::$ours || ! self::$touched || ! self::auto_enabled() ) {
			return;
		}
		self::$touched = false; // never run twice in one request
		if ( ! empty( self::$dirty ) ) {
			foreach ( self::$dirty as $id ) {
				self::purge( array( 'scope' => 'post', 'post_id' => $id ) );
			}
			return;
		}
		self::purge( array( 'scope' => 'all' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Driver registry                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Every cache layer we know how to talk to.
	 *
	 * `settings` is informational: it names where the plugin keeps its options so
	 * an agent can read or change them through /options (which snapshots first).
	 * We do not write them here — those keys move between plugin versions, and a
	 * wrong guess would silently mangle someone's configuration.
	 *
	 * @return array
	 */
	private static function drivers() {
		return array(

			'wp-rocket' => array(
				'name'     => 'WP Rocket',
				'file'     => 'wp-rocket/wp-rocket.php',
				'caches'   => 'pages, CSS/JS minification, lazy loading, preload',
				'settings' => array( 'option' => 'wp_rocket_settings', 'type' => 'array' ),
				'active'   => function () {
					return defined( 'WP_ROCKET_VERSION' ) || function_exists( 'rocket_clean_domain' );
				},
				'version'  => function () {
					return defined( 'WP_ROCKET_VERSION' ) ? WP_ROCKET_VERSION : null;
				},
				'all'      => function () {
					if ( ! function_exists( 'rocket_clean_domain' ) ) {
						return false;
					}
					rocket_clean_domain();
					if ( function_exists( 'rocket_clean_minify' ) ) {
						rocket_clean_minify();
					}
					return true;
				},
				'post'     => function ( $id ) {
					if ( ! function_exists( 'rocket_clean_post' ) ) {
						return false;
					}
					rocket_clean_post( $id );
					return true;
				},
				'url'      => function ( $url ) {
					if ( ! function_exists( 'rocket_clean_files' ) ) {
						return false;
					}
					rocket_clean_files( array( $url ) );
					return true;
				},
			),

			'litespeed-cache' => array(
				'name'     => 'LiteSpeed Cache',
				'file'     => 'litespeed-cache/litespeed-cache.php',
				'caches'   => 'pages (server-level on LiteSpeed/OpenLiteSpeed), CSS/JS optimisation, image optimisation',
				'settings' => array( 'option_prefix' => 'litespeed.conf.', 'type' => 'one option per setting' ),
				'active'   => function () {
					return defined( 'LSCWP_V' ) || class_exists( '\\LiteSpeed\\Core' ) || class_exists( 'LiteSpeed_Cache' );
				},
				'version'  => function () {
					return defined( 'LSCWP_V' ) ? LSCWP_V : null;
				},
				'all'      => function () {
					// The documented, version-stable entry point.
					do_action( 'litespeed_purge_all' );
					return true;
				},
				'post'     => function ( $id ) {
					do_action( 'litespeed_purge_post', $id );
					return true;
				},
				'url'      => function ( $url ) {
					do_action( 'litespeed_purge_url', $url );
					return true;
				},
			),

			'wp-optimize' => array(
				'name'     => 'WP-Optimize',
				'file'     => 'wp-optimize/wp-optimize.php',
				'caches'   => 'pages, database cleanup, image compression, minification',
				'settings' => array( 'option' => 'wpo_cache_config', 'type' => 'array' ),
				'active'   => function () {
					return function_exists( 'WP_Optimize' ) || class_exists( 'WPO_Page_Cache' );
				},
				'version'  => function () {
					return defined( 'WPO_VERSION' ) ? WPO_VERSION : null;
				},
				'all'      => function () {
					if ( class_exists( 'WPO_Page_Cache' ) && method_exists( 'WPO_Page_Cache', 'instance' ) ) {
						$c = WPO_Page_Cache::instance();
						if ( is_object( $c ) && method_exists( $c, 'purge' ) ) {
							$c->purge();
							return true;
						}
					}
					if ( function_exists( 'WP_Optimize' ) ) {
						$wpo = WP_Optimize();
						if ( is_object( $wpo ) && method_exists( $wpo, 'get_page_cache' ) ) {
							$pc = $wpo->get_page_cache();
							if ( is_object( $pc ) && method_exists( $pc, 'purge' ) ) {
								$pc->purge();
								return true;
							}
						}
					}
					return false;
				},
				'post'     => function ( $id ) {
					if ( class_exists( 'WPO_Page_Cache' ) && method_exists( 'WPO_Page_Cache', 'delete_single_post_cache' ) ) {
						WPO_Page_Cache::delete_single_post_cache( $id );
						return true;
					}
					return false;
				},
				'url'      => null,
			),

			'w3-total-cache' => array(
				'name'     => 'W3 Total Cache',
				'file'     => 'w3-total-cache/w3-total-cache.php',
				'caches'   => 'pages, database, objects, CDN, minification',
				'settings' => array( 'note' => 'stored in wp-content/w3tc-config/*.json, not the options table' ),
				'active'   => function () {
					return defined( 'W3TC' ) || function_exists( 'w3tc_flush_all' );
				},
				'version'  => function () {
					return defined( 'W3TC_VERSION' ) ? W3TC_VERSION : null;
				},
				'all'      => function () {
					if ( ! function_exists( 'w3tc_flush_all' ) ) {
						return false;
					}
					w3tc_flush_all();
					return true;
				},
				'post'     => function ( $id ) {
					if ( ! function_exists( 'w3tc_flush_post' ) ) {
						return false;
					}
					w3tc_flush_post( $id );
					return true;
				},
				'url'      => function ( $url ) {
					if ( ! function_exists( 'w3tc_flush_url' ) ) {
						return false;
					}
					w3tc_flush_url( $url );
					return true;
				},
			),

			'wp-super-cache' => array(
				'name'     => 'WP Super Cache',
				'file'     => 'wp-super-cache/wp-cache.php',
				'caches'   => 'pages (static files)',
				'settings' => array( 'note' => 'stored in wp-content/wp-cache-config.php' ),
				'active'   => function () {
					return function_exists( 'wp_cache_clear_cache' );
				},
				'version'  => function () {
					return null;
				},
				'all'      => function () {
					wp_cache_clear_cache();
					return true;
				},
				'post'     => function ( $id ) {
					if ( ! function_exists( 'wpsc_delete_post_cache' ) ) {
						return false;
					}
					wpsc_delete_post_cache( $id );
					return true;
				},
				'url'      => function ( $url ) {
					if ( ! function_exists( 'wpsc_delete_url_cache' ) ) {
						return false;
					}
					wpsc_delete_url_cache( $url );
					return true;
				},
			),

			'wp-fastest-cache' => array(
				'name'     => 'WP Fastest Cache',
				'file'     => 'wp-fastest-cache/wpFastestCache.php',
				'caches'   => 'pages, minification',
				'settings' => array( 'option' => 'WpFastestCache', 'type' => 'json string' ),
				'active'   => function () {
					return class_exists( 'WpFastestCache' );
				},
				'version'  => function () {
					return null;
				},
				'all'      => function () {
					if ( empty( $GLOBALS['wp_fastest_cache'] ) || ! method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
						return false;
					}
					$GLOBALS['wp_fastest_cache']->deleteCache( true );
					return true;
				},
				'post'     => null,
				'url'      => null,
			),

			'cache-enabler' => array(
				'name'     => 'Cache Enabler',
				'file'     => 'cache-enabler/cache-enabler.php',
				'caches'   => 'pages (static files)',
				'settings' => array( 'option' => 'cache_enabler', 'type' => 'array' ),
				'active'   => function () {
					return class_exists( 'Cache_Enabler' );
				},
				'version'  => function () {
					return defined( 'CACHE_ENABLER_VERSION' ) ? CACHE_ENABLER_VERSION : null;
				},
				'all'      => function () {
					if ( method_exists( 'Cache_Enabler', 'clear_complete_cache' ) ) {
						Cache_Enabler::clear_complete_cache();
						return true;
					}
					if ( method_exists( 'Cache_Enabler', 'clear_total_cache' ) ) {
						Cache_Enabler::clear_total_cache();
						return true;
					}
					return false;
				},
				'post'     => function ( $id ) {
					if ( ! method_exists( 'Cache_Enabler', 'clear_page_cache_by_post_id' ) ) {
						return false;
					}
					Cache_Enabler::clear_page_cache_by_post_id( $id );
					return true;
				},
				'url'      => function ( $url ) {
					if ( ! method_exists( 'Cache_Enabler', 'clear_page_cache_by_url' ) ) {
						return false;
					}
					Cache_Enabler::clear_page_cache_by_url( $url );
					return true;
				},
			),

			'autoptimize' => array(
				'name'     => 'Autoptimize',
				'file'     => 'autoptimize/autoptimize.php',
				'caches'   => 'aggregated CSS/JS (not pages)',
				'settings' => array( 'option_prefix' => 'autoptimize_', 'type' => 'one option per setting' ),
				'active'   => function () {
					return class_exists( 'autoptimizeCache' );
				},
				'version'  => function () {
					return defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ) ? AUTOPTIMIZE_PLUGIN_VERSION : null;
				},
				'all'      => function () {
					if ( ! method_exists( 'autoptimizeCache', 'clearall' ) ) {
						return false;
					}
					autoptimizeCache::clearall();
					return true;
				},
				'post'     => null,
				'url'      => null,
			),

			'breeze' => array(
				'name'     => 'Breeze',
				'file'     => 'breeze/breeze.php',
				'caches'   => 'pages, minification, Varnish',
				'settings' => array( 'option_prefix' => 'breeze_', 'type' => 'one option per setting' ),
				'active'   => function () {
					return class_exists( 'Breeze_Admin' ) || defined( 'BREEZE_VERSION' );
				},
				'version'  => function () {
					return defined( 'BREEZE_VERSION' ) ? BREEZE_VERSION : null;
				},
				'all'      => function () {
					do_action( 'breeze_clear_all_cache' );
					return true;
				},
				'post'     => null,
				'url'      => null,
			),

			'sg-optimizer' => array(
				'name'     => 'SiteGround Optimizer',
				'file'     => 'sg-cachepress/sg-cachepress.php',
				'caches'   => 'pages (SiteGround dynamic cache), minification',
				'settings' => array( 'option_prefix' => 'siteground_optimizer_', 'type' => 'one option per setting' ),
				'active'   => function () {
					return function_exists( 'sg_cachepress_purge_cache' ) || defined( 'SiteGround_Optimizer\VERSION' );
				},
				'version'  => function () {
					return null;
				},
				'all'      => function () {
					if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
						sg_cachepress_purge_cache();
						return true;
					}
					do_action( 'siteground_optimizer_flush_cache' );
					return true;
				},
				'post'     => null,
				'url'      => null,
			),

			'hummingbird' => array(
				'name'     => 'Hummingbird',
				'file'     => 'hummingbird-performance/wp-hummingbird.php',
				'caches'   => 'pages, asset optimisation',
				'settings' => array( 'option' => 'wphb_settings', 'type' => 'array' ),
				'active'   => function () {
					return class_exists( '\\Hummingbird\\Core\\Utils' ) || defined( 'WPHB_VERSION' );
				},
				'version'  => function () {
					return defined( 'WPHB_VERSION' ) ? WPHB_VERSION : null;
				},
				'all'      => function () {
					do_action( 'wphb_clear_page_cache' );
					return true;
				},
				'post'     => null,
				'url'      => null,
			),

			'nginx-helper' => array(
				'name'     => 'Nginx Helper',
				'file'     => 'nginx-helper/nginx-helper.php',
				'caches'   => 'nginx FastCGI / Redis page cache',
				'settings' => array( 'option' => 'rt_wp_nginx_helper_options', 'type' => 'array' ),
				'active'   => function () {
					return class_exists( 'Nginx_Helper' ) || defined( 'NGINX_HELPER_BASENAME' );
				},
				'version'  => function () {
					return null;
				},
				'all'      => function () {
					do_action( 'rt_nginx_helper_purge_all' );
					return true;
				},
				'post'     => null,
				'url'      => null,
			),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Reporting                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Is this driver's plugin running?
	 *
	 * Trusts its own probe first (a constant or function the plugin defines), and
	 * falls back to the active-plugins list so a plugin that is switched on but
	 * has not finished loading still counts as present.
	 *
	 * @param array $d            Driver.
	 * @param array $active_files active_plugins.
	 * @return bool
	 */
	private static function is_active( $d, $active_files ) {
		return (bool) call_user_func( $d['active'] ) || in_array( $d['file'], (array) $active_files, true );
	}

	/**
	 * What this site actually has in front of it.
	 *
	 * @return array
	 */
	public static function detect() {
		$active_files = (array) get_option( 'active_plugins', array() );

		// Installed-but-switched-off matters too: "you have WP Rocket, it is just
		// not on" is a different answer from "there is no cache plugin here".
		if ( ! function_exists( 'get_plugins' ) ) {
			// Hardened installs can move wp-admin; a hard require here would take
			// the whole response down just to list plugins we can live without.
			$plugin_api = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( file_exists( $plugin_api ) ) {
				require_once $plugin_api;
			}
		}
		$installed = function_exists( 'get_plugins' ) ? (array) get_plugins() : array();

		$layers = array();
		foreach ( self::drivers() as $slug => $d ) {
			$is_active = self::is_active( $d, $active_files );
			$present   = $is_active || isset( $installed[ $d['file'] ] );
			if ( ! $present ) {
				continue;
			}
			$layers[] = array(
				'slug'       => $slug,
				'name'       => $d['name'],
				'plugin'     => $d['file'],
				'active'     => $is_active,
				'version'    => ( $d['version'] ? call_user_func( $d['version'] ) : null )
					?: ( isset( $installed[ $d['file'] ]['Version'] ) ? $installed[ $d['file'] ]['Version'] : null ),
				'caches'     => $d['caches'],
				'can_purge'  => array(
					'all'  => ! empty( $d['all'] ),
					'post' => ! empty( $d['post'] ),
					'url'  => ! empty( $d['url'] ),
				),
				'settings'   => $d['settings'],
			);
		}

		return array(
			'page_cache'    => self::page_cache_summary( $layers ),
			'layers'        => $layers,
			'object_cache'  => array(
				'persistent' => (bool) wp_using_ext_object_cache(),
				'dropin'     => file_exists( WP_CONTENT_DIR . '/object-cache.php' ),
			),
			'advanced_cache' => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ),
			'wp_cache_const' => defined( 'WP_CACHE' ) && WP_CACHE,
			'opcache'        => function_exists( 'opcache_get_status' ),
			'auto_purge'     => self::auto_enabled(),
		);
	}

	/**
	 * One sentence an agent can act on.
	 *
	 * @param array $layers Detected layers.
	 * @return string
	 */
	private static function page_cache_summary( $layers ) {
		$active = array();
		foreach ( $layers as $l ) {
			if ( $l['active'] ) {
				$active[] = $l['name'];
			}
		}
		if ( ! empty( $active ) ) {
			return implode( ', ', $active );
		}
		$off = array();
		foreach ( $layers as $l ) {
			if ( ! $l['active'] ) {
				$off[] = $l['name'];
			}
		}
		if ( ! empty( $off ) ) {
			return 'none active — installed but switched off: ' . implode( ', ', $off );
		}
		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			return 'WP_CACHE is on but no known plugin was detected — the host may cache above WordPress.';
		}
		return 'none detected';
	}

	/* ------------------------------------------------------------------ */
	/* Purging                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Clear caches.
	 *
	 * args: {
	 *   scope:   "all" (default) | "post" | "url",
	 *   post_id: int   — required for scope "post",
	 *   url:     string— required for scope "url",
	 *   layers:  array — limit to these driver slugs,
	 *   object_cache: bool — also wp_cache_flush(),
	 *   opcache: bool  — also opcache_reset()
	 * }
	 *
	 * A driver that cannot do the narrow scope falls back to purging everything,
	 * which is reported, so "I asked for one URL and it cleared the site" is
	 * never a surprise.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function purge( $args = array() ) {
		$scope = isset( $args['scope'] ) ? sanitize_key( $args['scope'] ) : 'all';
		$only  = isset( $args['layers'] ) && is_array( $args['layers'] )
			? array_map( 'sanitize_key', $args['layers'] ) : null;

		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$url     = isset( $args['url'] ) ? esc_url_raw( (string) $args['url'] ) : '';

		if ( 'post' === $scope ) {
			if ( $post_id <= 0 ) {
				return PP_Helpers::error( 'pp_cache_no_post', 'scope "post" needs a post_id.', 400 );
			}
			if ( ! $url ) {
				$permalink = get_permalink( $post_id );
				$url       = $permalink ? $permalink : '';
			}
		}
		if ( 'url' === $scope && '' === $url ) {
			return PP_Helpers::error( 'pp_cache_no_url', 'scope "url" needs a url.', 400 );
		}

		$active_files = (array) get_option( 'active_plugins', array() );

		$results = array();
		foreach ( self::drivers() as $slug => $d ) {
			if ( null !== $only && ! in_array( $slug, $only, true ) ) {
				continue;
			}
			if ( ! self::is_active( $d, $active_files ) ) {
				continue;
			}

			$did      = false;
			$how      = $scope;
			$fallback = false;

			if ( 'post' === $scope && ! empty( $d['post'] ) ) {
				$did = (bool) call_user_func( $d['post'], $post_id );
			} elseif ( 'url' === $scope && ! empty( $d['url'] ) ) {
				$did = (bool) call_user_func( $d['url'], $url );
			} elseif ( 'post' === $scope && '' !== $url && ! empty( $d['url'] ) ) {
				$did = (bool) call_user_func( $d['url'], $url );
				$how = 'url';
			}

			if ( ! $did && ! empty( $d['all'] ) ) {
				$did      = (bool) call_user_func( $d['all'] );
				$fallback = ( 'all' !== $scope );
				$how      = 'all';
			}

			$results[ $slug ] = array(
				'name'    => $d['name'],
				'purged'  => $did,
				'scope'   => $did ? $how : null,
				'widened' => $did ? $fallback : false, // asked for something narrower than it could do
				'reason'  => $did ? null
					: 'The plugin is switched on but its purge API was not callable — it may be a version whose API moved, or it may not have loaded.',
			);
		}

		$extra = array();
		if ( ! empty( $args['object_cache'] ) ) {
			$extra['object_cache'] = function_exists( 'wp_cache_flush' ) ? (bool) wp_cache_flush() : false;
		}
		if ( ! empty( $args['opcache'] ) && function_exists( 'opcache_reset' ) ) {
			$extra['opcache'] = (bool) @opcache_reset(); // phpcs:ignore
		}

		return array(
			'purged'  => ! empty( $results ) || ! empty( $extra ),
			'scope'   => $scope,
			'post_id' => $post_id ? $post_id : null,
			'url'     => $url ? $url : null,
			'layers'  => $results,
			'extra'   => $extra,
			'note'    => empty( $results )
				? 'No active cache plugin was found. If the site is still serving stale HTML, the cache is above WordPress (host, Cloudflare or a reverse proxy).'
				: null,
		);
	}

	/**
	 * Turn the automatic purge on or off.
	 *
	 * @param bool $enabled Desired state.
	 * @return array
	 */
	public static function set_auto( $enabled ) {
		update_option( self::OPTION_AUTO, $enabled ? 1 : 0 );
		return array( 'auto_purge' => (bool) $enabled );
	}
}
