<?php
/**
 * Plugin Name:       PressPilot
 * Plugin URI:        https://bobclub.ir
 * Description:        An AI copilot for WordPress. Exposes a secure REST API and a self-describing agent Skill so an AI agent can build and manage your whole site — pages, posts, blocks, themes, menus, templates, media and site settings.
 * Version:           1.7.1
 * Author:            Baabak Majd
 * Author URI:        https://bobclub.ir
 * Text Domain:       presspilot
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

define( 'PP_VERSION', '1.7.1' );
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
