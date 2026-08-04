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
		</style>
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
		return
"You are an autonomous web developer connected to a WordPress site through the {$product} API.

Connection
- API base: {$rest_url}
- Auth header on EVERY request: X-PressPilot-Key: {$key}

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

			<div class="pp-card">
				<h2><span class="pp-step">1</span> Give your agent this prompt</h2>
				<p class="description">Paste it into your AI agent (Claude, etc.). It already contains your live URL and API key.</p>
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
				<?php echo esc_html( $product ); ?> v<?php echo esc_html( defined( 'PP_VERSION' ) ? PP_VERSION : '' ); ?> · GPL-2.0-or-later ·
				<a href="https://bobclub.ir" target="_blank" rel="noopener">bobclub.ir</a> ·
				<a href="https://t.me/bob_club" target="_blank" rel="noopener">Telegram</a> ·
				Enjoying it? <a href="https://bobclub.ir/coffee" target="_blank" rel="noopener">Buy me a coffee ☕</a>
			</p>
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
