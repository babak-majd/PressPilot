<?php
/**
 * API-key authentication for the REST API.
 *
 * The key is stored in an option and never exposed except on the settings page
 * to a logged-in administrator. All REST routes call ::check_permission().
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Auth {

	const OPTION_KEY         = 'presspilot_api_key';
	const OPTION_SCOPES      = 'presspilot_scopes';       // which capability groups the API key may use
	const OPTION_ENABLED     = 'presspilot_api_enabled';  // master on/off switch (default on)
	const OPTION_ALLOWED_IPS = 'presspilot_allowed_ips';  // optional IP/CIDR allow-list (empty = allow all)
	const LEGACY_OPTION_KEY  = 'eaia_api_key';            // pre-rename option (migrated on load)
	const LEGACY_HEADER      = 'x_eaia_key';               // pre-rename header (still accepted)

	/**
	 * Capability groups the admin can toggle on the settings screen. Each REST
	 * route declares one of these; a request is refused if its group is off.
	 * Default is "everything on" so nothing breaks out of the box.
	 *
	 * The English text here is the machine-readable fallback used by the API
	 * (/scopes); the admin screen renders ::scope_labels() instead, which is
	 * translated into the dashboard language.
	 *
	 * @var array<string,string>
	 */
	const SCOPES = array(
		'content'   => 'Pages & posts (create / edit / delete content)',
		'media'     => 'Media library (upload & list images)',
		'menus'     => 'Navigation menus (create & edit menus)',
		'templates' => 'Templates (Elementor Theme Builder, block themes, patterns)',
		'styles'    => 'Global styles & CSS (Additional CSS, theme.json global styles)',
		'themes'    => 'Themes (install, activate, switch)',
		'plugins'   => 'Plugins (install, activate, deactivate, delete)',
		'settings'  => 'Site settings (options, homepage, permalinks)',
		'config'    => 'Plugin & site configuration (read/write any option, meta & terms; snapshots/restore; discovery; REST proxy; plugin adapters)',
	);

	/**
	 * The same capability groups, described for a human in the dashboard's own
	 * language. Keys are identical to ::SCOPES and are never translated — they
	 * are the wire identifiers agents see.
	 *
	 * @return array<string,string>
	 */
	public static function scope_labels() {
		return array(
			'content'   => __( 'Pages & posts (create / edit / delete content)', 'presspilot' ),
			'media'     => __( 'Media library (upload & list images)', 'presspilot' ),
			'menus'     => __( 'Navigation menus (create & edit menus)', 'presspilot' ),
			'templates' => __( 'Templates (Elementor Theme Builder, block themes, patterns)', 'presspilot' ),
			'styles'    => __( 'Global styles & CSS (Additional CSS, theme.json global styles)', 'presspilot' ),
			'themes'    => __( 'Themes (install, activate, switch)', 'presspilot' ),
			'plugins'   => __( 'Plugins (install, activate, deactivate, delete)', 'presspilot' ),
			'settings'  => __( 'Site settings (options, homepage, permalinks)', 'presspilot' ),
			'config'    => __( 'Plugin & site configuration (read/write any option, meta & terms; snapshots/restore; discovery; REST proxy; plugin adapters)', 'presspilot' ),
		);
	}

	/** @var PP_Auth */
	private static $instance;

	/**
	 * Depth of the current internal dispatch. When > 0 the caller is the plugin
	 * itself re-entering its own REST routes on behalf of an already-authenticated
	 * request (the MCP server, the built-in copilot), so the key and IP checks are
	 * waived — but the master switch and the capability scopes still apply, which
	 * is what actually protects the site.
	 *
	 * @var int
	 */
	private static $internal_depth = 0;

	public static function begin_internal() {
		self::$internal_depth++;
	}

	public static function end_internal() {
		if ( self::$internal_depth > 0 ) {
			self::$internal_depth--;
		}
	}

	public static function is_internal() {
		return self::$internal_depth > 0;
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Create a key on activation if one does not exist yet.
	 */
	public static function maybe_generate_key() {
		$key = get_option( self::OPTION_KEY );
		if ( ! empty( $key ) ) {
			return;
		}
		// Migrate a key from the pre-rename option so the same key keeps working.
		$legacy = get_option( self::LEGACY_OPTION_KEY );
		if ( ! empty( $legacy ) ) {
			update_option( self::OPTION_KEY, $legacy, false );
			return;
		}
		self::regenerate_key();
	}

	/**
	 * Create (or replace) the API key. Returns the new key.
	 *
	 * @return string
	 */
	public static function regenerate_key() {
		$key = 'pp_' . wp_generate_password( 40, false, false );
		update_option( self::OPTION_KEY, $key, false );
		return $key;
	}

	/**
	 * @return string
	 */
	public static function get_key() {
		$key = (string) get_option( self::OPTION_KEY, '' );
		if ( '' === $key ) {
			$key = (string) get_option( self::LEGACY_OPTION_KEY, '' ); // transition fallback
		}
		return $key;
	}

	/**
	 * Extract the presented key from the request.
	 * Accepts either  X-PressPilot-Key: <key>  or  Authorization: Bearer <key>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private static function presented_key( $request ) {
		$header = $request->get_header( 'x_presspilot_key' );
		if ( empty( $header ) ) {
			$header = $request->get_header( self::LEGACY_HEADER ); // transition fallback
		}
		if ( ! empty( $header ) ) {
			return trim( $header );
		}
		$auth = $request->get_header( 'authorization' );
		if ( ! empty( $auth ) && stripos( $auth, 'bearer ' ) === 0 ) {
			return trim( substr( $auth, 7 ) );
		}
		return '';
	}

	/**
	 * REST permission_callback. Constant-time comparison against the stored key,
	 * then an optional per-route capability-group (scope) check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $scope   Capability group this route belongs to.
	 * @return bool|WP_Error
	 */
	public static function check_permission( $request, $scope = '' ) {
		// Master switch: the whole API can be turned off from the admin for safety.
		if ( ! self::is_enabled() ) {
			return PP_Helpers::error( 'pp_api_disabled', 'The PressPilot API is turned off on this site.', 503 );
		}

		// An internal re-entry (MCP tool call, copilot step). The outer request was
		// already authenticated; only the capability check below still applies.
		if ( self::is_internal() ) {
			if ( '' !== $scope && ! self::scope_allowed( $scope ) ) {
				return PP_Helpers::error(
					'pp_scope_disabled',
					sprintf( 'The "%s" capability is turned off for the API on this site. Enable it on the PressPilot settings screen.', $scope ),
					403
				);
			}
			return true;
		}

		$stored    = self::get_key();
		$presented = self::presented_key( $request );

		if ( empty( $stored ) ) {
			return PP_Helpers::error( 'pp_no_key', 'No API key is configured on the site.', 500 );
		}
		if ( empty( $presented ) || ! hash_equals( $stored, $presented ) ) {
			return PP_Helpers::error( 'pp_forbidden', 'Invalid or missing API key.', 401 );
		}
		// Optional IP allow-list (empty = allow all).
		if ( ! self::ip_allowed( self::client_ip() ) ) {
			return PP_Helpers::error( 'pp_ip_blocked', 'Your IP address is not allowed to use this API.', 403 );
		}
		if ( '' !== $scope && ! self::scope_allowed( $scope ) ) {
			return PP_Helpers::error(
				'pp_scope_disabled',
				sprintf( 'The "%s" capability is turned off for the API on this site. Enable it on the PressPilot settings screen.', $scope ),
				403
			);
		}
		return true;
	}

	/**
	 * The stored scope map, normalised to booleans for every known scope.
	 * Anything not explicitly stored defaults to allowed (on).
	 *
	 * @return array<string,bool>
	 */
	public static function get_scopes() {
		$saved = get_option( self::OPTION_SCOPES );
		$out   = array();
		foreach ( array_keys( self::SCOPES ) as $scope ) {
			// Default ON: a scope is only disabled when explicitly set to a falsey value.
			$out[ $scope ] = ! is_array( $saved ) || ! array_key_exists( $scope, $saved )
				? true
				: (bool) $saved[ $scope ];
		}
		return $out;
	}

	/**
	 * Is a capability group currently allowed for the API?
	 *
	 * @param string $scope Scope key.
	 * @return bool
	 */
	public static function scope_allowed( $scope ) {
		if ( ! array_key_exists( $scope, self::SCOPES ) ) {
			return true; // unknown/ungated route.
		}
		$scopes = self::get_scopes();
		return ! empty( $scopes[ $scope ] );
	}

	/* ------------------------------------------------------------------ */
	/* Master switch + IP allow-list (safety)                             */
	/* ------------------------------------------------------------------ */

	/** Is the API enabled? Default ON (only '0' turns it off). */
	public static function is_enabled() {
		return '0' !== (string) get_option( self::OPTION_ENABLED, '1' );
	}

	/** Turn the whole API on/off. */
	public static function set_enabled( $on ) {
		update_option( self::OPTION_ENABLED, $on ? '1' : '0', false );
		return self::is_enabled();
	}

	/** The configured IP/CIDR allow-list, one per line (raw string). */
	public static function get_allowed_ips() {
		return (string) get_option( self::OPTION_ALLOWED_IPS, '' );
	}

	/** Save the allow-list (sanitized: keep IPs, CIDRs; drop junk). */
	public static function save_allowed_ips( $raw ) {
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$ip = explode( '/', $line )[0];
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$out[] = $line;
			}
		}
		$val = implode( "\n", array_unique( $out ) );
		update_option( self::OPTION_ALLOWED_IPS, $val, false );
		return $val;
	}

	/** Best-effort client IP (REMOTE_ADDR — not spoofable like XFF). */
	public static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Is $ip allowed? True when the allow-list is empty (no restriction), or when
	 * $ip matches an entry — exact IPv4/IPv6 or an IPv4 CIDR range.
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	public static function ip_allowed( $ip ) {
		$list = trim( self::get_allowed_ips() );
		if ( '' === $list ) {
			return true; // no restriction configured.
		}
		if ( '' === $ip ) {
			return false;
		}
		foreach ( preg_split( '/\s+/', $list ) as $entry ) {
			$entry = trim( $entry );
			if ( '' === $entry ) {
				continue;
			}
			if ( false === strpos( $entry, '/' ) ) {
				if ( $entry === $ip ) {
					return true;
				}
				continue;
			}
			if ( self::cidr_match( $ip, $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/** IPv4 CIDR match (e.g. 203.0.113.0/24). */
	private static function cidr_match( $ip, $cidr ) {
		list( $subnet, $bits ) = array_pad( explode( '/', $cidr ), 2, '32' );
		$ip_l     = ip2long( $ip );
		$subnet_l = ip2long( $subnet );
		if ( false === $ip_l || false === $subnet_l ) {
			return false; // IPv6 CIDR not supported; exact-match those instead.
		}
		$bits = (int) $bits;
		if ( $bits < 0 || $bits > 32 ) {
			return false;
		}
		$mask = -1 << ( 32 - $bits );
		return ( $ip_l & $mask ) === ( $subnet_l & $mask );
	}

	/**
	 * Persist the scope map from a set of enabled scope keys (from the settings form).
	 *
	 * @param array $enabled List of scope keys that should be ON.
	 * @return array<string,bool>
	 */
	public static function save_scopes( $enabled ) {
		$enabled = is_array( $enabled ) ? array_map( 'sanitize_key', $enabled ) : array();
		$map     = array();
		foreach ( array_keys( self::SCOPES ) as $scope ) {
			$map[ $scope ] = in_array( $scope, $enabled, true );
		}
		update_option( self::OPTION_SCOPES, $map, false );
		return $map;
	}
}
