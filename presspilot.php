<?php
/**
 * Plugin Name:       PressPilot
 * Plugin URI:        https://bobclub.ir
 * Description:        An AI copilot for WordPress. Connect Claude Code, OpenAI Codex, OpenRouter or AgentRouter straight to your site over MCP — or use the built-in copilot — and let an agent build and manage everything: pages, posts, blocks, themes, menus, templates, media, settings and plugin configuration.
 * Version:           2.2.1
 * Author:            Baabak Majd
 * Author URI:        https://bobclub.ir
 * Text Domain:       presspilot
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

define( 'PP_VERSION', '2.2.1' );
define( 'PP_PRODUCT', 'PressPilot' );
define( 'PP_TAGLINE', 'AI copilot for WordPress' );
define( 'PP_FILE', __FILE__ );
define( 'PP_PATH', plugin_dir_path( __FILE__ ) );
define( 'PP_URL', plugin_dir_url( __FILE__ ) );
define( 'PP_REST_NS', 'presspilot/v1' );

require_once PP_PATH . 'includes/class-pp-helpers.php';
require_once PP_PATH . 'includes/class-pp-auth.php';
require_once PP_PATH . 'includes/class-pp-elementor.php';
require_once PP_PATH . 'includes/class-pp-gutenberg.php';
require_once PP_PATH . 'includes/class-pp-site.php';
require_once PP_PATH . 'includes/class-pp-menus.php';
require_once PP_PATH . 'includes/class-pp-plugins.php';
require_once PP_PATH . 'includes/class-pp-fse.php';
require_once PP_PATH . 'includes/class-pp-assets.php';
require_once PP_PATH . 'includes/class-pp-perf.php';
require_once PP_PATH . 'includes/class-pp-forms.php';
require_once PP_PATH . 'includes/class-pp-config.php';
require_once PP_PATH . 'includes/class-pp-db.php';
require_once PP_PATH . 'includes/class-pp-adapters.php';
require_once PP_PATH . 'includes/class-pp-tools.php';
require_once PP_PATH . 'includes/class-pp-mcp.php';
require_once PP_PATH . 'includes/class-pp-providers.php';
require_once PP_PATH . 'includes/class-pp-agent.php';
require_once PP_PATH . 'includes/class-pp-rest.php';
require_once PP_PATH . 'includes/class-pp-admin.php';

/**
 * On activation: make sure an API key exists so the user can copy it immediately.
 */
function pp_activate() {
	PP_Auth::maybe_generate_key();
}
register_activation_hook( __FILE__, 'pp_activate' );

/**
 * Load the dashboard translations.
 *
 * The language comes from WordPress alone — the site language in Settings, or a
 * per-user language on the profile screen. The plugin adds no rewrite rules, no
 * query parameters and no URL segments of its own, so translating it changes
 * nothing about the addresses of this site. Right-to-left layout follows the
 * same source: WordPress marks the admin `dir="rtl"` for an RTL locale and the
 * plugin's stylesheet is written with logical properties, so it mirrors on its
 * own with no separate RTL stylesheet to load.
 *
 * Hooked to `init` rather than `plugins_loaded`: translations must not be
 * requested before WordPress has settled the locale, and every string here is
 * used on an admin screen, long after this point.
 */
function pp_load_textdomain() {
	load_plugin_textdomain( 'presspilot', false, dirname( plugin_basename( PP_FILE ) ) . '/languages' );
}
add_action( 'init', 'pp_load_textdomain' );

/**
 * Boot the plugin.
 */
function pp_boot() {
	PP_Auth::instance();
	PP_REST::instance();
	if ( is_admin() ) {
		PP_Admin::instance();
	}
	// When Google Fonts are disabled (for a fully-local site), reliably stop
	// Elementor from printing any external font <link> at render time.
	if ( '0' === (string) get_option( 'elementor_google_fonts', '1' ) ) {
		add_filter( 'elementor/frontend/print_google_fonts', '__return_false' );
	}
}
add_action( 'plugins_loaded', 'pp_boot' );
