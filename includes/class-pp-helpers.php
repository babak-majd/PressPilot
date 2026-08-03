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
