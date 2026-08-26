<?php
/**
 * Admin settings screens.
 *
 *  - Connect: the API key, REST base URL and a ready-to-paste agent prompt.
 *  - Agents (MCP): the MCP endpoint and per-client connection snippets.
 *  - Copilot: model provider settings and the in-dashboard chat.
 *  - Permissions: per-capability toggles that gate what the API key may touch.
 *  - API & Docs: the bundled agent Skill / OpenAPI links.
 *
 * Styling stays inside the native WordPress admin (cards, form-tables, buttons);
 * only a small scoped stylesheet is added for polish. Every rule that has a side
 * uses logical properties, so the screens mirror correctly on an RTL locale
 * without a second stylesheet — while keys, URLs and code samples are pinned
 * LTR, because those are never right-to-left no matter what the admin language is.
 *
 * Everything a human reads here is translatable. Everything a *model* reads —
 * the agent prompt, the MCP instructions, tool descriptions — deliberately stays
 * in English: the operating manual it is paired with is English, and mixing
 * languages in an LLM's context degrades its output.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Admin {

	/** @var PP_Admin */
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_pp_regenerate', array( $this, 'handle_regenerate' ) );
		add_action( 'admin_post_pp_save_scopes', array( $this, 'handle_save_scopes' ) );
		add_action( 'admin_post_pp_save_mcp', array( $this, 'handle_save_mcp' ) );
		add_action( 'admin_post_pp_save_agent', array( $this, 'handle_save_agent' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PP_FILE ), array( $this, 'action_links' ) );
	}

	public function menu() {
		$product = defined( 'PP_PRODUCT' ) ? PP_PRODUCT : 'PressPilot';
		add_menu_page(
			$product,
			$product,
			'manage_options',
			'presspilot-settings',
			array( $this, 'render' ),
			'dashicons-superhero-alt',
			58
		);
		/* translators: %s: plugin name. */
		$title = __( '%s — Connect', 'presspilot' );
		add_submenu_page( 'presspilot-settings', sprintf( $title, $product ), __( 'Connect', 'presspilot' ), 'manage_options', 'presspilot-settings', array( $this, 'render' ) );
		/* translators: %s: plugin name. */
		add_submenu_page( 'presspilot-settings', sprintf( __( '%s — Agents (MCP)', 'presspilot' ), $product ), __( 'Agents (MCP)', 'presspilot' ), 'manage_options', 'presspilot-agents', array( $this, 'render_agents' ) );
		/* translators: %s: plugin name. */
		add_submenu_page( 'presspilot-settings', sprintf( __( '%s — Copilot', 'presspilot' ), $product ), __( 'Copilot', 'presspilot' ), 'manage_options', 'presspilot-copilot', array( $this, 'render_copilot' ) );
		/* translators: %s: plugin name. */
		add_submenu_page( 'presspilot-settings', sprintf( __( '%s — Permissions', 'presspilot' ), $product ), __( 'Permissions', 'presspilot' ), 'manage_options', 'presspilot-permissions', array( $this, 'render_permissions' ) );
		/* translators: %s: plugin name. */
		add_submenu_page( 'presspilot-settings', sprintf( __( '%s — API & Skill', 'presspilot' ), $product ), __( 'API &amp; Docs', 'presspilot' ), 'manage_options', 'presspilot-docs', array( $this, 'render_docs' ) );
	}

	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=presspilot-settings' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Connect', 'presspilot' ) . '</a>' );
		return $links;
	}

	/**
	 * A small, scoped stylesheet so the screens feel considered without leaving
	 * the native WP admin look. Printed inline (no enqueue/asset files).
	 */
	private function styles() {
		?>
		<style>
			.pp-wrap{max-width:960px}
			.pp-hero{display:flex;align-items:center;gap:12px;margin:6px 0 2px}
			.pp-hero .dashicons{font-size:34px;width:34px;height:34px;color:#2271b1}
			.pp-hero h1{margin:0;padding:0;font-size:23px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
			.pp-ver{display:inline-block;font-size:12px;font-weight:600;color:#2271b1;background:#f0f6fc;border:1px solid #c5d9ed;border-radius:999px;padding:1px 9px;vertical-align:middle;direction:ltr}
			.pp-tag{color:#646970;font-size:13px}
			.pp-sec{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 14px;border:1px solid #e0e0e0;border-radius:8px;background:#fafafa;margin:4px 0 12px}
			.pp-sec .dashicons{color:#2271b1}
			.pp-sec .pp-push{margin-inline-start:auto}
			.pp-dot{display:inline-block;width:8px;height:8px;border-radius:50%;vertical-align:middle;margin-inline-end:4px}
			.pp-dot.on{background:#00a32a}.pp-dot.off{background:#d63638}
			.pp-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 20px;margin:16px 0;box-shadow:0 1px 2px rgba(0,0,0,.04)}
			.pp-card h2{margin-top:0;font-size:15px;display:flex;align-items:center;gap:8px}
			.pp-step{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#2271b1;color:#fff;font-size:12px;font-weight:600;flex:0 0 auto}
			.pp-field{width:520px;max-width:100%}
			.pp-mono{font-family:Menlo,Consolas,monospace}
			.pp-scope-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 24px;margin:8px 0 4px}
			@media(max-width:782px){.pp-scope-grid{grid-template-columns:1fr}}
			.pp-scope{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid #e0e0e0;border-radius:6px;background:#fafafa}
			.pp-scope input{margin-top:2px}
			.pp-scope .pp-scope-key{font-weight:600;text-transform:capitalize;direction:ltr;display:inline-block}
			.pp-scope .pp-scope-desc{color:#646970;font-size:12px;display:block;margin-top:2px}
			.pp-badge{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:999px}
			.pp-badge.on{background:#edfaef;color:#00794f;border:1px solid #b8e6c8}
			.pp-badge.off{background:#fbeaea;color:#b32d2e;border:1px solid #f0c4c4}
			.pp-pill-row{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0}
			.pp-links .button{margin-inline-end:6px}
			.pp-tabs{display:flex;gap:4px;flex-wrap:wrap;border-bottom:1px solid #dcdcde;margin:10px 0 0}
			.pp-tab{background:none;border:1px solid transparent;border-bottom:none;padding:8px 14px;cursor:pointer;font-size:13px;font-weight:600;color:#50575e;border-start-start-radius:6px;border-start-end-radius:6px}
			.pp-tab[aria-selected="true"]{background:#fff;border-color:#dcdcde;color:#1d2327;margin-bottom:-1px}
			.pp-panel{display:none;padding:14px 0 0}
			.pp-panel.is-active{display:block}
			.pp-code{position:relative;background:#1d2327;color:#f0f0f1;border-radius:6px;padding:14px 16px;overflow:auto;font-family:Menlo,Consolas,monospace;font-size:12.5px;line-height:1.6;white-space:pre;margin:8px 0}
			.pp-copy{position:absolute;top:8px;inset-inline-end:8px}
			.pp-chat{border:1px solid #dcdcde;border-radius:8px;background:#fff;height:460px;overflow-y:auto;padding:14px}
			.pp-msg{margin:0 0 14px;display:flex;gap:10px;align-items:flex-start}
			.pp-msg .pp-who{flex:0 0 68px;font-size:11px;font-weight:700;text-transform:uppercase;color:#787c82;padding-top:3px}
			.pp-msg .pp-body{flex:1;min-width:0;white-space:pre-wrap;word-wrap:break-word;unicode-bidi:plaintext}
			.pp-msg.user .pp-body{background:#f0f6fc;border:1px solid #c5d9ed;border-radius:6px;padding:8px 12px}
			.pp-tool{font-family:Menlo,Consolas,monospace;font-size:12px;background:#f6f7f7;border:1px solid #e0e0e0;border-inline-start:3px solid #2271b1;border-radius:4px;padding:6px 10px;margin:4px 0;direction:ltr;text-align:left}
			.pp-tool.err{border-inline-start-color:#d63638;background:#fcf0f1}
			.pp-tool b{font-weight:600}
			.pp-tool .pp-sum{color:#646970}
			.pp-composer{display:flex;gap:8px;margin-top:10px}
			.pp-composer textarea{flex:1;min-height:64px;font-family:inherit;unicode-bidi:plaintext}
			.pp-warn{background:#fcf9e8;border:1px solid #f0e6b8;border-radius:6px;padding:10px 12px;margin:10px 0;font-size:13px}

			/*
			 * Machine text is never right-to-left. Keys, endpoints, curl lines, TOML
			 * and JSON stay LTR and left-aligned even when the admin is Persian,
			 * Arabic or any other RTL locale — mirroring them makes them unreadable
			 * and, worse, un-copyable in the right order.
			 */
			.pp-code,.pp-mono,
			.pp-wrap input.code,.pp-wrap textarea.code,
			.pp-wrap input[type="url"],.pp-wrap input[readonly].code{direction:ltr;text-align:left}
			/* An inline LTR run: keeps a sequence of code spans in source order on RTL. */
			.pp-ltr{direction:ltr;unicode-bidi:embed}
			select.pp-ltr{text-align:left}
			.pp-wrap code,.pp-wrap kbd{direction:ltr;display:inline-block;unicode-bidi:embed}
		</style>
		<?php
	}

	/**
	 * Strings the inline scripts need. Kept here so the JS carries no English of
	 * its own and the whole screen translates as one unit.
	 *
	 * @return array<string,string>
	 */
	private function js_strings() {
		return array(
			'copy'      => __( 'Copy', 'presspilot' ),
			'copied'    => __( 'Copied', 'presspilot' ),
			'working'   => __( 'Working…', 'presspilot' ),
			'loading'   => __( 'Loading…', 'presspilot' ),
			'you'       => __( 'You', 'presspilot' ),
			'copilot'   => __( 'Copilot', 'presspilot' ),
			'tools'     => __( 'Tools', 'presspilot' ),
			'error'     => __( 'Error', 'presspilot' ),
			'pickModel' => __( '— pick a model —', 'presspilot' ),
			/* translators: %d: number of models returned by the provider. */
			'nModels'   => __( '%d models', 'presspilot' ),
			/* translators: %s: error message. */
			'failed'    => __( 'Failed: %s', 'presspilot' ),
		);
	}

	/** A dark code block with a copy button. */
	private function code_block( $code, $id ) {
		?>
		<div class="pp-code"><button type="button" class="button button-small pp-copy"
			onclick="navigator.clipboard.writeText(document.getElementById('<?php echo esc_attr( $id ); ?>').textContent);this.textContent='<?php echo esc_js( __( 'Copied', 'presspilot' ) ); ?> ✓';"><?php echo esc_html__( 'Copy', 'presspilot' ); ?></button><span id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $code ); ?></span></div>
		<?php
	}

	private function hero( $sub = '' ) {
		$product = defined( 'PP_PRODUCT' ) ? PP_PRODUCT : 'PressPilot';
		$version = defined( 'PP_VERSION' ) ? PP_VERSION : '';
		?>
		<div class="pp-hero">
			<span class="dashicons dashicons-superhero-alt"></span>
			<div>
				<h1>
					<?php echo esc_html( $product ); ?>
					<?php if ( $version ) : ?><span class="pp-ver">v<?php echo esc_html( $version ); ?></span><?php endif; ?>
					<?php if ( $sub ) : ?><span class="pp-tag">— <?php echo esc_html( $sub ); ?></span><?php endif; ?>
				</h1>
				<div class="pp-tag"><?php echo esc_html__( 'AI copilot for WordPress', 'presspilot' ); ?></div>
			</div>
		</div>
		<?php
	}

	/** The shared footer credit line. */
	private function footer_note() {
		?>
		<p class="pp-tag" style="margin-top:6px">
			<?php echo esc_html( defined( 'PP_PRODUCT' ) ? PP_PRODUCT : 'PressPilot' ); ?> v<?php echo esc_html( defined( 'PP_VERSION' ) ? PP_VERSION : '' ); ?> · GPL-2.0-or-later ·
			<a href="https://bobclub.ir" target="_blank" rel="noopener">bobclub.ir</a> ·
			<a href="https://t.me/bob_club" target="_blank" rel="noopener"><?php echo esc_html__( 'Telegram', 'presspilot' ); ?></a> ·
			<?php
			printf(
				/* translators: %s: link reading "Buy me a coffee". */
				esc_html__( 'Enjoying it? %s', 'presspilot' ),
				'<a href="https://bobclub.ir/coffee" target="_blank" rel="noopener">' . esc_html__( 'Buy me a coffee ☕', 'presspilot' ) . '</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * The ready-to-paste prompt the user hands to their AI agent.
	 *
	 * Intentionally NOT translated: it is read by a language model, not a person,
	 * and it pairs with an English operating manual (the Skill). Handing a model
	 * instructions in one language and its manual in another makes it worse at
	 * the job, so this stays English on every locale.
	 *
	 * @param string $rest_url REST base URL.
	 * @param string $key      API key.
	 * @return string
	 */
	private function agent_prompt( $rest_url, $key ) {
		$product = defined( 'PP_PRODUCT' ) ? PP_PRODUCT : 'PressPilot';
		$mcp     = PP_MCP::endpoint_url();
		return
"You are an autonomous web developer connected to a WordPress site through the {$product} API.

Connection
- API base: {$rest_url}
- Auth header on EVERY request: X-PressPilot-Key: {$key}
- (If your client speaks MCP, connect to {$mcp} with `Authorization: Bearer {$key}` instead — you then get the tools natively and can skip the raw HTTP below.)

Before you build anything
1. GET {$rest_url}/skill — the operating manual. Follow its rules exactly.
2. GET {$rest_url}/openapi — the full OpenAPI 3.0 spec.
3. GET {$rest_url}/site — the environment (WordPress version, active theme, block-theme flag, enabled scopes).

What you can do
- Build pages/posts with NATIVE Gutenberg blocks (send a structured \"blocks\" tree or raw block markup) with no page-builder dependency.
- Edit existing content in place: PUT /content/{id}. To convert legacy page-builder content to blocks on the same URL, set builder:\"gutenberg\".
- Global CSS & fonts: POST /settings/custom-css (Additional CSS) or POST /global-styles (block-theme theme.json). Host fonts as files with POST /assets/upload.
- Header/footer for a block theme: POST /fse-template-parts and /fse-templates. Menus: POST /menus. Plugins: POST /plugins/install. Check speed with GET /performance.

Respect the site's Permissions: some capabilities may be turned off (see /site → scopes). After each change, fetch the live URL and verify it renders. Keep the API key secret.";
	}

	/* ------------------------------------------------------------------ */
	/* Form handlers                                                      */
	/* ------------------------------------------------------------------ */

	public function handle_regenerate() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'pp_regenerate' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'presspilot' ) );
		}
		PP_Auth::regenerate_key();
		wp_safe_redirect( add_query_arg( 'pp_regenerated', '1', admin_url( 'admin.php?page=presspilot-settings' ) ) );
		exit;
	}

	public function handle_save_scopes() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'pp_save_scopes' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'presspilot' ) );
		}
		$enabled = isset( $_POST['pp_scopes'] ) && is_array( $_POST['pp_scopes'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['pp_scopes'] ) )
			: array();
		PP_Auth::save_scopes( $enabled );
		// Master on/off switch + optional IP allow-list.
		PP_Auth::set_enabled( ! empty( $_POST['pp_api_enabled'] ) );
		PP_Auth::save_allowed_ips( isset( $_POST['pp_allowed_ips'] ) ? wp_unslash( $_POST['pp_allowed_ips'] ) : '' );
		// Dangerous opt-in: allow the /exec code-execution endpoint (off by default).
		update_option( PP_Config::EXEC_OPTION, ! empty( $_POST['pp_allow_exec'] ) ? '1' : '0', false );
		wp_safe_redirect( add_query_arg( 'pp_scopes_saved', '1', admin_url( 'admin.php?page=presspilot-permissions' ) ) );
		exit;
	}

	public function handle_save_mcp() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'pp_save_mcp' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'presspilot' ) );
		}
		update_option( PP_MCP::OPTION_ENABLED, ! empty( $_POST['pp_mcp_enabled'] ) ? '1' : '0', false );
		update_option( PP_MCP::OPTION_URL_KEY, ! empty( $_POST['pp_mcp_url_key'] ) ? '1' : '0', false );
		$profile = isset( $_POST['pp_tool_profile'] ) ? sanitize_key( wp_unslash( $_POST['pp_tool_profile'] ) ) : 'full';
		update_option( PP_Tools::OPTION_PROFILE, 'essential' === $profile ? 'essential' : 'full', false );
		wp_safe_redirect( add_query_arg( 'pp_saved', '1', admin_url( 'admin.php?page=presspilot-agents' ) ) );
		exit;
	}

	public function handle_save_agent() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'pp_save_agent' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'presspilot' ) );
		}
		PP_Providers::save_config(
			array(
				'provider'  => isset( $_POST['pp_provider'] ) ? wp_unslash( $_POST['pp_provider'] ) : '',
				'api_key'   => isset( $_POST['pp_api_key'] ) ? wp_unslash( $_POST['pp_api_key'] ) : '',
				'model'     => isset( $_POST['pp_model'] ) ? wp_unslash( $_POST['pp_model'] ) : '',
				'base_url'  => isset( $_POST['pp_base_url'] ) ? wp_unslash( $_POST['pp_base_url'] ) : '',
				'max_steps' => isset( $_POST['pp_max_steps'] ) ? wp_unslash( $_POST['pp_max_steps'] ) : '',
			)
		);
		wp_safe_redirect( add_query_arg( 'pp_saved', '1', admin_url( 'admin.php?page=presspilot-copilot' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Connect                                                            */
	/* ------------------------------------------------------------------ */

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$key      = PP_Auth::get_key();
		$rest_url = rest_url( PP_REST_NS );
		$docs_url = admin_url( 'admin.php?page=presspilot-docs' );
		$perm_url = admin_url( 'admin.php?page=presspilot-permissions' );
		$prompt   = $this->agent_prompt( $rest_url, $key );
		$scopes   = PP_Auth::get_scopes();
		$off      = array_keys( array_filter( $scopes, function ( $v ) { return ! $v; } ) );
		$this->styles();
		?>
		<div class="wrap pp-wrap">
			<?php $this->hero( __( 'Connect', 'presspilot' ) ); ?>

			<?php if ( isset( $_GET['pp_regenerated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'A new API key was generated. Update it wherever you use it — the prompt below already contains the new key.', 'presspilot' ); ?></p></div>
			<?php endif; ?>

			<div class="pp-card" style="border-color:#c5d9ed;background:#f6fbff">
				<h2><span class="dashicons dashicons-rest-api"></span> <?php echo esc_html__( 'Connect an agent directly', 'presspilot' ); ?></h2>
				<p style="margin:0 0 10px">
					<?php echo esc_html__( 'Claude Code, OpenAI Codex, Cursor and any other MCP client can plug straight into this site and see it as native tools — no prompt to paste, no HTTP calls to write. Or connect a model (Anthropic, OpenAI, OpenRouter, AgentRouter) and run the copilot right here in the dashboard.', 'presspilot' ); ?>
				</p>
				<div class="pp-links">
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=presspilot-agents' ) ); ?>"><?php echo esc_html__( 'Agents (MCP)', 'presspilot' ); ?> →</a>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=presspilot-copilot' ) ); ?>"><?php echo esc_html__( 'Built-in Copilot', 'presspilot' ); ?> →</a>
				</div>
			</div>

			<div class="pp-card">
				<h2><span class="pp-step">1</span> <?php echo esc_html__( 'Give your agent this prompt', 'presspilot' ); ?></h2>
				<p class="description"><?php echo esc_html__( 'For agents that do not speak MCP. Paste it into your AI agent — it already contains your live URL and API key. The prompt is in English because it is read by a language model, not by you.', 'presspilot' ); ?></p>
				<p>
					<button type="button" class="button button-primary"
						onclick="var t=document.getElementById('pp-prompt');t.select();document.execCommand('copy');this.textContent='<?php echo esc_js( __( 'Copied', 'presspilot' ) ); ?> ✓';"><?php echo esc_html__( 'Copy prompt', 'presspilot' ); ?></button>
				</p>
				<textarea id="pp-prompt" readonly onclick="this.select()"
					style="width:100%;height:280px;" class="pp-mono"><?php echo esc_textarea( $prompt ); ?></textarea>
			</div>

			<div class="pp-card">
				<h2><span class="pp-step">2</span> <?php echo esc_html__( 'Connection details', 'presspilot' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php echo esc_html__( 'REST base URL', 'presspilot' ); ?></th><td>
						<input type="text" class="regular-text code pp-field" readonly value="<?php echo esc_attr( $rest_url ); ?>" onclick="this.select()"></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'API key', 'presspilot' ); ?> <span style="color:#d63638"><?php echo esc_html__( '(secret)', 'presspilot' ); ?></span></th><td>
						<input type="text" class="regular-text code pp-field" readonly value="<?php echo esc_attr( $key ); ?>" onclick="this.select()">
						<p class="description">
						<?php
						printf(
							/* translators: %s: the HTTP header name, already formatted as code. */
							esc_html__( 'Sent as the %s header. Anyone holding this key can change everything your Permissions allow.', 'presspilot' ),
							'<code>X-PressPilot-Key</code>'
						);
						?>
						</p></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Access &amp; security', 'presspilot' ); ?></th><td>
						<?php $api_on = PP_Auth::is_enabled(); $ips = trim( PP_Auth::get_allowed_ips() ); ?>
						<div class="pp-sec">
							<span class="dashicons dashicons-shield"></span>
							<span><span class="pp-dot <?php echo $api_on ? 'on' : 'off'; ?>"></span><strong><?php echo $api_on ? esc_html__( 'API enabled', 'presspilot' ) : esc_html__( 'API disabled', 'presspilot' ); ?></strong></span>
							<span style="color:#dcdcde">|</span>
							<span><?php echo esc_html__( 'IP allow-list:', 'presspilot' ); ?> <strong>
								<?php
								if ( $ips ) {
									$count = count( preg_split( '/\s+/', $ips ) );
									/* translators: %d: number of allowed IP addresses or ranges. */
									echo esc_html( sprintf( _n( '%d address', '%d addresses', $count, 'presspilot' ), $count ) );
								} else {
									echo esc_html__( 'off (any IP)', 'presspilot' );
								}
								?>
							</strong></span>
							<span style="color:#dcdcde">|</span>
							<span><?php echo esc_html__( 'Capabilities:', 'presspilot' ); ?> <strong>
								<?php
								if ( empty( $off ) ) {
									echo esc_html__( 'all enabled', 'presspilot' );
								} else {
									/* translators: %d: number of disabled capabilities. */
									echo esc_html( sprintf( _n( '%d disabled', '%d disabled', count( $off ), 'presspilot' ), count( $off ) ) );
								}
								?>
							</strong></span>
							<a class="button button-small pp-push" href="<?php echo esc_url( $perm_url ); ?>"><?php echo esc_html__( 'Manage on Permissions', 'presspilot' ); ?> →</a>
						</div>
						<p class="description">
						<?php
						printf(
							/* translators: %s: link reading "Permissions". */
							esc_html__( 'Turn the whole API on or off and restrict it to specific IP addresses on the %s screen.', 'presspilot' ),
							'<a href="' . esc_url( $perm_url ) . '"><strong>' . esc_html__( 'Permissions', 'presspilot' ) . '</strong></a>'
						);
						?>
						</p>
					</td></tr>
					<?php $theme = wp_get_theme(); ?>
					<tr><th scope="row"><?php echo esc_html__( 'Environment', 'presspilot' ); ?></th><td>
						<?php echo esc_html__( 'WordPress:', 'presspilot' ); ?> <strong><?php echo esc_html( get_bloginfo( 'version' ) ); ?></strong> &nbsp;|&nbsp;
						<?php echo esc_html__( 'Theme:', 'presspilot' ); ?> <strong><?php echo esc_html( $theme->get( 'Name' ) ); ?></strong> &nbsp;|&nbsp;
						<?php echo esc_html__( 'Block theme:', 'presspilot' ); ?> <strong><?php echo PP_FSE::is_block_theme() ? esc_html__( 'yes', 'presspilot' ) : esc_html__( 'no', 'presspilot' ); ?></strong></td></tr>
				</table>

				<div class="pp-links">
					<a class="button" href="<?php echo esc_url( $rest_url . '/skill' ); ?>" target="_blank"><?php echo esc_html__( 'Skill (rules)', 'presspilot' ); ?></a>
					<a class="button" href="<?php echo esc_url( $rest_url . '/openapi' ); ?>" target="_blank"><?php echo esc_html__( 'OpenAPI spec', 'presspilot' ); ?></a>
					<a class="button button-primary" href="<?php echo esc_url( $docs_url ); ?>"><?php echo esc_html__( 'Full documentation', 'presspilot' ); ?></a>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px"
					onsubmit="return confirm('<?php echo esc_js( __( 'Generate a new key? The old key stops working immediately.', 'presspilot' ) ); ?>');">
					<input type="hidden" name="action" value="pp_regenerate">
					<?php wp_nonce_field( 'pp_regenerate' ); ?>
					<?php submit_button( __( 'Regenerate API key', 'presspilot' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<div class="pp-card">
				<h2><?php echo esc_html__( 'Quick test', 'presspilot' ); ?></h2>
				<p class="description"><?php echo esc_html__( 'Run this from any terminal to confirm the connection works:', 'presspilot' ); ?></p>
				<textarea readonly class="code pp-mono" style="width:100%;height:56px;" onclick="this.select()">curl -s "<?php echo esc_url( $rest_url ); ?>/ping" -H "X-PressPilot-Key: <?php echo esc_attr( $key ); ?>"</textarea>
			</div>

			<?php $this->footer_note(); ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Agents (MCP)                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Agents screen: the MCP endpoint plus copy-paste setup for each client.
	 */
	public function render_agents() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$key      = PP_Auth::get_key();
		$url      = PP_MCP::endpoint_url();
		$on       = PP_MCP::is_enabled();
		$snippets = PP_MCP::client_snippets( $key );
		$tools    = PP_Tools::available();
		$this->styles();
		?>
		<div class="wrap pp-wrap">
			<?php $this->hero( __( 'Agents — direct MCP connection', 'presspilot' ) ); ?>

			<?php if ( isset( $_GET['pp_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Saved.', 'presspilot' ); ?></p></div>
			<?php endif; ?>

			<div class="pp-card">
				<h2><span class="dashicons dashicons-rest-api"></span> <?php echo esc_html__( 'Model Context Protocol endpoint', 'presspilot' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %s: the number of tools exposed, already wrapped in <strong>. */
						esc_html__( 'MCP is the standard way an AI agent plugs into an external system. Point a client at this URL and your site shows up inside it as %s — no prompt engineering, no hand-written HTTP calls. The agent Skill is delivered automatically on connect, so agents follow this site\'s build rules from their first message.', 'presspilot' ),
						'<strong>' . esc_html( sprintf( _n( '%d native tool', '%d native tools', count( $tools ), 'presspilot' ), count( $tools ) ) ) . '</strong>'
					);
					?>
				</p>

				<div class="pp-sec">
					<span class="dashicons dashicons-<?php echo $on ? 'yes-alt' : 'dismiss'; ?>"></span>
					<span><span class="pp-dot <?php echo $on ? 'on' : 'off'; ?>"></span><strong><?php echo $on ? esc_html__( 'MCP enabled', 'presspilot' ) : esc_html__( 'MCP disabled', 'presspilot' ); ?></strong></span>
					<span style="color:#dcdcde">|</span>
					<span><?php echo esc_html__( 'Transport:', 'presspilot' ); ?> <strong><?php echo esc_html__( 'Streamable HTTP', 'presspilot' ); ?></strong></span>
					<span style="color:#dcdcde">|</span>
					<span><?php echo esc_html__( 'Tools exposed:', 'presspilot' ); ?> <strong><?php echo esc_html( number_format_i18n( count( $tools ) ) ); ?></strong>
						(<?php echo 'essential' === PP_Tools::profile() ? esc_html__( 'essential profile', 'presspilot' ) : esc_html__( 'full profile', 'presspilot' ); ?>)</span>
				</div>

				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php echo esc_html__( 'Endpoint URL', 'presspilot' ); ?></th><td>
						<input type="text" class="regular-text code pp-field" readonly value="<?php echo esc_attr( $url ); ?>" onclick="this.select()"></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Authentication', 'presspilot' ); ?></th><td>
						<code>Authorization: Bearer <?php echo esc_html( $key ); ?></code>
						<p class="description">
						<?php
						printf(
							/* translators: %s: alternative header name, formatted as code. */
							esc_html__( 'The same API key as the REST API. %s works too.', 'presspilot' ),
							'<code>X-PressPilot-Key</code>'
						);
						?>
						</p></td></tr>
				</table>
			</div>

			<div class="pp-card">
				<h2><span class="pp-step">1</span> <?php echo esc_html__( 'Connect your agent', 'presspilot' ); ?></h2>
				<div class="pp-tabs" role="tablist">
					<?php $first = true; foreach ( $snippets as $slug => $snippet ) : ?>
						<button type="button" class="pp-tab" role="tab" aria-selected="<?php echo $first ? 'true' : 'false'; ?>"
							data-pp-tab="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $snippet['label'] ); ?></button>
					<?php $first = false; endforeach; ?>
				</div>
				<?php $first = true; foreach ( $snippets as $slug => $snippet ) : ?>
					<div class="pp-panel <?php echo $first ? 'is-active' : ''; ?>" data-pp-panel="<?php echo esc_attr( $slug ); ?>">
						<p class="description"><?php echo esc_html( $snippet['note'] ); ?></p>
						<?php $this->code_block( $snippet['code'], 'pp-snip-' . $slug ); ?>
					</div>
				<?php $first = false; endforeach; ?>
				<script>
				document.querySelectorAll('.pp-tab').forEach(function(tab){
					tab.addEventListener('click', function(){
						document.querySelectorAll('.pp-tab').forEach(function(t){ t.setAttribute('aria-selected','false'); });
						document.querySelectorAll('.pp-panel').forEach(function(p){ p.classList.remove('is-active'); });
						tab.setAttribute('aria-selected','true');
						var panel = document.querySelector('[data-pp-panel="' + tab.dataset.ppTab + '"]');
						if (panel) { panel.classList.add('is-active'); }
					});
				});
				</script>
				<p class="description" style="margin-top:10px">
					<?php
					printf(
						/* translators: %s: an example instruction to give the agent, already wrapped in <em>. */
						esc_html__( 'Once connected, try: %s', 'presspilot' ),
						'<em>' . esc_html__( '“Read the PressPilot skill, then build me a pricing page with native blocks.”', 'presspilot' ) . '</em>'
					);
					?>
				</p>
			</div>

			<div class="pp-card">
				<h2><span class="pp-step">2</span> <?php echo esc_html__( 'Settings', 'presspilot' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pp_save_mcp">
					<?php wp_nonce_field( 'pp_save_mcp' ); ?>
					<table class="form-table" role="presentation">
						<tr><th scope="row"><?php echo esc_html__( 'MCP endpoint', 'presspilot' ); ?></th><td>
							<label><input type="checkbox" name="pp_mcp_enabled" value="1" <?php checked( $on ); ?>>
								<strong><?php echo esc_html__( 'Enabled', 'presspilot' ); ?></strong> — <?php echo esc_html__( 'accept MCP connections at the URL above.', 'presspilot' ); ?></label>
							<p class="description"><?php echo esc_html__( 'Turning this off leaves the REST API working; only the MCP endpoint stops answering.', 'presspilot' ); ?></p></td></tr>
						<tr><th scope="row"><?php echo esc_html__( 'Tool surface', 'presspilot' ); ?></th><td>
							<?php $profile = PP_Tools::profile(); ?>
							<label style="display:block;margin-bottom:4px"><input type="radio" name="pp_tool_profile" value="full" <?php checked( 'full', $profile ); ?>>
								<strong><?php echo esc_html__( 'Full', 'presspilot' ); ?></strong> — <?php echo esc_html__( 'every tool, including plugin configuration, database and adapters.', 'presspilot' ); ?></label>
							<label style="display:block"><input type="radio" name="pp_tool_profile" value="essential" <?php checked( 'essential', $profile ); ?>>
								<strong><?php echo esc_html__( 'Essential', 'presspilot' ); ?></strong> — <?php echo esc_html__( 'the core building set only. Fewer tools means less context and fewer wrong turns on smaller models.', 'presspilot' ); ?></label>
							<p class="description">
							<?php
							printf(
								/* translators: %s: link reading "Permissions". */
								esc_html__( 'Tools for a capability you turned off on the %s screen are hidden either way.', 'presspilot' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=presspilot-permissions' ) ) . '">' . esc_html__( 'Permissions', 'presspilot' ) . '</a>'
							);
							?>
							</p></td></tr>
						<tr><th scope="row"><?php echo esc_html__( 'Key in the URL', 'presspilot' ); ?></th><td>
							<label><input type="checkbox" name="pp_mcp_url_key" value="1" <?php checked( PP_MCP::url_key_allowed() ); ?>>
							<?php
							printf(
								/* translators: 1: the query parameter form, 2: the HTTP header name. Both formatted as code. */
								esc_html__( 'Allow %1$s as an alternative to the %2$s header.', 'presspilot' ),
								'<code>?key=…</code>',
								'<code>Authorization</code>'
							);
							?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'For clients that accept only a URL. It also rescues setups where the server strips Authorization headers, which is common on Apache CGI.', 'presspilot' ); ?>
								<strong><?php echo esc_html__( 'The key then travels in the URL', 'presspilot' ); ?></strong>,
								<?php echo esc_html__( 'where server and proxy logs can record it — leave this off unless a client needs it.', 'presspilot' ); ?>
							</p></td></tr>
					</table>
					<?php submit_button( __( 'Save MCP settings', 'presspilot' ) ); ?>
				</form>
			</div>

			<div class="pp-card">
				<h2><span class="dashicons dashicons-admin-tools"></span>
					<?php
					/* translators: %d: number of tools currently exposed. */
					echo esc_html( sprintf( __( 'Tools currently exposed (%d)', 'presspilot' ), count( $tools ) ) );
					?>
				</h2>
				<div class="pp-pill-row">
					<?php foreach ( $tools as $name => $tool ) : ?>
						<code title="<?php echo esc_attr( $tool['description'] ); ?>" style="background:#f0f6fc;border:1px solid #c5d9ed;border-radius:4px;padding:2px 7px;font-size:12px"><?php echo esc_html( $name ); ?></code>
					<?php endforeach; ?>
				</div>
			</div>

			<?php $this->footer_note(); ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Copilot                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Copilot screen: pick a provider, then talk to the site directly.
	 */
	public function render_copilot() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$config    = PP_Providers::public_config();
		$providers = PP_Providers::providers();
		$rest_url  = rest_url( PP_REST_NS );
		$key       = PP_Auth::get_key();
		$this->styles();
		?>
		<div class="wrap pp-wrap">
			<?php $this->hero( __( 'Copilot', 'presspilot' ) ); ?>

			<?php if ( isset( $_GET['pp_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Copilot settings saved.', 'presspilot' ); ?></p></div>
			<?php endif; ?>

			<div class="pp-card">
				<h2><span class="dashicons dashicons-admin-users"></span> <?php echo esc_html__( 'Model provider', 'presspilot' ); ?></h2>
				<p class="description">
					<?php echo esc_html__( 'Connect a model and the copilot runs the same tools an external agent gets over MCP, under the same permissions and the same Skill — without leaving the dashboard.', 'presspilot' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pp_save_agent">
					<?php wp_nonce_field( 'pp_save_agent' ); ?>
					<table class="form-table" role="presentation">
						<tr><th scope="row"><label for="pp_provider"><?php echo esc_html__( 'Provider', 'presspilot' ); ?></label></th><td>
							<select name="pp_provider" id="pp_provider" class="pp-field">
								<?php foreach ( $providers as $slug => $provider ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $config['provider'] ); ?>
										data-base="<?php echo esc_attr( $provider['base_url'] ); ?>"
										data-keys="<?php echo esc_attr( $provider['keys_url'] ); ?>"
										data-note="<?php echo esc_attr( $provider['note'] ); ?>"><?php echo esc_html( $provider['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description" id="pp-provider-note"><?php echo esc_html( $providers[ $config['provider'] ]['note'] ); ?></p></td></tr>
						<tr><th scope="row"><label for="pp_api_key"><?php echo esc_html__( 'API key', 'presspilot' ); ?></label></th><td>
							<input type="password" name="pp_api_key" id="pp_api_key" class="regular-text code pp-field" autocomplete="off"
								placeholder="<?php echo $config['api_key'] ? esc_attr( $config['api_key'] ) : 'sk-…'; ?>">
							<p class="description">
								<?php echo esc_html__( 'Stored in this site\'s database and used only for these calls. Leave blank to keep the saved key.', 'presspilot' ); ?>
								<?php if ( $providers[ $config['provider'] ]['keys_url'] ) : ?>
									<a href="<?php echo esc_url( $providers[ $config['provider'] ]['keys_url'] ); ?>" target="_blank" rel="noopener" id="pp-keys-link"><?php echo esc_html__( 'Get a key', 'presspilot' ); ?> →</a>
								<?php endif; ?>
							</p></td></tr>
						<tr><th scope="row"><label for="pp_model"><?php echo esc_html__( 'Model', 'presspilot' ); ?></label></th><td>
							<input type="text" name="pp_model" id="pp_model" class="regular-text code pp-field" value="<?php echo esc_attr( $config['model'] ); ?>" placeholder="claude-opus-5">
							<button type="button" class="button" id="pp-load-models"><?php echo esc_html__( 'Load available models', 'presspilot' ); ?></button>
							<select id="pp-model-list" style="display:none;margin-top:6px" class="pp-field pp-ltr"></select>
							<p class="description"><?php echo esc_html__( 'Save your key first, then load the list — it comes from the provider, so it is never out of date.', 'presspilot' ); ?></p></td></tr>
						<tr><th scope="row"><label for="pp_base_url"><?php echo esc_html__( 'API base URL', 'presspilot' ); ?></label></th><td>
							<input type="text" name="pp_base_url" id="pp_base_url" class="regular-text code pp-field" value="<?php echo esc_attr( $config['base_url'] ); ?>">
							<p class="description"><?php echo esc_html__( 'Only change this for a self-hosted or gateway endpoint.', 'presspilot' ); ?></p></td></tr>
						<tr><th scope="row"><label for="pp_max_steps"><?php echo esc_html__( 'Step limit', 'presspilot' ); ?></label></th><td>
							<input type="number" name="pp_max_steps" id="pp_max_steps" min="1" max="40" value="<?php echo esc_attr( $config['max_steps'] ); ?>" class="small-text">
							<p class="description"><?php echo esc_html__( 'How many tool-calling rounds one request may take before the copilot stops and reports back.', 'presspilot' ); ?></p></td></tr>
					</table>
					<?php submit_button( __( 'Save provider', 'presspilot' ) ); ?>
				</form>
			</div>

			<div class="pp-card">
				<h2><span class="dashicons dashicons-format-chat"></span> <?php echo esc_html__( 'Chat', 'presspilot' ); ?></h2>
				<?php if ( ! $config['configured'] ) : ?>
					<div class="pp-warn"><?php echo esc_html__( 'Add an API key and a model above to start using the copilot.', 'presspilot' ); ?></div>
				<?php endif; ?>
				<div class="pp-warn">
					<?php echo esc_html__( 'The copilot can change this site for real — create and delete content, edit settings, install plugins.', 'presspilot' ); ?>
					<?php
					printf(
						/* translators: %s: link reading "Permissions". */
						esc_html__( 'It works within the %s you set. Review what it proposes.', 'presspilot' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=presspilot-permissions' ) ) . '">' . esc_html__( 'Permissions', 'presspilot' ) . '</a>'
					);
					?>
				</div>

				<div class="pp-chat" id="pp-chat"></div>
				<div class="pp-composer">
					<textarea id="pp-input" placeholder="<?php echo esc_attr__( 'e.g. Build an About page with a hero, three feature cards and a contact section — native blocks, matching the current theme.', 'presspilot' ); ?>" <?php disabled( ! $config['configured'] ); ?>></textarea>
					<div>
						<button type="button" class="button button-primary" id="pp-send" <?php disabled( ! $config['configured'] ); ?>><?php echo esc_html__( 'Send', 'presspilot' ); ?></button><br>
						<button type="button" class="button" id="pp-reset" style="margin-top:6px"><?php echo esc_html__( 'Clear', 'presspilot' ); ?></button>
					</div>
				</div>
				<p class="description"><?php echo esc_html__( 'Enter sends · Shift+Enter adds a line · the conversation lives in this browser tab only.', 'presspilot' ); ?></p>
			</div>

			<script>
			(function () {
				var REST = <?php echo wp_json_encode( $rest_url ); ?>;
				var KEY = <?php echo wp_json_encode( $key ); ?>;
				var MAX = <?php echo (int) $config['max_steps']; ?>;
				var L = <?php echo wp_json_encode( $this->js_strings() ); ?>;
				var chat = document.getElementById('pp-chat');
				var input = document.getElementById('pp-input');
				var send = document.getElementById('pp-send');
				var messages = [];
				var busy = false;

				function el(cls, html) {
					var d = document.createElement('div');
					d.className = cls;
					if (html !== undefined) { d.innerHTML = html; }
					return d;
				}
				function esc(s) {
					return String(s === undefined || s === null ? '' : s)
						.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
				}
				function bubble(who, cls) {
					var wrap = el('pp-msg ' + cls);
					wrap.appendChild(el('pp-who', esc(who)));
					var body = el('pp-body');
					wrap.appendChild(body);
					chat.appendChild(wrap);
					chat.scrollTop = chat.scrollHeight;
					return body;
				}
				function status(text) {
					var body = bubble('', 'status');
					body.innerHTML = '<em style="color:#787c82">' + esc(text) + '</em>';
					return body.parentNode;
				}
				function toolLine(call) {
					var line = el('pp-tool' + (call.ok ? '' : ' err'));
					line.innerHTML = (call.ok ? '✓ ' : '✕ ') + '<b>' + esc(call.name) + '</b> '
						+ '<span class="pp-sum">' + esc(call.summary) + '</span>';
					return line;
				}

				async function step(payload) {
					var res = await fetch(REST + '/agent/step', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-PressPilot-Key': KEY },
						body: JSON.stringify(payload)
					});
					var data = await res.json();
					if (!res.ok) { throw new Error((data && data.message) || ('HTTP ' + res.status)); }
					return data;
				}

				async function turn(prompt) {
					if (busy) { return; }
					busy = true;
					send.disabled = true;
					bubble(L.you, 'user').textContent = prompt;
					var thinking = status(L.working);

					try {
						var payload = { prompt: prompt, messages: messages };
						for (var i = 0; i < MAX; i++) {
							var data = await step(payload);
							messages = data.messages;
							payload = { messages: messages };

							if (data.text) { bubble(L.copilot, 'assistant').textContent = data.text; }
							if (data.tool_calls && data.tool_calls.length) {
								var body = bubble(L.tools, 'tools');
								data.tool_calls.forEach(function (call) { body.appendChild(toolLine(call)); });
							}
							if (data.done) { break; }
						}
					} catch (e) {
						bubble(L.error, 'error').innerHTML = '<span style="color:#d63638">' + esc(e.message) + '</span>';
					} finally {
						thinking.remove();
						busy = false;
						send.disabled = false;
						input.focus();
					}
				}

				send.addEventListener('click', function () {
					var text = input.value.trim();
					if (!text) { return; }
					input.value = '';
					turn(text);
				});
				input.addEventListener('keydown', function (e) {
					if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send.click(); }
				});
				document.getElementById('pp-reset').addEventListener('click', function () {
					messages = [];
					chat.innerHTML = '';
				});

				// Provider picker: keep the base URL and "get a key" link in step with it.
				var providerSelect = document.getElementById('pp_provider');
				if (providerSelect) {
					providerSelect.addEventListener('change', function () {
						var opt = providerSelect.selectedOptions[0];
						document.getElementById('pp_base_url').value = opt.dataset.base || '';
						document.getElementById('pp-provider-note').textContent = opt.dataset.note || '';
						var link = document.getElementById('pp-keys-link');
						if (link && opt.dataset.keys) { link.href = opt.dataset.keys; }
					});
				}

				var loadModels = document.getElementById('pp-load-models');
				if (loadModels) {
					loadModels.addEventListener('click', async function () {
						loadModels.disabled = true;
						loadModels.textContent = L.loading;
						try {
							var res = await fetch(REST + '/agent/models', { headers: { 'X-PressPilot-Key': KEY } });
							var data = await res.json();
							if (!res.ok) { throw new Error(data.message || ('HTTP ' + res.status)); }
							var list = document.getElementById('pp-model-list');
							list.innerHTML = '<option value="">' + esc(L.pickModel) + '</option>';
							data.models.forEach(function (id) {
								var o = document.createElement('option');
								o.value = id; o.textContent = id;
								list.appendChild(o);
							});
							list.style.display = '';
							list.addEventListener('change', function () {
								if (list.value) { document.getElementById('pp_model').value = list.value; }
							});
							loadModels.textContent = L.nModels.replace('%d', data.models.length);
						} catch (e) {
							loadModels.textContent = L.failed.replace('%s', e.message);
						} finally {
							loadModels.disabled = false;
						}
					});
				}
			})();
			</script>

			<?php $this->footer_note(); ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Permissions                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Permissions screen: toggle which capability groups the API key may use.
	 */
	public function render_permissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$scopes = PP_Auth::get_scopes();
		$labels = PP_Auth::scope_labels();
		$this->styles();
		?>
		<div class="wrap pp-wrap">
			<?php $this->hero( __( 'Permissions', 'presspilot' ) ); ?>

			<?php if ( isset( $_GET['pp_scopes_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Permissions saved.', 'presspilot' ); ?></p></div>
			<?php endif; ?>

			<div class="pp-card">
				<h2><span class="dashicons dashicons-lock"></span> <?php echo esc_html__( 'What the API is allowed to do', 'presspilot' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %s: the HTTP error returned, formatted as code. */
						esc_html__( 'Everything is enabled by default. Turn off any capability you do not want an AI agent to touch. Requests to a disabled capability get a %s, and its tools are hidden from the agent entirely.', 'presspilot' ),
						'<code>403 pp_scope_disabled</code>'
					);
					?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pp_save_scopes">
					<?php wp_nonce_field( 'pp_save_scopes' ); ?>

					<?php $api_on = PP_Auth::is_enabled(); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php echo esc_html__( 'API access', 'presspilot' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="pp_api_enabled" value="1" <?php checked( $api_on ); ?>>
									<strong><?php echo $api_on ? esc_html__( 'Enabled', 'presspilot' ) : esc_html__( 'Disabled', 'presspilot' ); ?></strong> — <?php echo esc_html__( 'master on/off switch for the whole REST API.', 'presspilot' ); ?>
								</label>
								<p class="description">
								<?php
								printf(
									/* translators: %s: the HTTP error returned, formatted as code. */
									esc_html__( 'When off, every API request is refused with %s. Use it to cut off access instantly.', 'presspilot' ),
									'<code>503 pp_api_disabled</code>'
								);
								?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'IP allow-list', 'presspilot' ); ?> <span style="color:#787c82"><?php echo esc_html__( '(optional)', 'presspilot' ); ?></span></th>
							<td>
								<textarea name="pp_allowed_ips" rows="3" class="large-text code" placeholder="203.0.113.5&#10;198.51.100.0/24"><?php echo esc_textarea( PP_Auth::get_allowed_ips() ); ?></textarea>
								<p class="description">
									<?php echo esc_html__( 'One IP address or CIDR range per line.', 'presspilot' ); ?>
									<strong><?php echo esc_html__( 'Leave empty to allow any IP.', 'presspilot' ); ?></strong>
									<?php
									printf(
										/* translators: %s: the HTTP error returned, formatted as code. */
										esc_html__( 'When set, only these addresses may use the API; others get %s.', 'presspilot' ),
										'<code>403 pp_ip_blocked</code>'
									);
									?>
									<?php
									printf(
										/* translators: %s: the visitor's current IP address, formatted as code. */
										esc_html__( 'Your current IP: %s', 'presspilot' ),
										'<code>' . esc_html( PP_Auth::client_ip() ) . '</code>'
									);
									?>
								</p>
							</td>
						</tr>
					</table>

					<h3 style="margin:6px 0 2px"><?php echo esc_html__( 'Capabilities', 'presspilot' ); ?></h3>
					<div class="pp-pill-row">
						<button type="button" class="button button-small" onclick="document.querySelectorAll('.pp-scope input').forEach(function(c){c.checked=true});"><?php echo esc_html__( 'Enable all', 'presspilot' ); ?></button>
						<button type="button" class="button button-small" onclick="document.querySelectorAll('.pp-scope input').forEach(function(c){c.checked=false});"><?php echo esc_html__( 'Disable all', 'presspilot' ); ?></button>
					</div>

					<div class="pp-scope-grid">
						<?php foreach ( $labels as $key => $label ) : ?>
							<label class="pp-scope">
								<input type="checkbox" name="pp_scopes[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( ! empty( $scopes[ $key ] ) ); ?>>
								<span>
									<span class="pp-scope-key"><?php echo esc_html( $key ); ?></span>
									<span class="pp-scope-desc"><?php echo esc_html( $label ); ?></span>
								</span>
							</label>
						<?php endforeach; ?>
					</div>

					<div class="pp-scope" style="border-color:#d63638;background:#fcf0f1;margin-top:14px;align-items:flex-start">
						<input type="checkbox" name="pp_allow_exec" value="1" <?php checked( '1' === (string) get_option( PP_Config::EXEC_OPTION, '0' ) ); ?>>
						<span>
							<span class="pp-scope-key" style="color:#d63638"><?php echo esc_html__( 'Allow code execution', 'presspilot' ); ?> (<code>/exec</code>)</span>
							<span class="pp-scope-desc">
								<?php echo esc_html__( 'Lets the API run arbitrary PHP — the universal fallback for configuring any plugin.', 'presspilot' ); ?>
								<strong><?php echo esc_html__( 'Off by default.', 'presspilot' ); ?></strong>
								<?php
								printf(
									/* translators: %s: the capability name, formatted as code. */
									esc_html__( 'Only enable it if you trust the API key holder completely; it is as powerful as installing a plugin. Requires the %s capability above.', 'presspilot' ),
									'<code>config</code>'
								);
								?>
							</span>
						</span>
					</div>

					<p class="description" style="margin-top:10px">
						<?php
						printf(
							/* translators: %s: a list of always-available endpoints, formatted as code. */
							esc_html__( 'Discovery routes (%s) are always available so an agent can inspect the site.', 'presspilot' ),
							// One LTR run, not five: on an RTL locale a sequence of separate
							// inline elements is laid out right-to-left, which would print the
							// list backwards even though each item reads correctly.
							'<span dir="ltr" class="pp-ltr"><code>/ping</code>, <code>/site</code>, <code>/skill</code>, <code>/openapi</code>, <code>/blocks</code></span>'
						);
						?>
					</p>

					<?php submit_button( __( 'Save permissions', 'presspilot' ) ); ?>
				</form>
			</div>

			<?php $this->footer_note(); ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* API & Docs                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Render the bundled Skill / API documentation.
	 */
	public function render_docs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$rest_url = rest_url( PP_REST_NS );
		$path     = PP_PATH . 'docs/SKILL.md';
		$md       = is_readable( $path ) ? file_get_contents( $path ) : __( 'Documentation file not found.', 'presspilot' );
		$this->styles();
		?>
		<div class="wrap pp-wrap">
			<?php $this->hero( __( 'API & Agent Skill', 'presspilot' ) ); ?>
			<div class="pp-card">
				<p>
					<?php
					printf(
						/* translators: %s: the endpoint URL, formatted as code. */
						esc_html__( 'Hand this to any AI agent so it can build your site through the plugin\'s REST API. It is also served at %s.', 'presspilot' ),
						'<code class="pp-ltr">' . esc_html( $rest_url . '/skill' ) . '</code>'
					);
					?>
					<?php echo esc_html__( 'The Skill is English on every locale: it is read by a language model, and translating it would make the model worse at the job.', 'presspilot' ); ?>
				</p>
				<div class="pp-links">
					<button type="button" class="button" onclick="var t=document.getElementById('pp-skill');t.select();document.execCommand('copy');this.textContent='<?php echo esc_js( __( 'Copied', 'presspilot' ) ); ?> ✓';"><?php echo esc_html__( 'Copy all', 'presspilot' ); ?></button>
					<a class="button" href="<?php echo esc_url( $rest_url . '/skill?format=markdown' ); ?>" target="_blank"><?php echo esc_html__( 'Open raw Skill', 'presspilot' ); ?></a>
					<a class="button button-primary" href="<?php echo esc_url( $rest_url . '/openapi' ); ?>" target="_blank"><?php echo esc_html__( 'OpenAPI spec (JSON)', 'presspilot' ); ?></a>
				</div>
				<textarea id="pp-skill" readonly onclick="this.select()"
					style="width:100%;height:620px;white-space:pre;overflow:auto;" class="pp-mono"><?php echo esc_textarea( $md ); ?></textarea>
			</div>

			<?php $this->footer_note(); ?>
		</div>
		<?php
	}
}
