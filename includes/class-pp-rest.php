<?php
/**
 * REST API routes under /wp-json/presspilot/v1/.
 *
 * Every route is protected by PP_Auth::check_permission (API key).
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_REST {

	/** @var PP_REST */
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Shared args for the auth callback.
	 *
	 * @param string $methods  HTTP methods.
	 * @param string $callback Method name on this class.
	 * @param string $scope    Capability group gating the route ('' = ungated).
	 * @param array  $args     Extra args.
	 * @return array
	 */
	private function route( $methods, $callback, $scope = '', $args = array() ) {
		return array(
			'methods'             => $methods,
			'callback'            => array( $this, $callback ),
			'permission_callback' => function ( $request ) use ( $scope ) {
				return PP_Auth::check_permission( $request, $scope );
			},
			'args'                => $args,
		);
	}

	public function register_routes() {
		$ns = PP_REST_NS;

		register_rest_route( $ns, '/ping', $this->route( 'GET', 'ping' ) );
		register_rest_route( $ns, '/site', $this->route( 'GET', 'get_site' ) );
		register_rest_route( $ns, '/scopes', $this->route( 'GET', 'get_scopes' ) );
		register_rest_route( $ns, '/migration-status', $this->route( 'GET', 'migration_status' ) );
		register_rest_route( $ns, '/performance', $this->route( 'GET', 'performance' ) );

		// Content listing & CRUD.
		register_rest_route( $ns, '/content', $this->route( 'GET', 'list_content', 'content' ) );
		register_rest_route( $ns, '/content', $this->route( 'POST', 'create_content', 'content' ) );
		register_rest_route( $ns, '/content/(?P<id>\d+)', $this->route( 'GET', 'get_content', 'content' ) );
		register_rest_route( $ns, '/content/(?P<id>\d+)', $this->route( 'PUT,PATCH', 'update_content', 'content' ) );
		register_rest_route( $ns, '/content/(?P<id>\d+)', $this->route( 'DELETE', 'delete_content', 'content' ) );

		// Elementor discovery.
		register_rest_route( $ns, '/widgets', $this->route( 'GET', 'list_widgets' ) );
		register_rest_route( $ns, '/widgets/(?P<name>[a-z0-9\-\_]+)', $this->route( 'GET', 'get_widget' ) );
		register_rest_route( $ns, '/globals', $this->route( 'GET', 'get_globals' ) );
		register_rest_route( $ns, '/templates', $this->route( 'GET', 'list_templates' ) );

		// Gutenberg / block discovery.
		register_rest_route( $ns, '/blocks', $this->route( 'GET', 'list_blocks' ) );
		register_rest_route( $ns, '/patterns', $this->route( 'GET', 'list_patterns' ) );
		register_rest_route( $ns, '/patterns', $this->route( 'POST', 'create_pattern', 'templates' ) );

		// Media & assets (fonts/CSS/SVG as real cacheable files).
		register_rest_route( $ns, '/media', $this->route( 'GET', 'list_media', 'media' ) );
		register_rest_route( $ns, '/media', $this->route( 'POST', 'upload_media', 'media' ) );
		register_rest_route( $ns, '/assets', $this->route( 'GET', 'list_assets', 'media' ) );
		register_rest_route( $ns, '/assets/upload', $this->route( 'POST', 'upload_asset', 'media' ) );

		// Batch (run many sub-requests in one call) & Elementor-meta cleanup.
		register_rest_route( $ns, '/batch', $this->route( 'POST', 'batch' ) );
		register_rest_route( $ns, '/cleanup/elementor-meta', $this->route( 'POST', 'cleanup_elementor_meta', 'content' ) );

		// Native form handler (submit is public; config is key-gated).
		register_rest_route( $ns, '/forms/submit', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'form_submit' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/forms/config', $this->route( 'GET', 'get_form_config', 'settings' ) );
		register_rest_route( $ns, '/forms/config', $this->route( 'POST', 'set_form_config', 'settings' ) );

		// Taxonomies / menus.
		register_rest_route( $ns, '/taxonomies', $this->route( 'GET', 'list_taxonomies' ) );
		register_rest_route( $ns, '/menus', $this->route( 'GET', 'list_menus', 'menus' ) );
		register_rest_route( $ns, '/menus', $this->route( 'POST', 'create_menu', 'menus' ) );
		register_rest_route( $ns, '/menus/(?P<id>\d+)', $this->route( 'GET', 'get_menu', 'menus' ) );
		register_rest_route( $ns, '/menus/(?P<id>\d+)', $this->route( 'PUT,PATCH', 'update_menu', 'menus' ) );
		register_rest_route( $ns, '/menus/(?P<id>\d+)', $this->route( 'DELETE', 'delete_menu', 'menus' ) );
		register_rest_route( $ns, '/menu-locations', $this->route( 'GET', 'list_menu_locations', 'menus' ) );

		// Site: themes & homepage.
		register_rest_route( $ns, '/themes', $this->route( 'GET', 'list_themes', 'themes' ) );
		register_rest_route( $ns, '/themes/activate', $this->route( 'POST', 'activate_theme', 'themes' ) );
		register_rest_route( $ns, '/homepage', $this->route( 'GET', 'get_homepage', 'settings' ) );
		register_rest_route( $ns, '/homepage', $this->route( 'POST', 'set_homepage', 'settings' ) );

		// Plugins.
		register_rest_route( $ns, '/plugins', $this->route( 'GET', 'list_plugins', 'plugins' ) );
		register_rest_route( $ns, '/plugins/install', $this->route( 'POST', 'install_plugin', 'plugins' ) );
		register_rest_route( $ns, '/plugins/(?P<action>activate|deactivate|delete)', $this->route( 'POST', 'manage_plugin', 'plugins' ) );

		// Block-theme (FSE) templates, template parts & global styles.
		register_rest_route( $ns, '/fse-templates', $this->route( 'GET', 'list_fse_templates', 'templates' ) );
		register_rest_route( $ns, '/fse-templates', $this->route( 'POST', 'upsert_fse_template', 'templates' ) );
		register_rest_route( $ns, '/fse-template-parts', $this->route( 'GET', 'list_fse_parts', 'templates' ) );
		register_rest_route( $ns, '/fse-template-parts', $this->route( 'POST', 'upsert_fse_part', 'templates' ) );
		register_rest_route( $ns, '/global-styles', $this->route( 'GET', 'get_global_styles', 'styles' ) );
		register_rest_route( $ns, '/global-styles', $this->route( 'POST', 'set_global_styles', 'styles' ) );

		// Site options, Theme Builder parts, Additional CSS.
		register_rest_route( $ns, '/settings/options', $this->route( 'POST', 'set_options', 'settings' ) );
		register_rest_route( $ns, '/settings/custom-css', $this->route( 'GET', 'get_custom_css', 'styles' ) );
		register_rest_route( $ns, '/settings/custom-css', $this->route( 'POST', 'set_custom_css', 'styles' ) );
		register_rest_route( $ns, '/template', $this->route( 'POST', 'upsert_template', 'templates' ) );

		// Configuration assistant (generic options/meta/terms, snapshots, discovery, REST proxy, adapters).
		register_rest_route( $ns, '/options', $this->route( 'GET', 'config_get_options', 'config' ) );
		register_rest_route( $ns, '/options', $this->route( 'POST', 'config_set_options', 'config' ) );
		register_rest_route( $ns, '/meta', $this->route( 'GET', 'config_get_meta', 'config' ) );
		register_rest_route( $ns, '/meta', $this->route( 'POST', 'config_set_meta', 'config' ) );
		register_rest_route( $ns, '/terms', $this->route( 'POST', 'config_create_term', 'config' ) );
		register_rest_route( $ns, '/terms/assign', $this->route( 'POST', 'config_assign_terms', 'config' ) );
		register_rest_route( $ns, '/config/snapshot', $this->route( 'GET', 'config_snapshots', 'config' ) );
		register_rest_route( $ns, '/config/snapshot', $this->route( 'POST', 'config_snapshot', 'config' ) );
		register_rest_route( $ns, '/config/diff', $this->route( 'GET', 'config_diff', 'config' ) );
		register_rest_route( $ns, '/config/restore', $this->route( 'POST', 'config_restore', 'config' ) );
		register_rest_route( $ns, '/registered-settings', $this->route( 'GET', 'config_registered_settings', 'config' ) );
		register_rest_route( $ns, '/rest-routes', $this->route( 'GET', 'config_rest_routes', 'config' ) );
		register_rest_route( $ns, '/discover', $this->route( 'GET', 'config_discover', 'config' ) );
		register_rest_route( $ns, '/proxy', $this->route( 'POST', 'config_proxy', 'config' ) );
		register_rest_route( $ns, '/adapters', $this->route( 'GET', 'adapters_list', 'config' ) );
		register_rest_route( $ns, '/adapters/(?P<slug>[a-z0-9\-]+)/(?P<action>[a-z0-9\-_]+)', $this->route( 'POST', 'adapters_run', 'config' ) );

		// Reach into any plugin: custom DB tables, admin-ajax handlers, and an opt-in code hatch.
		register_rest_route( $ns, '/db/tables', $this->route( 'GET', 'db_tables', 'config' ) );
		register_rest_route( $ns, '/db/describe', $this->route( 'GET', 'db_describe', 'config' ) );
		register_rest_route( $ns, '/db/select', $this->route( 'POST', 'db_select', 'config' ) );
		register_rest_route( $ns, '/db/write', $this->route( 'POST', 'db_write', 'config' ) );
		register_rest_route( $ns, '/admin-ajax', $this->route( 'POST', 'config_admin_ajax', 'config' ) );
		register_rest_route( $ns, '/exec', $this->route( 'POST', 'config_exec', 'config' ) );

		// Model Context Protocol — the direct agent connection (Claude Code, Codex, …).
		// Auth is handled inside the handler so failures come back as JSON-RPC.
		PP_MCP::instance()->register_routes();

		// The tool registry, and the built-in copilot that drives it.
		register_rest_route( $ns, '/tools', $this->route( 'GET', 'list_tools' ) );
		register_rest_route( $ns, '/tools/call', $this->route( 'POST', 'call_tool' ) );
		register_rest_route( $ns, '/agent/config', $this->route( 'GET', 'agent_get_config' ) );
		register_rest_route( $ns, '/agent/config', $this->route( 'POST', 'agent_set_config' ) );
		register_rest_route( $ns, '/agent/models', $this->route( 'GET', 'agent_models' ) );
		register_rest_route( $ns, '/agent/step', $this->route( 'POST', 'agent_step' ) );
		register_rest_route( $ns, '/agent/run', $this->route( 'POST', 'agent_run' ) );

		// Public: the agent Skill / API documentation (no key needed for discovery).
		register_rest_route( $ns, '/skill', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'skill_doc' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/openapi', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'openapi' ),
			'permission_callback' => '__return_true',
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Tools & the built-in copilot                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * The tool surface as this site currently offers it — the same list MCP
	 * clients see, useful for debugging what a disabled scope hid.
	 */
	public function list_tools() {
		return rest_ensure_response(
			array(
				'profile'  => PP_Tools::profile(),
				'count'    => count( PP_Tools::available() ),
				'mcp_url'  => PP_MCP::endpoint_url(),
				'mcp_on'   => PP_MCP::is_enabled(),
				'tools'    => PP_Tools::mcp_definitions(),
			)
		);
	}

	/** Run one tool by name — the same dispatch MCP and the copilot use. */
	public function call_tool( $request ) {
		$name = (string) $request->get_param( 'name' );
		if ( '' === $name ) {
			return PP_Helpers::error( 'pp_missing_tool_name', 'A tool "name" is required.', 400 );
		}
		$args   = $request->get_param( 'arguments' );
		$result = PP_Tools::call( $name, is_array( $args ) ? $args : array() );
		return new WP_REST_Response( $result, $result['ok'] ? 200 : $result['status'] );
	}

	public function agent_get_config() {
		return rest_ensure_response(
			array(
				'config'    => PP_Providers::public_config(),
				'providers' => PP_Providers::providers(),
				'tools'     => count( PP_Tools::available() ),
			)
		);
	}

	public function agent_set_config( $request ) {
		PP_Providers::save_config( $request->get_params() );
		return rest_ensure_response( array( 'config' => PP_Providers::public_config() ) );
	}

	public function agent_models() {
		$models = PP_Providers::list_models();
		if ( is_wp_error( $models ) ) {
			return $models;
		}
		return rest_ensure_response( array( 'models' => $models ) );
	}

	/**
	 * One copilot round trip. The caller keeps the conversation and loops, which
	 * keeps any single request short enough for shared hosting.
	 */
	public function agent_step( $request ) {
		$messages = $request->get_param( 'messages' );
		$messages = is_array( $messages ) ? $messages : array();
		$prompt   = (string) $request->get_param( 'prompt' );

		if ( '' !== $prompt ) {
			$messages[] = PP_Providers::user_message( $prompt );
		}
		if ( empty( $messages ) ) {
			return PP_Helpers::error( 'pp_no_prompt', 'Send a "prompt" or a "messages" array.', 400 );
		}

		$step = PP_Agent::step( $messages );
		if ( is_wp_error( $step ) ) {
			return $step;
		}
		return rest_ensure_response( $step );
	}

	/** Run the copilot to completion server-side (headless callers). */
	public function agent_run( $request ) {
		$result = PP_Agent::run(
			(string) $request->get_param( 'prompt' ),
			$request->get_param( 'messages' ),
			(int) $request->get_param( 'max_steps' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public function openapi() {
		$path = PP_PATH . 'docs/openapi.json';
		$spec = is_readable( $path ) ? json_decode( file_get_contents( $path ), true ) : array();
		if ( is_array( $spec ) ) {
			$spec['info']['version'] = PP_VERSION;
			$spec['servers']         = array( array( 'url' => rest_url( PP_REST_NS ), 'description' => 'This site' ) );
		}
		return rest_ensure_response( $spec );
	}

	public function skill_doc( $request ) {
		$path = PP_PATH . 'docs/SKILL.md';
		$md   = is_readable( $path ) ? file_get_contents( $path ) : '';
		if ( 'markdown' === $request->get_param( 'format' ) ) {
			return new WP_REST_Response( $md, 200, array( 'Content-Type' => 'text/markdown; charset=utf-8' ) );
		}
		return rest_ensure_response( array(
			'plugin'   => 'presspilot',
			'version'  => PP_VERSION,
			'markdown' => $md,
		) );
	}

	public function set_options( $request ) {
		return rest_ensure_response( PP_Site::set_options( $request->get_param( 'options' ) ) );
	}

	public function upsert_template( $request ) {
		return rest_ensure_response( PP_Site::upsert_template( array(
			'slug'       => $request->get_param( 'slug' ),
			'title'      => $request->get_param( 'title' ),
			'type'       => $request->get_param( 'template_type' ),
			'elements'   => $request->get_param( 'elementor_data' ),
			'conditions' => $request->get_param( 'conditions' ),
			'settings'   => $request->get_param( 'elementor_settings' ),
		) ) );
	}


	/* ------------------------------------------------------------------ */
	/* Diagnostics                                                        */
	/* ------------------------------------------------------------------ */

	public function ping() {
		return rest_ensure_response(
			array(
				'ok'      => true,
				'plugin'  => 'presspilot',
				'version' => PP_VERSION,
				'time'    => current_time( 'mysql' ),
			)
		);
	}

	public function get_site() {
		$plugins = array();
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = array(
				'name'   => $data['Name'],
				'version'=> $data['Version'],
				'active' => is_plugin_active( $file ),
			);
		}

		$theme = wp_get_theme();

		return rest_ensure_response(
			array(
				'site_url'            => site_url(),
				'home_url'            => home_url(),
				'wp_version'          => get_bloginfo( 'version' ),
				'php_version'         => PHP_VERSION,
				'language'            => get_bloginfo( 'language' ),
				'timezone'            => wp_timezone_string(),
				'active_theme'        => array(
					'name'           => $theme->get( 'Name' ),
					'version'        => $theme->get( 'Version' ),
					'slug'           => $theme->get_stylesheet(),
					'is_block_theme' => PP_FSE::is_block_theme(),
				),
				'scopes'              => PP_Auth::get_scopes(),
				'elementor'           => array(
					'active'  => PP_Helpers::elementor_active(),
					'version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
				),
				'elementor_pro'       => array(
					'active'  => PP_Helpers::elementor_pro_active(),
					'version' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
				),
				'container_experiment'=> $this->flexbox_containers_on(),
				'plugins'             => $plugins,
			)
		);
	}

	/**
	 * Whether the flexbox-container experiment is active (affects section vs container).
	 *
	 * @return bool
	 */
	private function flexbox_containers_on() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}
		try {
			$experiments = \Elementor\Plugin::$instance->experiments;
			if ( $experiments && method_exists( $experiments, 'is_feature_active' ) ) {
				return (bool) $experiments->is_feature_active( 'container' );
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Content CRUD                                                       */
	/* ------------------------------------------------------------------ */

	public function list_content( $request ) {
		$type   = $request->get_param( 'type' ) ? sanitize_key( $request->get_param( 'type' ) ) : 'page';
		$search = $request->get_param( 'search' );
		$status = $request->get_param( 'status' ) ? sanitize_key( $request->get_param( 'status' ) ) : 'any';
		$limit  = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 30 ) ) );

		$query = new WP_Query(
			array(
				'post_type'      => $type,
				'post_status'    => $status,
				's'              => $search ? sanitize_text_field( $search ) : '',
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = $this->summary( $post );
		}

		return rest_ensure_response(
			array(
				'total' => (int) $query->found_posts,
				'items' => $items,
			)
		);
	}

	public function get_content( $request ) {
		return $this->build_content_response( (int) $request['id'] );
	}

	/**
	 * Build the full single-content response for a post id (shared by GET/POST/PUT).
	 *
	 * @param int $post_id Post id.
	 * @return WP_REST_Response|WP_Error
	 */
	private function build_content_response( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return PP_Helpers::error( 'pp_not_found', 'Content not found.', 404 );
		}

		$data                       = $this->summary( $post );
		$data['content_raw']        = $post->post_content;
		$data['is_elementor']       = PP_Elementor::is_built_with_elementor( $post->ID );
		$data['elementor_data']     = PP_Elementor::get_data( $post->ID );
		$data['elementor_settings'] = PP_Elementor::get_page_settings( $post->ID );

		return rest_ensure_response( $data );
	}

	/**
	 * Resolve the post_content string a request wants to write.
	 *
	 * Priority: `blocks` (structured block tree, serialized to block markup) →
	 * `content` (raw markup/HTML). Returns null when neither is present so the
	 * caller can leave existing content untouched on an update.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param bool            $trusted Whether unfiltered HTML is allowed.
	 * @return string|null
	 */
	private function resolve_content( $request, $trusted ) {
		$blocks = $request->get_param( 'blocks' );
		if ( is_array( $blocks ) ) {
			return PP_Gutenberg::serialize_tree( $blocks );
		}
		$content = $request->get_param( 'content' );
		if ( null === $content ) {
			return null;
		}
		$content = (string) $content;
		if ( $trusted ) {
			return $content; // written with KSES disabled — full block markup survives.
		}
		// Non-trusted: strip tags KSES would leak as visible text, then KSES-clean.
		return wp_kses_post( PP_Gutenberg::strip_orphan_style( $content ) );
	}

	/**
	 * Whether this request should be allowed to write unfiltered HTML/block markup.
	 * The API channel is already secret-key gated, so the default is trusted;
	 * callers can force classic KSES filtering with `allow_unfiltered_html:false`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private function trusted_write( $request ) {
		$flag = $request->get_param( 'allow_unfiltered_html' );
		return ( null === $flag ) ? true : filter_var( $flag, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Should this request detach the post from Elementor (in-place migration)?
	 * True when `clear_elementor` is truthy or `builder` is "gutenberg"/"block".
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private function wants_elementor_cleared( $request ) {
		if ( filter_var( $request->get_param( 'clear_elementor' ), FILTER_VALIDATE_BOOLEAN ) ) {
			return true;
		}
		$builder = strtolower( (string) $request->get_param( 'builder' ) );
		return in_array( $builder, array( 'gutenberg', 'block', 'blocks', 'wordpress' ), true );
	}

	public function create_content( $request ) {
		$type  = $request->get_param( 'type' ) ? sanitize_key( $request->get_param( 'type' ) ) : 'page';
		$title = $request->get_param( 'title' );
		if ( empty( $title ) ) {
			return PP_Helpers::error( 'pp_missing_title', 'A "title" is required.', 400 );
		}

		$trusted = $this->trusted_write( $request );
		$content = $this->resolve_content( $request, $trusted );

		$postarr = array(
			'post_type'    => $type,
			'post_title'   => sanitize_text_field( $title ),
			'post_status'  => $request->get_param( 'status' ) ? sanitize_key( $request->get_param( 'status' ) ) : 'draft',
			'post_content' => null === $content ? '' : $content,
			'post_excerpt' => $request->get_param( 'excerpt' ) ? sanitize_textarea_field( $request->get_param( 'excerpt' ) ) : '',
			'post_author'  => $this->default_author(),
		);
		if ( $request->get_param( 'slug' ) ) {
			$postarr['post_name'] = sanitize_title( $request->get_param( 'slug' ) );
		}
		if ( null !== $request->get_param( 'parent' ) ) {
			$postarr['post_parent'] = (int) $request->get_param( 'parent' );
		}

		$post_id = $this->insert_post( $postarr, $trusted );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$applied = $this->apply_builder_and_meta( $post_id, $request );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		return $this->build_content_response( $post_id );
	}

	/**
	 * Insert a post, disabling KSES for trusted (API-key) block-markup writes.
	 *
	 * @param array $postarr Post array.
	 * @param bool  $trusted Whether to bypass KSES.
	 * @return int|WP_Error
	 */
	private function insert_post( $postarr, $trusted ) {
		if ( ! $trusted ) {
			return wp_insert_post( $postarr, true );
		}
		return PP_Gutenberg::without_kses(
			function () use ( $postarr ) {
				return wp_insert_post( $postarr, true );
			}
		);
	}

	/**
	 * Shared post-write side effects: Elementor detach, Elementor data/settings,
	 * page template, taxonomies. Returns WP_Error on the first hard failure.
	 *
	 * @param int             $post_id Post id.
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private function apply_builder_and_meta( $post_id, $request ) {
		// In-place Elementor → Gutenberg migration: detach first so the block
		// content we just wrote actually renders on the front-end.
		if ( $this->wants_elementor_cleared( $request ) ) {
			PP_Elementor::clear( $post_id );
		}

		$elements = $request->get_param( 'elementor_data' );
		if ( is_array( $elements ) ) {
			$saved = PP_Elementor::save_data( $post_id, $elements );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		$settings = $request->get_param( 'elementor_settings' );
		if ( is_array( $settings ) ) {
			PP_Elementor::save_page_settings( $post_id, $settings );
		}

		if ( $request->get_param( 'page_template' ) ) {
			PP_Site::set_page_template( $post_id, sanitize_key( $request->get_param( 'page_template' ) ) );
		}
		$this->apply_taxonomies( $post_id, $request );

		return true;
	}

	/**
	 * Assign categories/tags (by name, created if missing) and an excerpt-less
	 * post its terms. Categories can be passed as an array of names.
	 *
	 * @param int             $post_id Post id.
	 * @param WP_REST_Request $request Request.
	 * @return void
	 */
	private function apply_taxonomies( $post_id, $request ) {
		foreach ( array( 'categories' => 'category', 'tags' => 'post_tag' ) as $param => $taxonomy ) {
			$names = $request->get_param( $param );
			if ( ! is_array( $names ) ) {
				continue;
			}
			$ids = array();
			foreach ( $names as $name ) {
				$name = sanitize_text_field( $name );
				if ( '' === $name ) {
					continue;
				}
				$term = term_exists( $name, $taxonomy );
				if ( ! $term ) {
					$term = wp_insert_term( $name, $taxonomy );
				}
				if ( ! is_wp_error( $term ) ) {
					$ids[] = (int) $term['term_id'];
				}
			}
			wp_set_post_terms( $post_id, $ids, $taxonomy );
		}
	}

	public function update_content( $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return PP_Helpers::error( 'pp_not_found', 'Content not found.', 404 );
		}

		$trusted = $this->trusted_write( $request );
		$content = $this->resolve_content( $request, $trusted );

		$postarr = array( 'ID' => $post_id );
		if ( null !== $request->get_param( 'title' ) ) {
			$postarr['post_title'] = sanitize_text_field( $request->get_param( 'title' ) );
		}
		if ( null !== $request->get_param( 'status' ) ) {
			$postarr['post_status'] = sanitize_key( $request->get_param( 'status' ) );
		}
		if ( null !== $content ) {
			$postarr['post_content'] = $content;
		}
		if ( null !== $request->get_param( 'excerpt' ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( $request->get_param( 'excerpt' ) );
		}
		if ( null !== $request->get_param( 'slug' ) ) {
			$postarr['post_name'] = sanitize_title( $request->get_param( 'slug' ) );
		}
		if ( null !== $request->get_param( 'parent' ) ) {
			$postarr['post_parent'] = (int) $request->get_param( 'parent' );
		}
		if ( count( $postarr ) > 1 ) {
			$res = $this->update_post( $postarr, $trusted );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		$applied = $this->apply_builder_and_meta( $post_id, $request );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		return $this->build_content_response( $post_id );
	}

	/**
	 * Update a post, disabling KSES for trusted (API-key) block-markup writes.
	 *
	 * @param array $postarr Post array.
	 * @param bool  $trusted Whether to bypass KSES.
	 * @return int|WP_Error
	 */
	private function update_post( $postarr, $trusted ) {
		if ( ! $trusted ) {
			return wp_update_post( $postarr, true );
		}
		return PP_Gutenberg::without_kses(
			function () use ( $postarr ) {
				return wp_update_post( $postarr, true );
			}
		);
	}

	public function delete_content( $request ) {
		$post_id = (int) $request['id'];
		if ( ! get_post( $post_id ) ) {
			return PP_Helpers::error( 'pp_not_found', 'Content not found.', 404 );
		}
		$force  = filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );
		$result = wp_delete_post( $post_id, $force );
		if ( ! $result ) {
			return PP_Helpers::error( 'pp_delete_failed', 'Could not delete the content.', 500 );
		}
		return rest_ensure_response( array( 'deleted' => true, 'forced' => $force ) );
	}

	/* ------------------------------------------------------------------ */
	/* Elementor discovery                                                */
	/* ------------------------------------------------------------------ */

	public function list_widgets() {
		if ( ! PP_Helpers::elementor_active() ) {
			return PP_Helpers::error( 'pp_no_elementor', 'Elementor is not active.', 409 );
		}
		return rest_ensure_response( array( 'widgets' => PP_Elementor::list_widgets() ) );
	}

	public function get_widget( $request ) {
		return rest_ensure_response( PP_Elementor::widget_controls( sanitize_key( $request['name'] ) ) );
	}

	public function get_globals() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return PP_Helpers::error( 'pp_no_elementor', 'Elementor is not active.', 409 );
		}
		$out = array( 'colors' => array(), 'fonts' => array() );
		try {
			$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
			if ( $kit ) {
				$settings         = $kit->get_settings();
				$out['colors']    = isset( $settings['system_colors'] ) ? $settings['system_colors'] : array();
				$out['custom']    = isset( $settings['custom_colors'] ) ? $settings['custom_colors'] : array();
				$out['fonts']     = isset( $settings['system_typography'] ) ? $settings['system_typography'] : array();
			}
		} catch ( \Throwable $e ) {
			return PP_Helpers::error( 'pp_globals_failed', $e->getMessage(), 500 );
		}
		return rest_ensure_response( $out );
	}

	public function list_templates() {
		$query = new WP_Query(
			array(
				'post_type'      => 'elementor_library',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'    => $post->ID,
				'title' => get_the_title( $post ),
				'type'  => get_post_meta( $post->ID, '_elementor_template_type', true ),
			);
		}
		return rest_ensure_response( array( 'items' => $items ) );
	}

	/* ------------------------------------------------------------------ */
	/* Media                                                              */
	/* ------------------------------------------------------------------ */

	public function list_media( $request ) {
		$limit = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 30 ) ) );
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => $limit,
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'    => $post->ID,
				'title' => get_the_title( $post ),
				'url'   => wp_get_attachment_url( $post->ID ),
			);
		}
		return rest_ensure_response( array( 'items' => $items ) );
	}

	/**
	 * Sideload an image from a URL into the media library.
	 * Body: { "url": "https://...", "title": "optional", "alt": "optional" }
	 */
	public function upload_media( $request ) {
		$url = esc_url_raw( (string) $request->get_param( 'url' ) );
		if ( empty( $url ) ) {
			return PP_Helpers::error( 'pp_missing_url', 'A "url" is required.', 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$file_array = array(
			'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ) ?: 'image.jpg',
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, 0, $request->get_param( 'title' ) );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return $attachment_id;
		}

		if ( $request->get_param( 'alt' ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $request->get_param( 'alt' ) ) );
		}

		return rest_ensure_response(
			array(
				'id'  => $attachment_id,
				'url' => wp_get_attachment_url( $attachment_id ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Taxonomies / menus                                                 */
	/* ------------------------------------------------------------------ */

	public function list_taxonomies() {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$terms = get_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => false, 'number' => 100 ) );
			$out[ $tax->name ] = array(
				'label' => $tax->label,
				'terms' => is_wp_error( $terms ) ? array() : array_map(
					function ( $t ) {
						return array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
					},
					$terms
				),
			);
		}
		return rest_ensure_response( $out );
	}

	public function list_menus() {
		$menus = wp_get_nav_menus();
		$out   = array();
		foreach ( $menus as $menu ) {
			$out[] = array(
				'id'    => $menu->term_id,
				'name'  => $menu->name,
				'count' => $menu->count,
			);
		}
		return rest_ensure_response( array( 'menus' => $out ) );
	}

	/* ------------------------------------------------------------------ */
	/* Site: themes & homepage                                            */
	/* ------------------------------------------------------------------ */

	public function list_themes() {
		return rest_ensure_response( array( 'themes' => PP_Site::list_themes() ) );
	}

	public function activate_theme( $request ) {
		$slug    = (string) $request->get_param( 'slug' );
		$install = ( null === $request->get_param( 'install' ) )
			? true
			: filter_var( $request->get_param( 'install' ), FILTER_VALIDATE_BOOLEAN );
		return rest_ensure_response( PP_Site::activate_theme( $slug, $install ) );
	}

	public function get_homepage() {
		return rest_ensure_response( PP_Site::get_homepage() );
	}

	public function set_homepage( $request ) {
		$posts = ( null === $request->get_param( 'posts_page_id' ) ) ? null : (int) $request->get_param( 'posts_page_id' );
		return rest_ensure_response( PP_Site::set_homepage( (int) $request->get_param( 'page_id' ), $posts ) );
	}

	/* ------------------------------------------------------------------ */
	/* Scopes / capability report                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Which endpoints each capability group gates — so a client can discover
	 * exactly what a scope controls (self-documenting permissions).
	 *
	 * @return array<string,array>
	 */
	private function scope_endpoints() {
		return array(
			'content'   => array( 'GET/POST /content', 'GET/PUT/DELETE /content/{id}', 'POST /cleanup/elementor-meta' ),
			'media'     => array( 'GET/POST /media', 'GET /assets', 'POST /assets/upload' ),
			'menus'     => array( 'GET/POST /menus', 'GET/PUT/DELETE /menus/{id}', 'GET /menu-locations' ),
			'templates' => array( 'POST /template', 'GET/POST /fse-templates', 'GET/POST /fse-template-parts', 'POST /patterns' ),
			'styles'    => array( 'GET/POST /global-styles', 'GET/POST /settings/custom-css' ),
			'themes'    => array( 'GET /themes', 'POST /themes/activate' ),
			'plugins'   => array( 'GET /plugins', 'POST /plugins/install', 'POST /plugins/{activate|deactivate|delete}' ),
			'settings'  => array( 'POST /settings/options', 'GET/POST /homepage', 'GET/POST /forms/config' ),
		);
	}

	public function get_scopes() {
		$allowed = PP_Auth::get_scopes();
		$map     = $this->scope_endpoints();
		$out     = array();
		foreach ( PP_Auth::SCOPES as $key => $label ) {
			$out[] = array(
				'scope'     => $key,
				'label'     => $label,
				'enabled'   => ! empty( $allowed[ $key ] ),
				'endpoints' => isset( $map[ $key ] ) ? $map[ $key ] : array(),
			);
		}
		return rest_ensure_response( array(
			'scopes'  => $out,
			'always_available' => array(
				'/ping', '/site', '/scopes', '/migration-status',
				'/globals', '/widgets', '/widgets/{name}', '/templates',
				'/blocks', '/patterns (GET)', '/taxonomies',
				'/skill', '/openapi',
			),
			'note'    => 'A request to a disabled scope returns HTTP 403 pp_scope_disabled. Manage these on the plugin\'s Permissions screen; default is everything enabled.',
		) );
	}

	public function migration_status() {
		return rest_ensure_response( PP_Elementor::usage_report() );
	}

	/* ------------------------------------------------------------------ */
	/* Gutenberg / block discovery & patterns                             */
	/* ------------------------------------------------------------------ */

	public function list_blocks() {
		return rest_ensure_response( array( 'blocks' => PP_Gutenberg::list_block_types() ) );
	}

	public function list_patterns() {
		return rest_ensure_response( array( 'patterns' => PP_Gutenberg::list_patterns() ) );
	}

	public function create_pattern( $request ) {
		return rest_ensure_response( PP_Gutenberg::create_pattern( array(
			'title'   => $request->get_param( 'title' ),
			'content' => $request->get_param( 'content' ),
			'blocks'  => $request->get_param( 'blocks' ),
		) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Menus (write)                                                      */
	/* ------------------------------------------------------------------ */

	public function get_menu( $request ) {
		return rest_ensure_response( PP_Menus::get( (int) $request['id'] ) );
	}

	public function create_menu( $request ) {
		return rest_ensure_response( PP_Menus::create( array(
			'name'      => $request->get_param( 'name' ),
			'items'     => $request->get_param( 'items' ),
			'locations' => $request->get_param( 'locations' ),
		) ) );
	}

	public function update_menu( $request ) {
		return rest_ensure_response( PP_Menus::update( (int) $request['id'], array(
			'name'      => $request->get_param( 'name' ),
			'items'     => $request->get_param( 'items' ),
			'locations' => $request->get_param( 'locations' ),
			'append'    => $request->get_param( 'append' ),
		) ) );
	}

	public function delete_menu( $request ) {
		return rest_ensure_response( PP_Menus::delete( (int) $request['id'] ) );
	}

	public function list_menu_locations() {
		return rest_ensure_response( array( 'locations' => PP_Menus::locations() ) );
	}

	/* ------------------------------------------------------------------ */
	/* Plugins                                                            */
	/* ------------------------------------------------------------------ */

	public function list_plugins() {
		return rest_ensure_response( array( 'plugins' => PP_Plugins::list_all() ) );
	}

	public function install_plugin( $request ) {
		return rest_ensure_response( PP_Plugins::install( array(
			'slug'     => $request->get_param( 'slug' ),
			'zip'      => $request->get_param( 'zip' ),
			'activate' => $request->get_param( 'activate' ),
		) ) );
	}

	public function manage_plugin( $request ) {
		$action = sanitize_key( $request['action'] );
		$ref    = (string) ( $request->get_param( 'file' ) ?: $request->get_param( 'slug' ) );
		if ( '' === $ref ) {
			return PP_Helpers::error( 'pp_missing_plugin', 'A plugin "file" or "slug" is required.', 400 );
		}
		return rest_ensure_response( PP_Plugins::manage( $action, $ref ) );
	}

	/* ------------------------------------------------------------------ */
	/* FSE templates, parts, global styles & Additional CSS               */
	/* ------------------------------------------------------------------ */

	public function list_fse_templates() {
		return rest_ensure_response( PP_FSE::list_templates( 'wp_template' ) );
	}

	public function upsert_fse_template( $request ) {
		return rest_ensure_response( PP_FSE::upsert( 'wp_template', array(
			'slug'        => $request->get_param( 'slug' ),
			'title'       => $request->get_param( 'title' ),
			'content'     => $request->get_param( 'content' ),
			'blocks'      => $request->get_param( 'blocks' ),
			'description' => $request->get_param( 'description' ),
		) ) );
	}

	public function list_fse_parts() {
		return rest_ensure_response( PP_FSE::list_templates( 'wp_template_part' ) );
	}

	public function upsert_fse_part( $request ) {
		return rest_ensure_response( PP_FSE::upsert( 'wp_template_part', array(
			'slug'        => $request->get_param( 'slug' ),
			'title'       => $request->get_param( 'title' ),
			'content'     => $request->get_param( 'content' ),
			'blocks'      => $request->get_param( 'blocks' ),
			'area'        => $request->get_param( 'area' ),
			'description' => $request->get_param( 'description' ),
		) ) );
	}

	public function get_global_styles() {
		return rest_ensure_response( PP_FSE::get_global_styles() );
	}

	public function set_global_styles( $request ) {
		return rest_ensure_response( PP_FSE::set_global_styles( array(
			'settings' => $request->get_param( 'settings' ),
			'styles'   => $request->get_param( 'styles' ),
			'css'      => $request->get_param( 'css' ),
		) ) );
	}

	public function get_custom_css( $request ) {
		return rest_ensure_response( PP_FSE::get_custom_css( (string) $request->get_param( 'theme' ) ) );
	}

	public function set_custom_css( $request ) {
		$css = $request->get_param( 'css' );
		if ( null === $css ) {
			return PP_Helpers::error( 'pp_missing_css', 'A "css" string is required.', 400 );
		}
		return rest_ensure_response( PP_FSE::set_custom_css(
			(string) $css,
			filter_var( $request->get_param( 'append' ), FILTER_VALIDATE_BOOLEAN ),
			(string) $request->get_param( 'theme' )
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Assets (fonts/CSS/SVG as cacheable files)                          */
	/* ------------------------------------------------------------------ */

	public function list_assets() {
		return rest_ensure_response( array( 'assets' => PP_Assets::list_all() ) );
	}

	public function upload_asset( $request ) {
		return rest_ensure_response( PP_Assets::upload( array(
			'filename' => $request->get_param( 'filename' ),
			'base64'   => $request->get_param( 'base64' ),
			'subdir'   => $request->get_param( 'subdir' ),
		) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Performance diagnostics                                            */
	/* ------------------------------------------------------------------ */

	public function performance( $request ) {
		return rest_ensure_response( PP_Perf::report( (string) $request->get_param( 'url' ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Batch & Elementor-meta cleanup                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Run many sub-requests against this same namespace in one call. Each entry:
	 * { "method": "POST", "path": "/content", "body": { ... } }. Every sub-request
	 * still passes its own scope/auth check via the internal dispatcher.
	 */
	public function batch( $request ) {
		$requests = $request->get_param( 'requests' );
		if ( ! is_array( $requests ) ) {
			return PP_Helpers::error( 'pp_bad_batch', 'A "requests" array is required.', 400 );
		}
		if ( count( $requests ) > 25 ) {
			return PP_Helpers::error( 'pp_batch_too_large', 'A batch is limited to 25 requests.', 400 );
		}
		$auth     = $request->get_header( 'x_presspilot_key' );
		$bearer   = $request->get_header( 'authorization' );
		$results  = array();
		foreach ( $requests as $sub ) {
			if ( ! is_array( $sub ) || empty( $sub['path'] ) ) {
				$results[] = array( 'status' => 400, 'body' => array( 'error' => 'each request needs a "path"' ) );
				continue;
			}
			$method = isset( $sub['method'] ) ? strtoupper( sanitize_text_field( $sub['method'] ) ) : 'GET';
			$path   = '/' . ltrim( (string) $sub['path'], '/' );
			$req    = new WP_REST_Request( $method, '/' . PP_REST_NS . $path );
			// Carry auth so sub-request permission callbacks pass.
			if ( $auth ) {
				$req->set_header( 'X-PressPilot-Key', $auth );
			}
			if ( $bearer ) {
				$req->set_header( 'Authorization', $bearer );
			}
			if ( isset( $sub['body'] ) && is_array( $sub['body'] ) ) {
				$req->set_body_params( $sub['body'] );
				foreach ( $sub['body'] as $k => $v ) {
					$req->set_param( $k, $v );
				}
			}
			$resp      = rest_do_request( $req );
			$results[] = array(
				'status' => $resp->get_status(),
				'body'   => $resp->get_data(),
			);
		}
		return rest_ensure_response( array( 'results' => $results ) );
	}

	public function cleanup_elementor_meta( $request ) {
		$revisions_only = filter_var( $request->get_param( 'revisions_only' ), FILTER_VALIDATE_BOOLEAN );
		return rest_ensure_response( PP_Elementor::purge_meta( $revisions_only ) );
	}

	/* ------------------------------------------------------------------ */
	/* Native forms                                                       */
	/* ------------------------------------------------------------------ */

	public function form_submit( $request ) {
		$params = $request->get_params();
		$meta   = array(
			'_t'    => isset( $params['_t'] ) ? $params['_t'] : 0,
			'pp_hp' => isset( $params['pp_hp'] ) ? $params['pp_hp'] : '',
		);
		$fields = $request->get_param( 'fields' );
		if ( ! is_array( $fields ) ) {
			// Accept a flat field map too; drop reserved keys.
			$fields = $params;
			unset( $fields['_t'], $fields['pp_hp'], $fields['fields'] );
		}
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$res = PP_Forms::submit( $fields, $ip, $meta );
		return rest_ensure_response( $res );
	}

	public function get_form_config() {
		return rest_ensure_response( PP_Forms::config() );
	}

	public function set_form_config( $request ) {
		return rest_ensure_response( PP_Forms::set_config( array(
			'recipient'     => $request->get_param( 'recipient' ),
			'subject'       => $request->get_param( 'subject' ),
			'success'       => $request->get_param( 'success' ),
			'min_seconds'   => $request->get_param( 'min_seconds' ),
			'rate_per_hour' => $request->get_param( 'rate_per_hour' ),
		) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                            */
	/* ------------------------------------------------------------------ */

	/* ------------------------------------------------------------------ */
	/* Configuration assistant                                            */
	/* ------------------------------------------------------------------ */

	public function config_get_options( $request ) {
		$keys = $request->get_param( 'keys' );
		return rest_ensure_response( PP_Config::get_options( array(
			'keys'   => is_array( $keys ) ? $keys : ( $keys ? explode( ',', (string) $keys ) : array() ),
			'prefix' => (string) $request->get_param( 'prefix' ),
			'reveal' => filter_var( $request->get_param( 'reveal' ), FILTER_VALIDATE_BOOLEAN ),
		) ) );
	}

	public function config_set_options( $request ) {
		return rest_ensure_response( PP_Config::set_options( array(
			'options' => $request->get_param( 'options' ),
			'dry_run' => filter_var( $request->get_param( 'dry_run' ), FILTER_VALIDATE_BOOLEAN ),
			'force'   => filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN ),
			'label'   => (string) $request->get_param( 'label' ),
		) ) );
	}

	public function config_get_meta( $request ) {
		$keys = $request->get_param( 'keys' );
		return rest_ensure_response( PP_Config::get_meta( array(
			'type'   => $request->get_param( 'type' ),
			'id'     => $request->get_param( 'id' ),
			'keys'   => is_array( $keys ) ? $keys : ( $keys ? explode( ',', (string) $keys ) : array() ),
			'reveal' => filter_var( $request->get_param( 'reveal' ), FILTER_VALIDATE_BOOLEAN ),
		) ) );
	}

	public function config_set_meta( $request ) {
		return rest_ensure_response( PP_Config::set_meta( array(
			'type'    => $request->get_param( 'type' ),
			'id'      => $request->get_param( 'id' ),
			'meta'    => $request->get_param( 'meta' ),
			'dry_run' => filter_var( $request->get_param( 'dry_run' ), FILTER_VALIDATE_BOOLEAN ),
		) ) );
	}

	public function config_create_term( $request ) {
		return rest_ensure_response( PP_Config::create_term( array(
			'taxonomy'    => $request->get_param( 'taxonomy' ),
			'name'        => $request->get_param( 'name' ),
			'slug'        => $request->get_param( 'slug' ),
			'parent'      => $request->get_param( 'parent' ),
			'description' => $request->get_param( 'description' ),
			'meta'        => $request->get_param( 'meta' ),
		) ) );
	}

	public function config_assign_terms( $request ) {
		return rest_ensure_response( PP_Config::assign_terms( array(
			'object_id' => $request->get_param( 'object_id' ),
			'taxonomy'  => $request->get_param( 'taxonomy' ),
			'terms'     => $request->get_param( 'terms' ),
			'append'    => filter_var( $request->get_param( 'append' ), FILTER_VALIDATE_BOOLEAN ),
		) ) );
	}

	public function config_snapshot( $request ) {
		$keys = $request->get_param( 'keys' );
		return rest_ensure_response( PP_Config::snapshot( array(
			'keys'   => is_array( $keys ) ? $keys : ( $keys ? explode( ',', (string) $keys ) : array() ),
			'prefix' => (string) $request->get_param( 'prefix' ),
			'label'  => (string) $request->get_param( 'label' ),
		) ) );
	}

	public function config_snapshots() {
		return rest_ensure_response( PP_Config::list_snapshots() );
	}

	public function config_diff( $request ) {
		return rest_ensure_response( PP_Config::diff( (string) $request->get_param( 'id' ) ) );
	}

	public function config_restore( $request ) {
		$keys = $request->get_param( 'keys' );
		return rest_ensure_response( PP_Config::restore(
			(string) $request->get_param( 'id' ),
			is_array( $keys ) ? $keys : ( $keys ? explode( ',', (string) $keys ) : array() )
		) );
	}

	public function config_registered_settings() {
		return rest_ensure_response( PP_Config::registered_settings() );
	}

	public function config_rest_routes( $request ) {
		return rest_ensure_response( PP_Config::rest_routes( (string) $request->get_param( 'prefix' ) ) );
	}

	public function config_discover( $request ) {
		return rest_ensure_response( PP_Config::discover( array(
			'slug'   => $request->get_param( 'slug' ),
			'prefix' => $request->get_param( 'prefix' ),
		) ) );
	}

	public function config_proxy( $request ) {
		return rest_ensure_response( PP_Config::proxy( array(
			'method'   => $request->get_param( 'method' ),
			'path'     => $request->get_param( 'path' ),
			'body'     => $request->get_param( 'body' ),
			'query'    => $request->get_param( 'query' ),
			'as_admin' => $request->get_param( 'as_admin' ),
		) ) );
	}

	public function adapters_list() {
		return rest_ensure_response( PP_Adapters::listing() );
	}

	public function adapters_run( $request ) {
		return rest_ensure_response( PP_Adapters::run(
			$request['slug'],
			$request['action'],
			$request->get_param( 'args' ) ?: $request->get_params()
		) );
	}

	public function db_tables( $request ) {
		return rest_ensure_response( PP_DB::tables( array( 'prefix' => $request->get_param( 'prefix' ) ) ) );
	}

	public function db_describe( $request ) {
		return rest_ensure_response( PP_DB::describe( (string) $request->get_param( 'table' ) ) );
	}

	public function db_select( $request ) {
		return rest_ensure_response( PP_DB::select( array(
			'table'   => $request->get_param( 'table' ),
			'columns' => $request->get_param( 'columns' ),
			'where'   => $request->get_param( 'where' ),
			'order'   => $request->get_param( 'order' ),
			'dir'     => $request->get_param( 'dir' ),
			'limit'   => $request->get_param( 'limit' ),
			'raw'     => $request->get_param( 'raw' ),
		) ) );
	}

	public function db_write( $request ) {
		return rest_ensure_response( PP_DB::write( array(
			'op'      => $request->get_param( 'op' ),
			'table'   => $request->get_param( 'table' ),
			'data'    => $request->get_param( 'data' ),
			'where'   => $request->get_param( 'where' ),
			'dry_run' => filter_var( $request->get_param( 'dry_run' ), FILTER_VALIDATE_BOOLEAN ),
			'force'   => filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN ),
			'limit'   => $request->get_param( 'limit' ),
		) ) );
	}

	public function config_admin_ajax( $request ) {
		return rest_ensure_response( PP_Config::admin_ajax( array(
			'action' => $request->get_param( 'action' ),
			'args'   => $request->get_param( 'args' ),
			'nopriv' => filter_var( $request->get_param( 'nopriv' ), FILTER_VALIDATE_BOOLEAN ),
		) ) );
	}

	public function config_exec( $request ) {
		return rest_ensure_response( PP_Config::exec_php( array( 'code' => $request->get_param( 'code' ) ) ) );
	}

	private function summary( $post ) {
		return array(
			'id'           => $post->ID,
			'type'         => $post->post_type,
			'title'        => get_the_title( $post ),
			'status'       => $post->post_status,
			'slug'         => $post->post_name,
			'parent'       => (int) $post->post_parent,
			'url'          => get_permalink( $post ),
			'edit_url'     => get_edit_post_link( $post->ID, 'raw' ),
			'modified'     => $post->post_modified,
			'is_elementor' => PP_Elementor::is_built_with_elementor( $post->ID ),
		);
	}

	private function default_author() {
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		return ! empty( $admins ) ? (int) $admins[0] : 0;
	}
}
