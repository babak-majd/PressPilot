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
	const OPTION_TOKENS      = 'presspilot_tokens';       // additional named tokens (multi-token support)
	const LEGACY_OPTION_KEY  = 'eaia_api_key';            // pre-rename option (migrated on load)
	const LEGACY_HEADER      = 'x_eaia_key';               // pre-rename header (still accepted)

	/** Only update a token's last-used stamp at most once per this many seconds. */
	const LAST_USED_THROTTLE = 60;

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
		'plugins'   => 'Plugins (install from the WordPress.org repo, activate, deactivate, delete)',
		'settings'  => 'Site settings (options, homepage, permalinks)',
		'config'    => 'Plugin & site configuration (read/write options, meta & terms; snapshots & restore points)',
		'database'  => 'Database reads (structured, read-only queries of plugin & core tables)',
		'files'     => 'Files (read-only listing & reading of files under allow-listed roots)',
		'security'  => 'Security & maintenance (read-only scans; vetted, reversible hardening & optimization)',
		'discovery' => 'Discovery (enumerate settings, REST routes & a plugin\'s option keys)',
		'adapters'  => 'Plugin adapters (run a curated, vetted action on a supported plugin)',
		'cache'     => 'Caching (detect cache plugins, purge caches, automatic purge after writes)',
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
			'plugins'   => __( 'Plugins (install from the WordPress.org repo, activate, deactivate, delete)', 'presspilot' ),
			'settings'  => __( 'Site settings (options, homepage, permalinks)', 'presspilot' ),
			'config'    => __( 'Plugin & site configuration (read/write options, meta & terms; snapshots & restore points)', 'presspilot' ),
			'database'  => __( 'Database reads (structured, read-only queries of plugin & core tables)', 'presspilot' ),
			'files'     => __( 'Files (read-only listing & reading of files under allow-listed roots)', 'presspilot' ),
			'security'  => __( 'Security & maintenance (read-only scans; vetted, reversible hardening & optimization)', 'presspilot' ),
			'discovery' => __( 'Discovery (enumerate settings, REST routes & a plugin\'s option keys)', 'presspilot' ),
			'adapters'  => __( 'Plugin adapters (run a curated, vetted action on a supported plugin)', 'presspilot' ),
			'cache'     => __( 'Caching (detect cache plugins, purge caches, automatic purge after writes)', 'presspilot' ),
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

		$presented = self::presented_key( $request );
		if ( empty( $presented ) ) {
			return PP_Helpers::error( 'pp_forbidden', 'Invalid or missing API key.', 401 );
		}

		// Match the presented key against the primary key and every active token,
		// in constant time (every candidate is compared, no early return).
		$match = self::match_credential( $presented );
		if ( null === $match ) {
			return PP_Helpers::error( 'pp_forbidden', 'Invalid or missing API key.', 401 );
		}

		// IP allow-list: a token may carry its own; otherwise the global one applies.
		$ip_list = ( isset( $match['ips'] ) && '' !== $match['ips'] ) ? $match['ips'] : self::get_allowed_ips();
		if ( ! self::ip_allowed_against( self::client_ip(), $ip_list ) ) {
			return PP_Helpers::error( 'pp_ip_blocked', 'Your IP address is not allowed to use this API.', 403 );
		}

		// Scope: the capability must be on globally AND granted to this token.
		if ( '' !== $scope ) {
			if ( ! self::scope_allowed( $scope ) ) {
				return PP_Helpers::error(
					'pp_scope_disabled',
					sprintf( 'The "%s" capability is turned off for the API on this site. Enable it on the PressPilot settings screen.', $scope ),
					403
				);
			}
			if ( is_array( $match['scopes'] ) && ! in_array( $scope, $match['scopes'], true ) ) {
				return PP_Helpers::error(
					'pp_token_scope',
					sprintf( 'This token is not permitted to use the "%s" capability.', $scope ),
					403
				);
			}
		}

		// Record use (throttled) so the admin can see which tokens are live.
		if ( ! empty( $match['id'] ) && 'primary' !== $match['id'] ) {
			self::touch_token( $match['id'] );
		}
		return true;
	}

	/**
	 * Find the credential whose secret matches the presented key, comparing every
	 * candidate (constant-time: no short-circuit reveals which one matched).
	 *
	 * Candidates: the primary key (full access, id "primary") plus every enabled
	 * named token. A disabled or revoked token never matches.
	 *
	 * @param string $presented Presented key.
	 * @return array|null The matched credential record, or null.
	 */
	private static function match_credential( $presented ) {
		$match = null;
		foreach ( self::credentials() as $cred ) {
			if ( '' !== (string) $cred['token'] && hash_equals( (string) $cred['token'], $presented ) ) {
				$match = $cred;
			}
		}
		return $match;
	}

	/**
	 * All credentials that may authenticate: the primary key as a synthetic record
	 * with full access, then every enabled named token.
	 *
	 * @return array[]
	 */
	private static function credentials() {
		$creds = array();
		$primary = self::get_key();
		if ( '' !== $primary ) {
			$creds[] = array( 'id' => 'primary', 'label' => 'Primary key', 'token' => $primary, 'scopes' => null, 'ips' => '' );
		}
		foreach ( self::get_tokens() as $tok ) {
			if ( ! empty( $tok['enabled'] ) ) {
				$creds[] = $tok;
			}
		}
		return $creds;
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
		$val = self::normalize_ips( $raw );
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
		return self::ip_allowed_against( $ip, self::get_allowed_ips() );
	}

	/**
	 * Is $ip allowed by a specific allow-list string (empty = no restriction)?
	 *
	 * @param string $ip   Client IP.
	 * @param string $list Raw allow-list (IPs/CIDRs, any whitespace/newline/comma separated).
	 * @return bool
	 */
	public static function ip_allowed_against( $ip, $list ) {
		$list = trim( (string) $list );
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

	/* ------------------------------------------------------------------ */
	/* Multiple named tokens                                              */
	/*                                                                    */
	/* Each token is a separate credential with its own label, optional   */
	/* capability subset (least privilege), optional IP allow-list, and   */
	/* on/off state. The primary key keeps full access. Tokens are        */
	/* managed only from wp-admin (manage_options) — never through the    */
	/* API itself, so a key can never mint or widen another key.          */
	/* ------------------------------------------------------------------ */

	/**
	 * All named tokens, normalised. Keyed by token id.
	 *
	 * @return array<string,array>
	 */
	public static function get_tokens() {
		$raw = get_option( self::OPTION_TOKENS, array() );
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $rec ) {
				$rec = self::normalize_token( $rec );
				if ( $rec ) {
					$out[ $rec['id'] ] = $rec;
				}
			}
		}
		return $out;
	}

	/** Coerce a stored record into the canonical token shape (or null if unusable). */
	private static function normalize_token( $rec ) {
		if ( ! is_array( $rec ) || empty( $rec['id'] ) || empty( $rec['token'] ) ) {
			return null;
		}
		$scopes = null;
		if ( isset( $rec['scopes'] ) && is_array( $rec['scopes'] ) ) {
			// Keep only known scope keys; an empty array means "no capabilities".
			$scopes = array_values( array_intersect( array_map( 'sanitize_key', $rec['scopes'] ), array_keys( self::SCOPES ) ) );
		}
		return array(
			'id'        => (string) $rec['id'],
			'label'     => isset( $rec['label'] ) ? (string) $rec['label'] : '',
			'token'     => (string) $rec['token'],
			'scopes'    => $scopes, // null = inherit all currently-granted global scopes.
			'ips'       => isset( $rec['ips'] ) ? (string) $rec['ips'] : '',
			'enabled'   => ! isset( $rec['enabled'] ) || (bool) $rec['enabled'],
			'created'   => isset( $rec['created'] ) ? (string) $rec['created'] : '',
			'last_used' => isset( $rec['last_used'] ) ? (string) $rec['last_used'] : '',
		);
	}

	/** Persist the token map. */
	private static function save_tokens( $tokens ) {
		update_option( self::OPTION_TOKENS, array_values( $tokens ), false );
	}

	/**
	 * Create a new named token.
	 *
	 * @param string     $label  Human label.
	 * @param array|null $scopes Capability subset (null = inherit all granted scopes).
	 * @param string     $ips    Optional per-token IP/CIDR allow-list (raw).
	 * @return array The new token record (includes the full secret — show it once).
	 */
	public static function create_token( $label, $scopes = null, $ips = '' ) {
		$tokens = self::get_tokens();
		$rec    = self::normalize_token( array(
			'id'      => 'tok_' . wp_generate_password( 12, false, false ),
			'label'   => sanitize_text_field( $label ),
			'token'   => 'pp_' . wp_generate_password( 40, false, false ),
			'scopes'  => is_array( $scopes ) ? $scopes : null,
			'ips'     => self::normalize_ips( $ips ),
			'enabled' => true,
			'created' => current_time( 'mysql' ),
		) );
		$tokens[ $rec['id'] ] = $rec;
		self::save_tokens( $tokens );
		return $rec;
	}

	/**
	 * Update a token's label, scopes, IPs or enabled state.
	 *
	 * @param string $id     Token id.
	 * @param array  $fields { label, scopes(array|null), ips, enabled }.
	 * @return array|null The updated record, or null if not found.
	 */
	public static function update_token( $id, $fields ) {
		$tokens = self::get_tokens();
		if ( empty( $tokens[ $id ] ) ) {
			return null;
		}
		$rec = $tokens[ $id ];
		if ( array_key_exists( 'label', $fields ) ) {
			$rec['label'] = sanitize_text_field( $fields['label'] );
		}
		if ( array_key_exists( 'scopes', $fields ) ) {
			$rec['scopes'] = is_array( $fields['scopes'] ) ? $fields['scopes'] : null;
		}
		if ( array_key_exists( 'ips', $fields ) ) {
			$rec['ips'] = self::normalize_ips( $fields['ips'] );
		}
		if ( array_key_exists( 'enabled', $fields ) ) {
			$rec['enabled'] = (bool) $fields['enabled'];
		}
		$rec            = self::normalize_token( $rec );
		$tokens[ $id ]  = $rec;
		self::save_tokens( $tokens );
		return $rec;
	}

	/** Enable or disable a token. */
	public static function set_token_enabled( $id, $on ) {
		return self::update_token( $id, array( 'enabled' => (bool) $on ) );
	}

	/** Permanently revoke (delete) a token. */
	public static function revoke_token( $id ) {
		$tokens = self::get_tokens();
		if ( empty( $tokens[ $id ] ) ) {
			return false;
		}
		unset( $tokens[ $id ] );
		self::save_tokens( $tokens );
		return true;
	}

	/** Record a token's last use, at most once per LAST_USED_THROTTLE seconds. */
	private static function touch_token( $id ) {
		$tokens = self::get_tokens();
		if ( empty( $tokens[ $id ] ) ) {
			return;
		}
		$last = $tokens[ $id ]['last_used'];
		if ( $last && ( time() - strtotime( $last ) ) < self::LAST_USED_THROTTLE ) {
			return;
		}
		$tokens[ $id ]['last_used'] = current_time( 'mysql' );
		self::save_tokens( $tokens );
	}

	/** Normalise a raw IP/CIDR allow-list to one entry per line, junk dropped. */
	public static function normalize_ips( $raw ) {
		$out = array();
		foreach ( preg_split( '/[\r\n,\s]+/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$ip = explode( '/', $line )[0];
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$out[] = $line;
			}
		}
		return implode( "\n", array_unique( $out ) );
	}
}
