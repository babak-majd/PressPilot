<?php
/**
 * Small shared helpers.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Helpers {

	/**
	 * Generate a unique 7-char hex id in the same style Elementor uses for elements.
	 *
	 * @return string
	 */
	public static function generate_element_id() {
		return substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
	}

	/**
	 * Walk an Elementor elements tree and make sure every node has a unique id
	 * and a settings object. Missing ids are the #1 reason a pasted layout does
	 * not render, so we repair the tree server-side.
	 *
	 * @param array $elements Elementor element nodes.
	 * @return array
	 */
	public static function ensure_ids( $elements ) {
		if ( ! is_array( $elements ) ) {
			return array();
		}

		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( empty( $element['id'] ) ) {
				$element['id'] = self::generate_element_id();
			}
			if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
				$element['settings'] = array();
			}
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::ensure_ids( $element['elements'] );
			} else {
				$element['elements'] = array();
			}
		}
		unset( $element );

		return array_values( $elements );
	}

	/**
	 * Standardised error response.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Human message.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	/**
	 * Slash a post array on its way into wp_insert_post()/wp_update_post().
	 *
	 * Both of those expect ALREADY-SLASHED data: they run wp_unslash() over the
	 * whole array, so anything handed to them raw silently loses one level of
	 * backslashes. Block markup depends on that level. serialize_block_attributes()
	 * escapes `--`, `<`, `>`, `&` and `"` inside the block comment as `\u002d`,
	 * `\u003c`, `\u003e`, `\u0026`, `\u0022` so the comment can never be closed
	 * early -- write that unslashed and `"className":"is-sec\u002d\u002dink"`
	 * lands in the database as `"className":"is-secu002du002dink"`, which the
	 * editor then parses as a literal class name with the `--` gone. The same
	 * applies to any JS, JSON or regex we write into post_content.
	 *
	 * @param array $postarr Post array with raw (unslashed) values.
	 * @return array Slashed copy, safe to pass to wp_insert_post()/wp_update_post().
	 */
	public static function slash_postarr( $postarr ) {
		return wp_slash( $postarr );
	}

	public static function error( $code, $message, $status = 400 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Is Elementor active?
	 *
	 * @return bool
	 */
	public static function elementor_active() {
		return did_action( 'elementor/loaded' ) || class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Is Elementor Pro active?
	 *
	 * @return bool
	 */
	public static function elementor_pro_active() {
		return function_exists( 'elementor_pro_load_plugin' ) || defined( 'ELEMENTOR_PRO_VERSION' );
	}
}
