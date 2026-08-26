<?php
/**
 * Admin settings screens.
 *
 *  - Connect: the API key, REST base URL and a ready-to-paste agent prompt.
 *  - Permissions: per-capability toggles that gate what the API key may touch.
 *  - API & Docs: the bundled agent Skill / OpenAPI links.
 *
 * Styling stays inside the native WordPress admin (cards, form-tables, buttons);
 * only a small scoped stylesheet is added for polish.
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
		add_submenu_page( 'presspilot-settings', $product, 'Connect', 'manage_options', 'presspilot-settings', array( $this, 'render' ) );
		add_submenu_page( 'presspilot-settings', $product . ' — Agents (MCP)', 'Agents (MCP)', 'manage_options', 'presspilot-agents', array( $this, 'render_agents' ) );
		add_submenu_page( 'presspilot-settings', $product . ' — Copilot', 'Copilot', 'manage_options', 'presspilot-copilot', array( $this, 'render_copilot' ) );
		add_submenu_page( 'presspilot-settings', $product . ' — Permissions', 'Permissions', 'manage_options', 'presspilot-permissions', array( $this, 'render_permissions' ) );
		add_submenu_page( 'presspilot-settings', $product . ' — API & Skill', 'API &amp; Docs', 'manage_options', 'presspilot-docs', array( $this, 'render_docs' ) );
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
			.pp-ver{display:inline-block;font-size:12px;font-weight:600;color:#2271b1;background:#f0f6fc;border:1px solid #c5d9ed;border-radius:999px;padding:1px 9px;vertical-align:middle}
			.pp-tag{color:#646970;font-size:13px}
			.pp-sec{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 14px;border:1px solid #e0e0e0;border-radius:8px;background:#fafafa;margin:4px 0 12px}
			.pp-sec .dashicons{color:#2271b1}
			.pp-dot{display:inline-block;width:8px;height:8px;border-radius:50%;vertical-align:middle;margin-right:4px}
			.pp-dot.on{background:#00a32a}.pp-dot.off{background:#d63638}
			.pp-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 20px;margin:16px 0;box-shadow:0 1px 2px rgba(0,0,0,.04)}
			.pp-card h2{margin-top:0;font-size:15px;display:flex;align-items:center;gap:8px}
			.pp-step{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#2271b1;color:#fff;font-size:12px;font-weight:600}
			.pp-field{width:520px;max-width:100%}
			.pp-mono{font-family:Menlo,Consolas,monospace}
			.pp-scope-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 24px;margin:8px 0 4px}
			@media(max-width:782px){.pp-scope-grid{grid-template-columns:1fr}}
			.pp-scope{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid #e0e0e0;border-radius:6px;background:#fafafa}
			.pp-scope input{margin-top:2px}
			.pp-scope .pp-scope-key{font-weight:600;text-transform:capitalize}
			.pp-scope .pp-scope-desc{color:#646970;font-size:12px;display:block;margin-top:2px}
			.pp-badge{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:999px}
			.pp-badge.on{background:#edfaef;color:#00794f;border:1px solid #b8e6c8}
			.pp-badge.off{background:#fbeaea;color:#b32d2e;border:1px solid #f0c4c4}
			.pp-pill-row{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0}
			.pp-links .button{margin-right:6px}
			.pp-tabs{display:flex;gap:4px;flex-wrap:wrap;border-bottom:1px solid #dcdcde;margin:10px 0 0}
			.pp-tab{background:none;border:1px solid transparent;border-bottom:none;padding:8px 14px;cursor:pointer;font-size:13px;font-weight:600;color:#50575e;border-radius:6px 6px 0 0}
			.pp-tab[aria-selected="true"]{background:#fff;border-color:#dcdcde;color:#1d2327;margin-bottom:-1px}
			.pp-panel{display:none;padding:14px 0 0}
			.pp-panel.is-active{display:block}
			.pp-code{position:relative;background:#1d2327;color:#f0f0f1;border-radius:6px;padding:14px 16px;overflow:auto;font-family:Menlo,Consolas,monospace;font-size:12.5px;line-height:1.6;white-space:pre;margin:8px 0}
			.pp-copy{position:absolute;top:8px;inset-inline-end:8px}
			.pp-chat{border:1px solid #dcdcde;border-radius:8px;background:#fff;height:460px;overflow-y:auto;padding:14px}
			.pp-msg{margin:0 0 14px;display:flex;gap:10px;align-items:flex-start}
			.pp-msg .pp-who{flex:0 0 62px;font-size:11px;font-weight:700;text-transform:uppercase;color:#787c82;padding-top:3px}
			.pp-msg .pp-body{flex:1;min-width:0;white-space:pre-wrap;word-wrap:break-word}
			.pp-msg.user .pp-body{background:#f0f6fc;border:1px solid #c5d9ed;border-radius:6px;padding:8px 12px}
			.pp-tool{font-family:Menlo,Consolas,monospace;font-size:12px;background:#f6f7f7;border:1px solid #e0e0e0;border-inline-start:3px solid #2271b1;border-radius:4px;padding:6px 10px;margin:4px 0}
			.pp-tool.err{border-inline-start-color:#d63638;background:#fcf0f1}
			.pp-tool b{font-weight:600}
			.pp-tool .pp-sum{color:#646970}
			.pp-composer{display:flex;gap:8px;margin-top:10px}
			.pp-composer textarea{flex:1;min-height:64px;font-family:inherit}
			.pp-warn{background:#fcf9e8;border:1px solid #f0e6b8;border-radius:6px;padding:10px 12px;margin:10px 0;font-size:13px}
		</style>
		<?php
	}

	/** A dark code block with a copy button. */
	private function code_block( $code, $id ) {
		?>
		<div class="pp-code"><button type="button" class="button button-small pp-copy"
			onclick="navigator.clipboard.writeText(document.getElementById('<?php echo esc_attr( $id ); ?>').textContent);this.textContent='Copied ✓';">Copy</button><span id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $code ); ?></span></div>
		<?php
	}

	private function hero( $sub = '' ) {
		$product = defined( 'PP_PRODUCT' ) ? PP_PRODUCT : 'PressPilot';
		$tagline = defined( 'PP_TAGLINE' ) ? PP_TAGLINE : '';
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
				<div class="pp-tag"><?php echo esc_html( $tagline ); ?></div>
			</div>
		</div>
		<?php
	}

	/**
	 * The ready-to-paste prompt the user hands to their AI agent.
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

	public function handle_regenerate() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'pp_regenerate' ) ) {
			wp_die( 'Not allowed.' );
		}
		PP_Auth::regenerate_key();
		wp_safe_redirect( add_query_arg( 'pp_regenerated', '1', admin_url( 'admin.php?page=presspilot-settings' ) ) );
		exit;
	}

	public function handle_save_scopes() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'pp_save_scopes' ) ) {
			wp_die( 'Not allowed.' );
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
			<?php $this->hero( 'Connect' ); ?>

			<?php if ( isset( $_GET['pp_regenerated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>A new API key was generated. Update it wherever you use it (the prompt below already includes the new key).</p></div>
			<?php endif; ?>

			<div class="pp-card" style="border-color:#c5d9ed;background:#f6fbff">
				<h2><span class="dashicons dashicons-rest-api"></span> New in 2.0 — connect an agent directly</h2>
				<p style="margin:0 0 10px">
					Claude Code, OpenAI Codex, Cursor and any other MCP client can now plug straight into this site
					and see it as native tools — no prompt to paste, no HTTP calls to write. Or connect a model
					(Anthropic, OpenAI, OpenRouter, AgentRouter) and run the copilot right here in wp-admin.
				</p>
				<div class="pp-links">
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=presspilot-agents' ) ); ?>">Agents (MCP) →</a>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=presspilot-copilot' ) ); ?>">Built-in Copilot →</a>
				</div>
			</div>

			<div class="pp-card">
				<h2><span class="pp-step">1</span> Give your agent this prompt</h2>
				<p class="description">For agents that don't speak MCP. Paste it into your AI agent — it already contains your live URL and API key.</p>
				<p>
					<button type="button" class="button button-primary"
						onclick="var t=document.getElementById('pp-prompt');t.select();document.execCommand('copy');this.textContent='Copied ✓';">Copy prompt</button>
				</p>
				<textarea id="pp-prompt" readonly onclick="this.select()"
					style="width:100%;height:280px;" class="pp-mono"><?php echo esc_textarea( $prompt ); ?></textarea>
			</div>

			<div class="pp-card">
				<h2><span class="pp-step">2</span> Connection details</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">REST base URL</th><td>
						<input type="text" class="regular-text code pp-field" readonly value="<?php echo esc_attr( $rest_url ); ?>" onclick="this.select()"></td></tr>
					<tr><th scope="row">API key <span style="color:#d63638">(secret)</span></th><td>
						<input type="text" class="regular-text code pp-field" readonly value="<?php echo esc_attr( $key ); ?>" onclick="this.select()">
						<p class="description">Header <code>X-PressPilot-Key</code>. Anyone with this key can edit everything the Permissions allow.</p></td></tr>
					<tr><th scope="row">Access &amp; security</th><td>
						<?php $api_on = PP_Auth::is_enabled(); $ips = trim( PP_Auth::get_allowed_ips() ); ?>
						<div class="pp-sec">
							<span class="dashicons dashicons-shield"></span>
							<span><span class="pp-dot <?php echo $api_on ? 'on' : 'off'; ?>"></span><strong>API <?php echo $api_on ? 'enabled' : 'disabled'; ?></strong></span>
							<span style="color:#dcdcde">|</span>
							<span>IP allow-list: <strong><?php echo $ips ? esc_html( count( preg_split( '/\s+/', $ips ) ) ) . ' address(es)' : 'off (any IP)'; ?></strong></span>
							<span style="color:#dcdcde">|</span>
							<span>Capabilities: <strong><?php echo empty( $off ) ? 'all enabled' : esc_html( count( $off ) ) . ' disabled'; ?></strong></span>
							<a class="button button-small" href="<?php echo esc_url( $perm_url ); ?>" style="margin-left:auto">Manage on Permissions →</a>
						</div>
						<p class="description">Turn the whole API on/off and restrict it to specific IPs on the <a href="<?php echo esc_url( $perm_url ); ?>"><strong>Permissions</strong></a> screen (menu: <em>PressPilot → Permissions</em>).</p>
					</td></tr>
					<?php $theme = wp_get_theme(); ?>
					<tr><th scope="row">Environment</th><td>
						WordPress: <strong><?php echo esc_html( get_bloginfo( 'version' ) ); ?></strong> &nbsp;|&nbsp;
						Theme: <strong><?php echo esc_html( $theme->get( 'Name' ) ); ?></strong> &nbsp;|&nbsp;
						Block theme: <strong><?php echo PP_FSE::is_block_theme() ? 'yes' : 'no'; ?></strong></td></tr>
				</table>

				<div class="pp-links">
					<a class="button" href="<?php echo esc_url( $rest_url . '/skill' ); ?>" target="_blank">Skill (rules)</a>
					<a class="button" href="<?php echo esc_url( $rest_url . '/openapi' ); ?>" target="_blank">OpenAPI spec</a>
					<a class="button button-primary" href="<?php echo esc_url( $docs_url ); ?>">Full documentation</a>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px"
					onsubmit="return confirm('Generate a new key? The old key will stop working immediately.');">
					<input type="hidden" name="action" value="pp_regenerate">
					<?php wp_nonce_field( 'pp_regenerate' ); ?>
					<?php submit_button( 'Regenerate API Key', 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<div class="pp-card">
				<h2>Quick test</h2>
				<p class="description">Run this from any terminal to confirm the connection works:</p>
				<textarea readonly class="code pp-mono" style="width:100%;height:56px;" onclick="this.select()">curl -s "<?php echo esc_url( $rest_url ); ?>/ping" -H "X-PressPilot-Key: <?php echo esc_attr( $key ); ?>"</textarea>
			</div>

			<p class="pp-tag" style="margin-top:6px">
				<?php echo esc_html( defined( 'PP_PRODUCT' ) ? PP_PRODUCT : 'PressPilot' ); ?> v<?php echo esc_html( defined( 'PP_VERSION' ) ? PP_VERSION : '' ); ?> · GPL-2.0-or-later ·
				<a href="https://bobclub.ir" target="_blank" rel="noopener">bobclub.ir</a> ·
				<a href="https://t.me/bob_club" target="_blank" rel="noopener">Telegram</a> ·
				Enjoying it? <a href="https://bobclub.ir/coffee" target="_blank" rel="noopener">Buy me a coffee ☕</a>
			</p>
		</div>
		<?php
	}

	public function handle_save_mcp() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'pp_save_mcp' ) ) {
			wp_die( 'Not allowed.' );
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
			wp_die( 'Not allowed.' );
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
			<?php $this->hero( 'Agents — direct MCP connection' ); ?>

			<?php if ( isset( $_GET['pp_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Saved.</p></div>
			<?php endif; ?>

			<div class="pp-card">
				<h2><span class="dashicons dashicons-rest-api"></span> Model Context Protocol endpoint</h2>
				<p class="description">
					MCP is the standard way an AI agent plugs into an external system. Point a client at this URL and
					your site shows up inside it as <strong><?php echo count( $tools ); ?> native tools</strong> —
					no prompt engineering, no hand-written HTTP calls. The agent Skill is delivered automatically on
					connect, so agents follow this site's build rules from their first message.
				</p>

				<div class="pp-sec">
					<span class="dashicons dashicons-<?php echo $on ? 'yes-alt' : 'dismiss'; ?>"></span>
					<span><span class="pp-dot <?php echo $on ? 'on' : 'off'; ?>"></span><strong>MCP <?php echo $on ? 'enabled' : 'disabled'; ?></strong></span>
					<span style="color:#dcdcde">|</span>
					<span>Transport: <strong>Streamable HTTP</strong></span>
					<span style="color:#dcdcde">|</span>
					<span>Tools exposed: <strong><?php echo count( $tools ); ?></strong> (<?php echo esc_html( PP_Tools::profile() ); ?> profile)</span>
				</div>

				<table class="form-table" role="presentation">
					<tr><th scope="row">Endpoint URL</th><td>
						<input type="text" class="regular-text code pp-field" readonly value="<?php echo esc_attr( $url ); ?>" onclick="this.select()"></td></tr>
					<tr><th scope="row">Authentication</th><td>
						<code>Authorization: Bearer <?php echo esc_html( $key ); ?></code>
						<p class="description">The same API key as the REST API. <code>X-PressPilot-Key</code> works too.</p></td></tr>
				</table>
			</div>

			<div class="pp-card">
				<h2><span class="pp-step">1</span> Connect your agent</h2>
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
					Once connected, try: <em>“Read the PressPilot skill, then build me a pricing page with native blocks.”</em>
				</p>
			</div>

			<div class="pp-card">
				<h2><span class="pp-step">2</span> Settings</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pp_save_mcp">
					<?php wp_nonce_field( 'pp_save_mcp' ); ?>
					<table class="form-table" role="presentation">
						<tr><th scope="row">MCP endpoint</th><td>
							<label><input type="checkbox" name="pp_mcp_enabled" value="1" <?php checked( $on ); ?>>
								<strong>Enabled</strong> — accept MCP connections at the URL above.</label>
							<p class="description">Turning this off leaves the REST API working; only the MCP endpoint stops answering.</p></td></tr>
						<tr><th scope="row">Tool surface</th><td>
							<?php $profile = PP_Tools::profile(); ?>
							<label style="display:block;margin-bottom:4px"><input type="radio" name="pp_tool_profile" value="full" <?php checked( 'full', $profile ); ?>>
								<strong>Full</strong> — every tool, including plugin configuration, database and adapters.</label>
							<label style="display:block"><input type="radio" name="pp_tool_profile" value="essential" <?php checked( 'essential', $profile ); ?>>
								<strong>Essential</strong> — the core building set only. Fewer tools means less context and fewer wrong turns on small models.</label>
							<p class="description">Tools for a capability you turned off on the <a href="<?php echo esc_url( admin_url( 'admin.php?page=presspilot-permissions' ) ); ?>">Permissions</a> screen are hidden either way.</p></td></tr>
						<tr><th scope="row">Key in the URL</th><td>
							<label><input type="checkbox" name="pp_mcp_url_key" value="1" <?php checked( PP_MCP::url_key_allowed() ); ?>>
								Allow <code>?key=…</code> as an alternative to the <code>Authorization</code> header.</label>
							<p class="description">
								For clients that accept only a URL. It also rescues setups where the server strips
								<code>Authorization</code> headers (common on Apache CGI). <strong>The key then travels in the URL</strong>,
								where server and proxy logs can record it — leave this off unless a client needs it.
							</p></td></tr>
					</table>
					<?php submit_button( 'Save MCP settings' ); ?>
				</form>
			</div>

			<div class="pp-card">
				<h2><span class="dashicons dashicons-admin-tools"></span> Tools currently exposed (<?php echo count( $tools ); ?>)</h2>
				<div class="pp-pill-row">
					<?php foreach ( $tools as $name => $tool ) : ?>
						<code title="<?php echo esc_attr( $tool['description'] ); ?>" style="background:#f0f6fc;border:1px solid #c5d9ed;border-radius:4px;padding:2px 7px;font-size:12px"><?php echo esc_html( $name ); ?></code>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

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
			<?php $this->hero( 'Copilot' ); ?>

			<?php if ( isset( $_GET['pp_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Copilot settings saved.</p></div>
			<?php endif; ?>

			<div class="pp-card">
				<h2><span class="dashicons dashicons-admin-users"></span> Model provider</h2>
				<p class="description">
					Connect a model and the copilot runs the same tools an external agent gets over MCP,
					under the same permissions and the same Skill — without leaving wp-admin.
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pp_save_agent">
					<?php wp_nonce_field( 'pp_save_agent' ); ?>
					<table class="form-table" role="presentation">
						<tr><th scope="row"><label for="pp_provider">Provider</label></th><td>
							<select name="pp_provider" id="pp_provider" class="pp-field">
								<?php foreach ( $providers as $slug => $provider ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $config['provider'] ); ?>
										data-base="<?php echo esc_attr( $provider['base_url'] ); ?>"
										data-keys="<?php echo esc_attr( $provider['keys_url'] ); ?>"
										data-note="<?php echo esc_attr( $provider['note'] ); ?>"><?php echo esc_html( $provider['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description" id="pp-provider-note"><?php echo esc_html( $providers[ $config['provider'] ]['note'] ); ?></p></td></tr>
						<tr><th scope="row"><label for="pp_api_key">API key</label></th><td>
							<input type="password" name="pp_api_key" id="pp_api_key" class="regular-text code pp-field" autocomplete="off"
								placeholder="<?php echo $config['api_key'] ? esc_attr( $config['api_key'] ) : 'sk-…'; ?>">
							<p class="description">
								Stored in this site's database and used only for these calls. Leave blank to keep the saved key.
								<?php if ( $providers[ $config['provider'] ]['keys_url'] ) : ?>
									<a href="<?php echo esc_url( $providers[ $config['provider'] ]['keys_url'] ); ?>" target="_blank" rel="noopener" id="pp-keys-link">Get a key →</a>
								<?php endif; ?>
							</p></td></tr>
						<tr><th scope="row"><label for="pp_model">Model</label></th><td>
							<input type="text" name="pp_model" id="pp_model" class="regular-text code pp-field" value="<?php echo esc_attr( $config['model'] ); ?>" placeholder="claude-opus-5">
							<button type="button" class="button" id="pp-load-models">Load available models</button>
							<select id="pp-model-list" style="display:none;margin-top:6px" class="pp-field"></select>
							<p class="description">Save your key first, then load the list — it comes from the provider, so it is never out of date.</p></td></tr>
						<tr><th scope="row"><label for="pp_base_url">API base URL</label></th><td>
							<input type="text" name="pp_base_url" id="pp_base_url" class="regular-text code pp-field" value="<?php echo esc_attr( $config['base_url'] ); ?>">
							<p class="description">Only change this for a self-hosted or gateway endpoint.</p></td></tr>
						<tr><th scope="row"><label for="pp_max_steps">Step limit</label></th><td>
							<input type="number" name="pp_max_steps" id="pp_max_steps" min="1" max="40" value="<?php echo esc_attr( $config['max_steps'] ); ?>" class="small-text">
							<p class="description">How many tool-calling rounds one request may take before the copilot stops and reports back.</p></td></tr>
					</table>
					<?php submit_button( 'Save provider' ); ?>
				</form>
			</div>

			<div class="pp-card">
				<h2><span class="dashicons dashicons-format-chat"></span> Chat</h2>
				<?php if ( ! $config['configured'] ) : ?>
					<div class="pp-warn">Add an API key and a model above to start using the copilot.</div>
				<?php endif; ?>
				<div class="pp-warn">
					The copilot can change this site for real — create and delete content, edit settings, install plugins.
					It works within the <a href="<?php echo esc_url( admin_url( 'admin.php?page=presspilot-permissions' ) ); ?>">Permissions</a> you set. Review what it proposes.
				</div>

				<div class="pp-chat" id="pp-chat"></div>
				<div class="pp-composer">
					<textarea id="pp-input" placeholder="e.g. Build an About page with a hero, three feature cards and a contact section — native blocks, matching the current theme." <?php disabled( ! $config['configured'] ); ?>></textarea>
					<div>
						<button type="button" class="button button-primary" id="pp-send" <?php disabled( ! $config['configured'] ); ?>>Send</button><br>
						<button type="button" class="button" id="pp-reset" style="margin-top:6px">Clear</button>
					</div>
				</div>
				<p class="description">Enter sends · Shift+Enter adds a line · the conversation lives in this browser tab only.</p>
			</div>

			<script>
			(function () {
				var REST = <?php echo wp_json_encode( $rest_url ); ?>;
				var KEY = <?php echo wp_json_encode( $key ); ?>;
				var MAX = <?php echo (int) $config['max_steps']; ?>;
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
					if (!res.ok) {
						throw new Error((data && data.message) || ('HTTP ' + res.status));
					}
					return data;
				}

				async function turn(prompt) {
					if (busy) { return; }
					busy = true;
					send.disabled = true;
					bubble('You', 'user').textContent = prompt;
					var thinking = status('Working…');

					try {
						var payload = { prompt: prompt, messages: messages };
						for (var i = 0; i < MAX; i++) {
							var data = await step(payload);
							messages = data.messages;
							payload = { messages: messages };

							if (data.text) {
								bubble('Copilot', 'assistant').textContent = data.text;
							}
							if (data.tool_calls && data.tool_calls.length) {
								var body = bubble('Tools', 'tools');
								data.tool_calls.forEach(function (call) { body.appendChild(toolLine(call)); });
							}
							if (data.done) { break; }
						}
					} catch (e) {
						var err = bubble('Error', 'error');
						err.innerHTML = '<span style="color:#d63638">' + esc(e.message) + '</span>';
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
						loadModels.textContent = 'Loading…';
						try {
							var res = await fetch(REST + '/agent/models', { headers: { 'X-PressPilot-Key': KEY } });
							var data = await res.json();
							if (!res.ok) { throw new Error(data.message || ('HTTP ' + res.status)); }
							var list = document.getElementById('pp-model-list');
							list.innerHTML = '<option value="">— pick a model —</option>';
							data.models.forEach(function (id) {
								var o = document.createElement('option');
								o.value = id; o.textContent = id;
								list.appendChild(o);
							});
							list.style.display = '';
							list.addEventListener('change', function () {
								if (list.value) { document.getElementById('pp_model').value = list.value; }
							});
							loadModels.textContent = data.models.length + ' models';
						} catch (e) {
							loadModels.textContent = 'Failed: ' + e.message;
						} finally {
							loadModels.disabled = false;
						}
					});
				}
			})();
			</script>
		</div>
		<?php
	}

	/**
	 * Permissions screen: toggle which capability groups the API key may use.
	 */
	public function render_permissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$scopes = PP_Auth::get_scopes();
		$this->styles();
		?>
		<div class="wrap pp-wrap">
			<?php $this->hero( 'Permissions' ); ?>

			<?php if ( isset( $_GET['pp_scopes_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Permissions saved.</p></div>
			<?php endif; ?>

			<div class="pp-card">
				<h2><span class="dashicons dashicons-lock"></span> What the API is allowed to do</h2>
				<p class="description">Everything is enabled by default. Turn off any capability you don't want the AI agent (API key) to touch. Requests to a disabled capability get a <code>403 pp_scope_disabled</code>.</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pp_save_scopes">
					<?php wp_nonce_field( 'pp_save_scopes' ); ?>

					<?php $api_on = PP_Auth::is_enabled(); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">API access</th>
							<td>
								<label>
									<input type="checkbox" name="pp_api_enabled" value="1" <?php checked( $api_on ); ?>>
									<strong><?php echo $api_on ? 'Enabled' : 'Disabled'; ?></strong> — master on/off switch for the whole REST API.
								</label>
								<p class="description">When off, every API request is refused with <code>503 pp_api_disabled</code>. Use it to instantly cut off access.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">IP allow-list <span style="color:#787c82">(optional)</span></th>
							<td>
								<textarea name="pp_allowed_ips" rows="3" class="large-text code" placeholder="e.g.&#10;203.0.113.5&#10;198.51.100.0/24"><?php echo esc_textarea( PP_Auth::get_allowed_ips() ); ?></textarea>
								<p class="description">One IP or CIDR range per line. <strong>Leave empty to allow any IP.</strong> When set, only these addresses may use the API (others get <code>403 pp_ip_blocked</code>). Your current IP: <code><?php echo esc_html( PP_Auth::client_ip() ); ?></code></p>
							</td>
						</tr>
					</table>

					<h3 style="margin:6px 0 2px">Capabilities</h3>
					<div class="pp-pill-row">
						<button type="button" class="button button-small" onclick="document.querySelectorAll('.pp-scope input').forEach(function(c){c.checked=true});">Enable all</button>
						<button type="button" class="button button-small" onclick="document.querySelectorAll('.pp-scope input').forEach(function(c){c.checked=false});">Disable all</button>
					</div>

					<div class="pp-scope-grid">
						<?php foreach ( PP_Auth::SCOPES as $key => $label ) : ?>
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
							<span class="pp-scope-key" style="color:#d63638">Allow code execution (<code>/exec</code>)</span>
							<span class="pp-scope-desc">Lets the API run arbitrary PHP — the universal fallback for configuring any plugin. <strong>Off by default.</strong> Only enable if you trust the API key holder completely; it is as powerful as installing a plugin. Requires the <code>config</code> scope above.</span>
						</span>
					</div>

					<p class="description" style="margin-top:10px">Discovery routes (<code>/ping</code>, <code>/site</code>, <code>/skill</code>, <code>/openapi</code>, <code>/widgets</code>, <code>/blocks</code>) are always available so an agent can inspect the site.</p>

					<?php submit_button( 'Save permissions' ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the bundled Skill / API documentation.
	 */
	public function render_docs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$rest_url = rest_url( PP_REST_NS );
		$path     = PP_PATH . 'docs/SKILL.md';
		$md       = is_readable( $path ) ? file_get_contents( $path ) : 'Documentation file not found.';
		$this->styles();
		?>
		<div class="wrap pp-wrap">
			<?php $this->hero( 'API & Agent Skill' ); ?>
			<div class="pp-card">
				<p>
					Hand this to any AI agent so it can build your site through the plugin's REST API.
					It is also served at <code><?php echo esc_html( $rest_url ); ?>/skill</code>
					(add <code>?format=markdown</code> for raw markdown).
				</p>
				<div class="pp-links">
					<button type="button" class="button" onclick="var t=document.getElementById('pp-skill');t.select();document.execCommand('copy');this.textContent='Copied!';">Copy all</button>
					<a class="button" href="<?php echo esc_url( $rest_url . '/skill?format=markdown' ); ?>" target="_blank">Open raw Skill</a>
					<a class="button button-primary" href="<?php echo esc_url( $rest_url . '/openapi' ); ?>" target="_blank">OpenAPI spec (JSON)</a>
				</div>
				<p class="description">Import the OpenAPI spec into Swagger, Postman or an SDK generator. Public URL: <code><?php echo esc_html( $rest_url ); ?>/openapi</code></p>
				<textarea id="pp-skill" readonly onclick="this.select()"
					style="width:100%;height:620px;white-space:pre;overflow:auto;" class="pp-mono"><?php echo esc_textarea( $md ); ?></textarea>
			</div>
		</div>
		<?php
	}
}
