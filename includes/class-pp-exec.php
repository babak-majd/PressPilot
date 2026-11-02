<?php
/**
 * Universal escape hatch — run arbitrary PHP inside WordPress.
 *
 * This file ships ONLY in the full (direct-download) build of PressPilot, never
 * in the WordPress.org build: the plugin directory forbids eval(). The main
 * plugin require_once's it only when the file is present, and the `wp_exec_php`
 * tool and REST handler register only when this class exists, so the .org build
 * behaves exactly as if the feature were never written.
 *
 * Access is doubly gated: the /exec endpoint needs the plugin's own
 * capability + API-key auth, and the feature itself stays OFF until an admin
 * opts in (option presspilot_allow_exec = '1', or the PP_ALLOW_EXEC constant).
 * The API key already grants plugin installation — i.e. code execution — so
 * this is a convenience of equal power, not a new trust boundary.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Exec {

	/**
	 * Evaluate PHP and return its value + echoed output.
	 *
	 * @param array $args { code }.
	 * @return array|WP_Error
	 */
	public static function run( $args ) {
		if ( ! PP_Config::exec_enabled() ) {
			return PP_Helpers::error( 'pp_exec_disabled', 'Code execution is disabled. Enable it on the PressPilot Permissions screen (or define PP_ALLOW_EXEC) before using /exec.', 403 );
		}
		$code = isset( $args['code'] ) ? (string) $args['code'] : '';
		if ( '' === $code ) {
			return PP_Helpers::error( 'pp_no_code', 'Provide "code" (PHP, without opening tags).', 400 );
		}
		ob_start();
		$value = null;
		$error = null;
		try {
			$value = eval( $code ); // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found, Squiz.PHP.Eval.Discouraged -- intentional admin-gated escape hatch; shipped only outside WordPress.org.
		} catch ( \Throwable $e ) {
			$error = $e->getMessage();
		}
		$echoed = ob_get_clean();
		return array(
			'return' => $value,
			'output' => $echoed,
			'error'  => $error,
		);
	}
}
