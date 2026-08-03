<?php
/**
 * Site-speed diagnostics.
 *
 * Gives an agent one call to reason about why a site is slow — separating
 * page-weight problems (huge inline CSS, base64 fonts, many requests) from
 * server problems (slow TTFB, no opcache, no persistent object cache, bloated
 * autoloaded options, no gzip, no page cache) — plus actionable recommendations.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Perf {

	/**
	 * Build the full performance report, optionally profiling a specific URL.
	 *
	 * @param string $url URL to fetch & analyze (defaults to the home page).
	 * @return array
	 */
	public static function report( $url = '' ) {
		$url = $url ? esc_url_raw( $url ) : home_url( '/' );

		$server   = self::server();
		$wp       = self::wordpress();
		$fetch    = self::fetch( $url );
		$html     = isset( $fetch['_body'] ) ? self::analyze_html( $fetch['_body'] ) : array();
		unset( $fetch['_body'] );

		$report = array(
			'url'          => $url,
			'server'       => $server,
			'wordpress'    => $wp,
			'response'     => $fetch,
			'page_weight'  => $html,
		);
		$report['recommendations'] = self::recommendations( $report );
		$report['verdict']         = empty( $report['recommendations'] ) ? 'healthy' : 'has_issues';
		return $report;
	}

	/** Server / PHP runtime facts that affect speed. */
	private static function server() {
		$opcache = false;
		if ( function_exists( 'opcache_get_status' ) ) {
			$status  = @opcache_get_status( false );
			$opcache = is_array( $status ) && ! empty( $status['opcache_enabled'] );
		}
		return array(
			'php_version'         => PHP_VERSION,
			'memory_limit'        => ini_get( 'memory_limit' ),
			'max_execution_time'  => ini_get( 'max_execution_time' ),
			'opcache_enabled'     => $opcache,
			'opcache_ok'          => $opcache, // false is a common, fixable slowdown.
			'gzip_supported'      => function_exists( 'gzencode' ),
			'server_software'     => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
		);
	}

	/** WordPress-level facts (caching, plugin count, option bloat). */
	private static function wordpress() {
		global $wpdb;

		// Autoloaded options — a very common, invisible slowdown when bloated.
		$autoload_bytes = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'" );
		$autoload_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload = 'yes'" );
		$biggest        = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload = 'yes' ORDER BY bytes DESC LIMIT 5" );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = (array) get_option( 'active_plugins', array() );

		return array(
			'active_plugins'          => count( $active ),
			'persistent_object_cache' => function_exists( 'wp_using_ext_object_cache' ) ? (bool) wp_using_ext_object_cache() : false,
			'page_cache_plugin'       => self::detect_page_cache(),
			'wp_debug'                => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'autoloaded_options'      => array(
				'count'    => $autoload_count,
				'bytes'    => $autoload_bytes,
				'kb'       => round( $autoload_bytes / 1024, 1 ),
				'biggest'  => array_map(
					function ( $r ) {
						return array( 'name' => $r->option_name, 'bytes' => (int) $r->bytes );
					},
					is_array( $biggest ) ? $biggest : array()
				),
			),
		);
	}

	/** Fetch a URL server-side and measure timing / size / headers. */
	private static function fetch( $url ) {
		$start = microtime( true );
		$res   = wp_remote_get( $url, array( 'timeout' => 20, 'redirection' => 2, 'sslverify' => false ) );
		$ms    = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $res ) ) {
			return array( 'error' => $res->get_error_message(), 'time_ms' => $ms );
		}
		$body    = (string) wp_remote_retrieve_body( $res );
		$headers = wp_remote_retrieve_headers( $res );
		$get     = function ( $k ) use ( $headers ) {
			return isset( $headers[ $k ] ) ? ( is_array( $headers[ $k ] ) ? implode( ',', $headers[ $k ] ) : $headers[ $k ] ) : '';
		};

		return array(
			'status'            => (int) wp_remote_retrieve_response_code( $res ),
			'time_ms'           => $ms,          // round-trip incl. TTFB, from this server.
			'html_bytes'        => strlen( $body ),
			'html_kb'           => round( strlen( $body ) / 1024, 1 ),
			'content_encoding'  => $get( 'content-encoding' ),  // '' => gzip likely OFF.
			'cache_header'      => $get( 'cache-control' ),
			'x_cache'           => $get( 'x-cache' ) . $get( 'x-cache-status' ) . $get( 'cf-cache-status' ),
			'server'            => $get( 'server' ),
			'_body'             => $body,
		);
	}

	/** Parse rendered HTML for weight problems. */
	private static function analyze_html( $body ) {
		$inline_css = 0;
		if ( preg_match_all( '#<style\b[^>]*>(.*?)</style>#is', $body, $m ) ) {
			foreach ( $m[1] as $chunk ) {
				$inline_css += strlen( $chunk );
			}
		}
		$inline_js = 0;
		if ( preg_match_all( '#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#is', $body, $mj ) ) {
			foreach ( $mj[1] as $chunk ) {
				$inline_js += strlen( $chunk );
			}
		}
		$base64 = 0;
		if ( preg_match_all( '#base64,([A-Za-z0-9+/=]+)#', $body, $mb ) ) {
			foreach ( $mb[1] as $chunk ) {
				$base64 += strlen( $chunk );
			}
		}
		return array(
			'html_bytes'        => strlen( $body ),
			'inline_css_bytes'  => $inline_css,
			'inline_js_bytes'   => $inline_js,
			'base64_bytes'      => $base64,
			'base64_pct_of_html'=> strlen( $body ) ? (int) round( $base64 * 100 / strlen( $body ) ) : 0,
			'external_css'      => preg_match_all( '#<link\b[^>]*rel=["\']?stylesheet#i', $body ),
			'external_js'       => preg_match_all( '#<script\b[^>]*\bsrc=#i', $body ),
			'images'            => preg_match_all( '#<img\b#i', $body ),
		);
	}

	/** Best-effort detection of a known page-cache plugin. */
	private static function detect_page_cache() {
		$known = array(
			'wp-super-cache/wp-cache.php'         => 'WP Super Cache',
			'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
			'wp-rocket/wp-rocket.php'             => 'WP Rocket',
			'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
			'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
			'cache-enabler/cache-enabler.php'     => 'Cache Enabler',
		);
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( $known as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				return $name;
			}
		}
		return ( defined( 'WP_CACHE' ) && WP_CACHE ) ? 'WP_CACHE constant on' : false;
	}

	/** Turn findings into prioritized, human-readable fixes. */
	private static function recommendations( $r ) {
		$rec  = array();
		$w    = isset( $r['page_weight'] ) ? $r['page_weight'] : array();
		$resp = isset( $r['response'] ) ? $r['response'] : array();
		$wp   = isset( $r['wordpress'] ) ? $r['wordpress'] : array();
		$sv   = isset( $r['server'] ) ? $r['server'] : array();

		if ( ! empty( $w['base64_bytes'] ) && $w['base64_bytes'] > 40000 ) {
			$rec[] = sprintf( 'Move %d KB of base64 assets (fonts/images) out of inline CSS into real cacheable files (POST /assets/upload) — %d%% of the HTML is base64.', (int) round( $w['base64_bytes'] / 1024 ), (int) $w['base64_pct_of_html'] );
		}
		if ( ! empty( $w['inline_css_bytes'] ) && $w['inline_css_bytes'] > 100000 ) {
			$rec[] = sprintf( '%d KB of render-blocking inline CSS in the <head>; host large CSS as an external cacheable file.', (int) round( $w['inline_css_bytes'] / 1024 ) );
		}
		if ( isset( $resp['html_kb'] ) && $resp['html_kb'] > 150 ) {
			$rec[] = sprintf( 'HTML document is %s KB (target < 100 KB); most of it is inline assets — externalize them.', $resp['html_kb'] );
		}
		if ( isset( $resp['time_ms'] ) && $resp['time_ms'] > 800 ) {
			$rec[] = sprintf( 'Server response is slow (%d ms round-trip from the host). With no page cache this points at the server/host; add a page-cache plugin and check hosting.', (int) $resp['time_ms'] );
		}
		if ( empty( $sv['opcache_enabled'] ) ) {
			$rec[] = 'PHP OPcache is OFF — enabling it typically cuts server time substantially (host/php.ini setting).';
		}
		if ( empty( $wp['persistent_object_cache'] ) ) {
			$rec[] = 'No persistent object cache (Redis/Memcached) — repeated DB queries aren\'t cached across requests.';
		}
		if ( empty( $wp['page_cache_plugin'] ) ) {
			$rec[] = 'No page-cache detected — a full-page cache is the single biggest win for TTFB on shared hosting.';
		}
		if ( empty( $resp['content_encoding'] ) ) {
			$rec[] = 'Response is not gzip/brotli compressed — enable compression at the server for a large transfer-size cut.';
		}
		if ( isset( $wp['autoloaded_options']['bytes'] ) && $wp['autoloaded_options']['bytes'] > 800000 ) {
			$rec[] = sprintf( 'Autoloaded options are %s KB (target < 800 KB) — bloat here slows every request; clean orphaned option rows.', $wp['autoloaded_options']['kb'] );
		}
		return $rec;
	}
}
