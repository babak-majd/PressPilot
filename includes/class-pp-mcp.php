<?php
/**
 * Model Context Protocol server — the direct connection for agents.
 *
 * One Streamable HTTP endpoint (POST /wp-json/presspilot/v1/mcp) that speaks
 * JSON-RPC 2.0, so Claude Code, OpenAI Codex, Cursor, Claude Desktop and any
 * other MCP client can drive this WordPress site as a native tool surface —
 * no bespoke prompt, no hand-written HTTP calls.
 *
 * Dual-era. MCP split into two shapes:
 *   - legacy  (2024-11-05 … 2025-11-25): an `initialize` handshake, session-scoped.
 *   - modern  (2026-07-28+): stateless, per-request `_meta`, `server/discover`.
 * Every shipping client today speaks one or the other, so this server answers
 * both on the same endpoint and mirrors the client's era back at it.
 *
 * The Skill is not optional here: it is returned as `instructions` on the
 * handshake, exposed as a resource, and available as the wp_get_skill tool —
 * so an agent that connects through MCP is held to the same operating rules as
 * one that was handed the prompt.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_MCP {

	const OPTION_ENABLED   = 'presspilot_mcp_enabled';    // master switch for the MCP endpoint.
	const OPTION_URL_KEY   = 'presspilot_mcp_url_key';    // allow ?key= auth for clients that only take a URL.

	/** Newest first. The first entry is what we answer with when the client asks for nothing. */
	const SUPPORTED = array( '2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );

	/** Versions that use the modern (stateless, `_meta`-carrying) shape. */
	const MODERN = array( '2026-07-28' );

	/** The version we answer a legacy handshake with when the client asks for something unknown. */
	const LEGACY_DEFAULT = '2025-11-25';

	/* JSON-RPC + MCP error codes. */
	const E_PARSE            = -32700;
	const E_INVALID_REQUEST  = -32600;
	const E_METHOD_NOT_FOUND = -32601;
	const E_INVALID_PARAMS   = -32602;
	const E_INTERNAL         = -32603;
	const E_HEADER_MISMATCH  = -32020;
	const E_UNSUPPORTED_VER  = -32022;

	/** @var PP_MCP */
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/** Is the MCP endpoint turned on? Default ON. */
	public static function is_enabled() {
		return '0' !== (string) get_option( self::OPTION_ENABLED, '1' );
	}

	/** May the API key be presented as a ?key= query parameter? Default OFF. */
	public static function url_key_allowed() {
		return '1' === (string) get_option( self::OPTION_URL_KEY, '0' );
	}

	/** The public MCP endpoint URL. */
	public static function endpoint_url() {
		return rest_url( PP_REST_NS . '/mcp' );
	}

	/* ------------------------------------------------------------------ */
	/* Route                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Register the endpoint. Auth happens inside the handler rather than in a
	 * permission_callback so that failures come back as JSON-RPC errors an MCP
	 * client can actually render, instead of a bare WordPress error body.
	 */
	public function register_routes() {
		register_rest_route(
			PP_REST_NS,
			'/mcp',
			array(
				'methods'             => 'GET, POST, DELETE',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The MCP endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle( $request ) {
		$method = strtoupper( $request->get_method() );

		// Legacy clients open a standalone SSE stream with GET and terminate a
		// session with DELETE. This server is stateless and streams nothing, so
		// both are declined the way the spec prescribes.
		if ( 'POST' !== $method ) {
			return new WP_REST_Response(
				self::error_body( null, self::E_INVALID_REQUEST, 'This MCP endpoint accepts POST only; it is stateless and opens no standalone stream.' ),
				405,
				array( 'Allow' => 'POST' )
			);
		}

		if ( ! self::is_enabled() ) {
			return new WP_REST_Response(
				self::error_body( null, self::E_INVALID_REQUEST, 'The MCP endpoint is turned off on this site.' ),
				503
			);
		}

		$auth = self::authenticate( $request );
		if ( is_wp_error( $auth ) ) {
			// The auth failure path must never itself error, so read the status defensively.
			$data   = $auth->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 401;
			return new WP_REST_Response(
				self::error_body( null, self::E_INVALID_REQUEST, $auth->get_error_message() ),
				$status,
				array( 'WWW-Authenticate' => 'Bearer realm="PressPilot", error="invalid_token"' )
			);
		}

		$raw     = $request->get_body();
		$message = json_decode( $raw, true );
		if ( ! is_array( $message ) ) {
			return new WP_REST_Response( self::error_body( null, self::E_PARSE, 'Request body is not valid JSON.' ), 400 );
		}

		// A JSON-RPC batch (an array of messages) — answered element by element.
		if ( isset( $message[0] ) ) {
			$out = array();
			foreach ( $message as $item ) {
				$result = is_array( $item ) ? $this->dispatch( $item, $request ) : null;
				if ( null !== $result && null !== $result['body'] ) {
					$out[] = $result['body'];
				}
			}
			return new WP_REST_Response( empty( $out ) ? null : $out, empty( $out ) ? 202 : 200 );
		}

		$result = $this->dispatch( $message, $request );
		return new WP_REST_Response( $result['body'], $result['status'] );
	}

	/* ------------------------------------------------------------------ */
	/* Auth                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Accept the API key from `Authorization: Bearer`, `X-PressPilot-Key`, or —
	 * when the admin opted in — a `?key=` query parameter, because several MCP
	 * clients can only be handed a bare URL with no custom headers.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private static function authenticate( $request ) {
		if ( self::url_key_allowed() ) {
			$url_key = (string) $request->get_param( 'key' );
			if ( '' !== $url_key ) {
				$request->set_header( 'X-PressPilot-Key', $url_key );
			}
		}
		return PP_Auth::check_permission( $request );
	}

	/* ------------------------------------------------------------------ */
	/* JSON-RPC dispatch                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Route one JSON-RPC message.
	 *
	 * @param array           $msg     Decoded JSON-RPC message.
	 * @param WP_REST_Request $request The HTTP request (for headers).
	 * @return array{body:mixed,status:int}
	 */
	private function dispatch( $msg, $request ) {
		$id     = isset( $msg['id'] ) ? $msg['id'] : null;
		$method = isset( $msg['method'] ) ? (string) $msg['method'] : '';
		$params = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();

		// Notifications ("initialized", "cancelled", …) get an acknowledgement and no body.
		if ( null === $id ) {
			return array( 'body' => null, 'status' => 202 );
		}

		if ( '' === $method ) {
			return self::rpc_error( $id, self::E_INVALID_REQUEST, 'Missing "method".' );
		}

		// Which era and version is this client speaking?
		$version = self::requested_version( $msg, $params, $request );
		if ( null !== $version && ! in_array( $version, self::SUPPORTED, true ) && 'initialize' !== $method ) {
			return array(
				'body'   => self::error_body(
					$id,
					self::E_UNSUPPORTED_VER,
					'Unsupported protocol version',
					array( 'supported' => array_values( self::SUPPORTED ), 'requested' => $version )
				),
				'status' => 400,
			);
		}

		$modern = null !== $version && in_array( $version, self::MODERN, true );

		// Modern clients mirror body fields into headers; a mismatch means something
		// in the path rewrote one of them, which the spec says to reject outright.
		$mismatch = self::header_mismatch( $msg, $params, $request, $version );
		if ( null !== $mismatch ) {
			return array( 'body' => self::error_body( $id, self::E_HEADER_MISMATCH, $mismatch ), 'status' => 400 );
		}

		switch ( $method ) {
			case 'initialize':
				return self::rpc_result( $id, $this->initialize( $params ) );

			case 'server/discover':
				return self::rpc_result( $id, $this->discover() );

			case 'ping':
				return self::rpc_result( $id, new stdClass() );

			case 'tools/list':
				return self::rpc_result( $id, array( 'tools' => PP_Tools::mcp_definitions() ) );

			case 'tools/call':
				return self::rpc_result( $id, $this->call_tool( $params ) );

			case 'resources/list':
				return self::rpc_result( $id, array( 'resources' => $this->resources() ) );

			case 'resources/templates/list':
				return self::rpc_result( $id, array( 'resourceTemplates' => array() ) );

			case 'resources/read':
				$read = $this->read_resource( isset( $params['uri'] ) ? (string) $params['uri'] : '' );
				if ( is_wp_error( $read ) ) {
					return self::rpc_error( $id, self::E_INVALID_PARAMS, $read->get_error_message() );
				}
				return self::rpc_result( $id, $read );

			case 'prompts/list':
				return self::rpc_result( $id, array( 'prompts' => self::prompt_definitions() ) );

			case 'prompts/get':
				$prompt = $this->get_prompt( $params );
				if ( is_wp_error( $prompt ) ) {
					return self::rpc_error( $id, self::E_INVALID_PARAMS, $prompt->get_error_message() );
				}
				return self::rpc_result( $id, $prompt );
		}

		// Unknown method. Modern transport wants 404 so a dual-era client can tell
		// "this server is modern but lacks the method" from "wrong endpoint".
		return array(
			'body'   => self::error_body( $id, self::E_METHOD_NOT_FOUND, sprintf( 'Unknown method "%s".', $method ) ),
			'status' => $modern ? 404 : 200,
		);
	}

	/**
	 * The protocol version this message claims, from (in order) the modern `_meta`
	 * field, the MCP-Protocol-Version header, or an initialize handshake's params.
	 *
	 * @param array           $msg     Message.
	 * @param array           $params  Params.
	 * @param WP_REST_Request $request Request.
	 * @return string|null
	 */
	private static function requested_version( $msg, $params, $request ) {
		$meta = isset( $params['_meta'] ) && is_array( $params['_meta'] ) ? $params['_meta'] : array();
		if ( ! empty( $meta['io.modelcontextprotocol/protocolVersion'] ) ) {
			return (string) $meta['io.modelcontextprotocol/protocolVersion'];
		}
		if ( isset( $params['protocolVersion'] ) ) {
			return (string) $params['protocolVersion'];
		}
		$header = $request->get_header( 'mcp_protocol_version' );
		if ( ! empty( $header ) ) {
			return trim( $header );
		}
		return null;
	}

	/**
	 * Validate the headers a modern client mirrors from the body. Absent headers
	 * are tolerated (many clients are mid-migration); a header that contradicts
	 * the body is not, since that is exactly the routing/execution split the rule
	 * exists to catch.
	 *
	 * @param array           $msg     Message.
	 * @param array           $params  Params.
	 * @param WP_REST_Request $request Request.
	 * @param string|null     $version Negotiated version.
	 * @return string|null Error message, or null when fine.
	 */
	private static function header_mismatch( $msg, $params, $request, $version ) {
		if ( null === $version || ! in_array( $version, self::MODERN, true ) ) {
			return null;
		}
		$header_version = $request->get_header( 'mcp_protocol_version' );
		if ( ! empty( $header_version ) && trim( $header_version ) !== $version ) {
			return sprintf( 'MCP-Protocol-Version header "%s" does not match the body value "%s".', trim( $header_version ), $version );
		}
		$header_method = $request->get_header( 'mcp_method' );
		if ( ! empty( $header_method ) && $header_method !== $msg['method'] ) {
			return sprintf( 'Mcp-Method header "%s" does not match the body method "%s".', $header_method, $msg['method'] );
		}
		$header_name = $request->get_header( 'mcp_name' );
		if ( ! empty( $header_name ) ) {
			$body_name = isset( $params['name'] ) ? (string) $params['name'] : ( isset( $params['uri'] ) ? (string) $params['uri'] : null );
			if ( null !== $body_name && self::decode_header_value( $header_name ) !== $body_name ) {
				return sprintf( 'Mcp-Name header does not match the body value "%s".', $body_name );
			}
		}
		return null;
	}

	/** Decode the `=?base64?…?=` sentinel a client uses for non-ASCII header values. */
	private static function decode_header_value( $value ) {
		if ( 0 === strpos( $value, '=?base64?' ) && '?=' === substr( $value, -2 ) ) {
			$decoded = base64_decode( substr( $value, 9, -2 ), true );
			if ( false !== $decoded ) {
				return $decoded;
			}
		}
		return $value;
	}

	/* ------------------------------------------------------------------ */
	/* Lifecycle                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Legacy handshake. We answer in the client's own version when we know it,
	 * because a legacy client has no way to fall forward.
	 *
	 * @param array $params Initialize params.
	 * @return array
	 */
	private function initialize( $params ) {
		$asked = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		$agree = in_array( $asked, self::SUPPORTED, true ) && ! in_array( $asked, self::MODERN, true )
			? $asked
			: self::LEGACY_DEFAULT;

		return array(
			'protocolVersion' => $agree,
			'capabilities'    => self::capabilities(),
			'serverInfo'      => self::server_info(),
			'instructions'    => self::instructions(),
		);
	}

	/**
	 * Modern discovery — same identity, but the client picks a version from the list.
	 *
	 * @return array
	 */
	private function discover() {
		return array(
			'resultType'        => 'complete',
			'supportedVersions' => array_values( self::SUPPORTED ),
			'capabilities'      => self::capabilities(),
			'instructions'      => self::instructions(),
			'ttlMs'             => 300000,
			'cacheScope'        => 'private',
			'_meta'             => array( 'io.modelcontextprotocol/serverInfo' => self::server_info() ),
		);
	}

	private static function capabilities() {
		return array(
			'tools'     => array( 'listChanged' => false ),
			'resources' => array( 'subscribe' => false, 'listChanged' => false ),
			'prompts'   => array( 'listChanged' => false ),
		);
	}

	private static function server_info() {
		return array(
			'name'    => 'presspilot',
			'title'   => PP_PRODUCT . ' — ' . get_bloginfo( 'name' ),
			'version' => PP_VERSION,
		);
	}

	/**
	 * The guidance every client shows the model on connect. Short on purpose —
	 * the real rules live in the Skill, and this points hard at it.
	 *
	 * @return string
	 */
	private static function instructions() {
		$theme    = wp_get_theme();
		$disabled = array_keys( array_filter( PP_Auth::get_scopes(), function ( $on ) { return ! $on; } ) );

		$text  = "You are connected to the WordPress site \"" . get_bloginfo( 'name' ) . "\" (" . home_url() . ") through PressPilot v" . PP_VERSION . ".\n";
		$text .= "You can build and manage the whole site: pages and posts as native Gutenberg blocks, block-theme headers/footers and templates, menus, media, global styles, themes, plugins, and plugin/site configuration.\n\n";
		$text .= "RULES\n";
		$text .= "1. Call wp_get_skill FIRST and follow it exactly. It is the operating manual — block authoring, what gets HTML-filtered, header/footer patterns, RTL and multilingual builds, and the decision order for configuring a plugin. Do not build before you have read it.\n";
		$text .= "2. Call wp_get_site once to learn the environment before choosing an approach.\n";
		$text .= "3. Preview destructive configuration writes with dry_run:true and show the user the diff before committing. Option writes create a restore point you can roll back.\n";
		$text .= "4. After a change, fetch the live URL and verify it renders.\n\n";
		$text .= "ENVIRONMENT: WordPress " . get_bloginfo( 'version' ) . ', theme "' . $theme->get( 'Name' ) . '"'
			. ( PP_FSE::is_block_theme() ? ' (block/FSE theme)' : ' (classic theme)' )
			. ', locale ' . get_bloginfo( 'language' ) . ( is_rtl() ? ' (RTL)' : '' ) . ".\n";
		if ( $disabled ) {
			$text .= 'DISABLED CAPABILITIES: ' . implode( ', ', $disabled ) . ". Tools for these are hidden; tell the user to enable them on PressPilot → Permissions rather than working around them.\n";
		}
		return $text;
	}

	/* ------------------------------------------------------------------ */
	/* Tools                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Run a tool. A failed call comes back as a normal result with isError:true —
	 * that is how MCP tells the model "your call failed, try something else"
	 * instead of breaking the client's connection with a protocol error.
	 *
	 * @param array $params tools/call params.
	 * @return array
	 */
	private function call_tool( $params ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		if ( '' === $name ) {
			return array(
				'content' => array( array( 'type' => 'text', 'text' => 'A tool "name" is required.' ) ),
				'isError' => true,
			);
		}

		$result = PP_Tools::call( $name, $args );

		return array(
			'content' => array( array( 'type' => 'text', 'text' => PP_Tools::render_result( $result ) ) ),
			'isError' => ! $result['ok'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* Resources                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Documents a client can attach to the conversation on its own — several
	 * clients preload resources, which is the cheapest way to get the Skill in
	 * front of the model before it starts building.
	 *
	 * @return array[]
	 */
	private function resources() {
		return array(
			array(
				'uri'         => 'presspilot://skill',
				'name'        => 'presspilot-skill',
				'title'       => 'PressPilot Skill — the operating manual',
				'description' => 'The hard rules for building this site correctly: block authoring, HTML filtering limits, header/footer patterns, RTL & multilingual, and the plugin-configuration decision order.',
				'mimeType'    => 'text/markdown',
			),
			array(
				'uri'         => 'presspilot://site',
				'name'        => 'presspilot-site',
				'title'       => 'Site environment',
				'description' => 'WordPress version, active theme, block-theme flag, locale, installed plugins and enabled capability scopes.',
				'mimeType'    => 'application/json',
			),
			array(
				'uri'         => 'presspilot://openapi',
				'name'        => 'presspilot-openapi',
				'title'       => 'OpenAPI 3.0 specification',
				'description' => 'The full REST surface, for anything the tool list does not cover.',
				'mimeType'    => 'application/json',
			),
		);
	}

	/**
	 * @param string $uri Resource URI.
	 * @return array|WP_Error
	 */
	private function read_resource( $uri ) {
		switch ( $uri ) {
			case 'presspilot://skill':
				$path = PP_PATH . 'docs/SKILL.md';
				return self::resource_contents( $uri, 'text/markdown', is_readable( $path ) ? file_get_contents( $path ) : '' );

			case 'presspilot://site':
				$site = PP_Tools::dispatch( 'GET', '/site' );
				return self::resource_contents( $uri, 'application/json', wp_json_encode( $site['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

			case 'presspilot://openapi':
				$path = PP_PATH . 'docs/openapi.json';
				return self::resource_contents( $uri, 'application/json', is_readable( $path ) ? file_get_contents( $path ) : '{}' );
		}
		return PP_Helpers::error( 'pp_unknown_resource', sprintf( 'No resource at "%s".', $uri ), 404 );
	}

	private static function resource_contents( $uri, $mime, $text ) {
		return array(
			'contents' => array(
				array( 'uri' => $uri, 'mimeType' => $mime, 'text' => (string) $text ),
			),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Prompts                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Slash-command style starting points. A user picking one of these in their
	 * client gets a brief that already carries the site's own rules.
	 *
	 * @return array[]
	 */
	private static function prompt_definitions() {
		return array(
			array(
				'name'        => 'build_site',
				'title'       => 'Build or extend this site',
				'description' => 'Plan and build pages with native Gutenberg blocks, following the PressPilot Skill.',
				'arguments'   => array(
					array( 'name' => 'brief', 'description' => 'What to build — pages, sections, tone, brand.', 'required' => true ),
				),
			),
			array(
				'name'        => 'migrate_to_gutenberg',
				'title'       => 'Migrate Elementor → Gutenberg',
				'description' => 'Convert Elementor content to native blocks in place, then verify and remove Elementor.',
				'arguments'   => array(
					array( 'name' => 'scope', 'description' => 'Which pages to migrate. Defaults to everything.', 'required' => false ),
				),
			),
			array(
				'name'        => 'configure_plugin',
				'title'       => 'Configure a plugin',
				'description' => 'Discover a plugin\'s settings surface and change it safely, with a dry run and a restore point.',
				'arguments'   => array(
					array( 'name' => 'plugin', 'description' => 'Plugin name or slug.', 'required' => true ),
					array( 'name' => 'goal', 'description' => 'What the settings should end up being.', 'required' => true ),
				),
			),
			array(
				'name'        => 'audit_site',
				'title'       => 'Audit this site',
				'description' => 'Review performance, page weight and configuration, and report what to fix first.',
				'arguments'   => array(),
			),
		);
	}

	/**
	 * @param array $params prompts/get params.
	 * @return array|WP_Error
	 */
	private function get_prompt( $params ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		$arg  = function ( $key, $default = '' ) use ( $args ) {
			return isset( $args[ $key ] ) && '' !== $args[ $key ] ? (string) $args[ $key ] : $default;
		};

		$preamble = "Start by calling wp_get_skill and wp_get_site, and follow the Skill exactly.\n\n";

		switch ( $name ) {
			case 'build_site':
				$text = $preamble . "Build this on the connected WordPress site using native Gutenberg blocks (no page-builder dependency). Decompose the design into real blocks rather than one core/html block, host fonts and images as uploaded files instead of inlining base64, and put shared CSS in the global stylesheet once. Verify each page renders at its live URL when you are done.\n\nBrief:\n" . $arg( 'brief' );
				break;

			case 'migrate_to_gutenberg':
				$text = $preamble . "Migrate this site from Elementor to native Gutenberg blocks. Convert each page in place with wp_update_content and builder:\"gutenberg\" so URLs and menus do not change, rebuild the header/footer as block-theme template parts, then work wp_migration_status's next_steps until fully_migrated is true and Elementor is deactivated.\n\nScope: " . $arg( 'scope', 'the whole site' );
				break;

			case 'configure_plugin':
				$text = $preamble . "Configure the \"" . $arg( 'plugin' ) . "\" plugin on this site.\n\nGoal: " . $arg( 'goal' ) . "\n\nFollow the decision order in the Skill: an adapter if one exists, then the plugin's own REST API through wp_proxy_rest, then options/meta/terms (use wp_config_discover and the snapshot/diff trick to find the exact keys), then the database tools, then admin-ajax. Take a snapshot first, preview every write with dry_run:true and show me the diff before committing, and tell me the restore_id afterwards.";
				break;

			case 'audit_site':
				$text = $preamble . "Audit this site and report findings ordered by impact. Check wp_performance for server and page-weight problems (TTFB, caching, gzip, autoloaded-option bloat), review the largest pages for inlined base64 or duplicated CSS, check whether the front page, permalinks and menus are set sensibly, and check wp_migration_status if Elementor is present. Recommend fixes; do not change anything without asking.";
				break;

			default:
				return PP_Helpers::error( 'pp_unknown_prompt', sprintf( 'No prompt named "%s".', $name ), 404 );
		}

		return array(
			'description' => sprintf( 'PressPilot: %s', $name ),
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array( 'type' => 'text', 'text' => $text ),
				),
			),
		);
	}

	/* ------------------------------------------------------------------ */
	/* JSON-RPC envelopes                                                 */
	/* ------------------------------------------------------------------ */

	private static function rpc_result( $id, $result ) {
		return array(
			'body'   => array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ),
			'status' => 200,
		);
	}

	private static function rpc_error( $id, $code, $message, $data = null ) {
		return array( 'body' => self::error_body( $id, $code, $message, $data ), 'status' => 200 );
	}

	private static function error_body( $id, $code, $message, $data = null ) {
		$error = array( 'code' => $code, 'message' => $message );
		if ( null !== $data ) {
			$error['data'] = $data;
		}
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => $error );
	}

	/* ------------------------------------------------------------------ */
	/* Client configuration snippets (admin UI)                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Ready-to-paste connection setup for the agents people actually use.
	 *
	 * The code samples are literal client configuration and never translated;
	 * the notes around them are read by a person, so they follow the dashboard
	 * language.
	 *
	 * @param string $key API key.
	 * @return array<string,array{label:string,lang:string,code:string,note:string}>
	 */
	public static function client_snippets( $key ) {
		$url     = self::endpoint_url();
		$url_key = self::url_key_allowed() ? add_query_arg( 'key', $key, $url ) : $url;

		return array(
			'claude-code' => array(
				'label' => 'Claude Code',
				'lang'  => 'bash',
				'note'  => __( 'Run this once in your terminal, then start Claude Code and ask it to build. Add --scope user to make it available in every project.', 'presspilot' ),
				'code'  => "claude mcp add --transport http presspilot \\\n  " . $url . " \\\n  --header \"Authorization: Bearer " . $key . '"',
			),
			'codex'       => array(
				'label' => 'OpenAI Codex',
				'lang'  => 'toml',
				'note'  => __( 'Add this to ~/.codex/config.toml (a url key means a remote Streamable HTTP server). Export PRESSPILOT_KEY in your shell so the token stays out of the file.', 'presspilot' ),
				'code'  => "[mcp_servers.presspilot]\nurl = \"" . $url . "\"\nbearer_token_env_var = \"PRESSPILOT_KEY\"\n\n# then, in your shell:\n#   export PRESSPILOT_KEY=\"" . $key . '"',
			),
			'cursor'      => array(
				'label' => 'Cursor / Windsurf / VS Code',
				'lang'  => 'json',
				'note'  => __( 'Add to .cursor/mcp.json in your project, or to the editor\'s global MCP settings.', 'presspilot' ),
				'code'  => wp_json_encode(
					array(
						'mcpServers' => array(
							'presspilot' => array(
								'url'     => $url,
								'headers' => array( 'Authorization' => 'Bearer ' . $key ),
							),
						),
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				),
			),
			'url-only'    => array(
				'label' => __( 'Any client (URL only)', 'presspilot' ),
				'lang'  => 'text',
				'note'  => self::url_key_allowed()
					? __( 'For clients that accept only a URL and no custom headers. The key travels in the URL, so it can end up in server and proxy logs — prefer a header where you can.', 'presspilot' )
					: __( 'Turn on "Key in the URL" below to use this form. It suits clients that accept only a URL and no custom headers.', 'presspilot' ),
				'code'  => $url_key,
			),
		);
	}
}
