<?php
/**
 * Plugin adapters — curated, self-describing connectors for popular plugins whose
 * configuration needs their own PHP API (not just an option write). This extends the
 * same pattern as the builder adapters (PP_Elementor / PP_Gutenberg / PP_FSE): the core
 * stays generic (see PP_Config) and each adapter exposes a small, typed, whitelisted set
 * of actions. No arbitrary code execution — only registered actions run, and only when
 * the target plugin is active.
 *
 * Add an adapter by adding an entry to self::adapters(): a `active` probe and an
 * `actions` map of action-name => [ 'desc' => …, 'run' => callable( $args ) ].
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Adapters {

	/**
	 * The adapter registry. Each adapter is only usable when its `active` probe is true.
	 *
	 * @return array
	 */
	private static function adapters() {
		return array(
			'polylang' => array(
				'name'   => 'Polylang',
				'active' => function () {
					return function_exists( 'pll_languages_list' ) || class_exists( 'Polylang' );
				},
				'actions' => array(
					'add_language' => array(
						'desc' => 'Create a language. args: { locale (e.g. "fa_IR"), name, slug, rtl (0|1), flag, term_group }.',
						'run'  => function ( $args ) {
							if ( ! function_exists( 'PLL' ) || ! PLL() || ! isset( PLL()->model ) ) {
								return PP_Helpers::error( 'pp_polylang_unready', 'Polylang model unavailable.', 500 );
							}
							$locale = isset( $args['locale'] ) ? (string) $args['locale'] : '';
							if ( '' === $locale ) {
								return PP_Helpers::error( 'pp_missing_locale', 'A "locale" is required (e.g. fa_IR).', 400 );
							}
							$slug = isset( $args['slug'] ) ? sanitize_key( $args['slug'] ) : strtolower( substr( $locale, 0, 2 ) );
							$data = array(
								'name'       => isset( $args['name'] ) ? (string) $args['name'] : $locale,
								'slug'       => $slug,
								'locale'     => $locale,
								'rtl'        => isset( $args['rtl'] ) ? (int) $args['rtl'] : 0,
								'flag'       => isset( $args['flag'] ) ? (string) $args['flag'] : $slug,
								'term_group' => isset( $args['term_group'] ) ? (int) $args['term_group'] : 0,
							);
							$res = PLL()->model->add_language( $data );
							if ( is_wp_error( $res ) ) {
								return $res;
							}
							return array( 'added' => true, 'language' => $data );
						},
					),
					'set_post_language' => array(
						'desc' => 'Assign a post/page to a language. args: { post_id, lang (slug) }.',
						'run'  => function ( $args ) {
							if ( ! function_exists( 'pll_set_post_language' ) ) {
								return PP_Helpers::error( 'pp_polylang_unready', 'Polylang API unavailable.', 500 );
							}
							$post_id = (int) ( isset( $args['post_id'] ) ? $args['post_id'] : 0 );
							$lang    = isset( $args['lang'] ) ? sanitize_key( $args['lang'] ) : '';
							if ( $post_id <= 0 || '' === $lang ) {
								return PP_Helpers::error( 'pp_bad_args', 'Provide "post_id" and "lang".', 400 );
							}
							pll_set_post_language( $post_id, $lang );
							return array( 'ok' => true, 'post_id' => $post_id, 'lang' => $lang );
						},
					),
					'link_translations' => array(
						'desc' => 'Link posts as translations of each other. args: { translations: { lang: post_id, … } }.',
						'run'  => function ( $args ) {
							if ( ! function_exists( 'pll_save_post_translations' ) ) {
								return PP_Helpers::error( 'pp_polylang_unready', 'Polylang API unavailable.', 500 );
							}
							$t = isset( $args['translations'] ) && is_array( $args['translations'] ) ? array_map( 'intval', $args['translations'] ) : array();
							if ( count( $t ) < 2 ) {
								return PP_Helpers::error( 'pp_bad_args', 'Provide "translations" as { lang: post_id } with 2+ entries.', 400 );
							}
							pll_save_post_translations( $t );
							return array( 'ok' => true, 'translations' => $t );
						},
					),
				),
			),
		);
	}

	/** List adapters and their actions, flagging which are usable right now. */
	public static function listing() {
		$out = array();
		foreach ( self::adapters() as $slug => $a ) {
			$active  = (bool) call_user_func( $a['active'] );
			$actions = array();
			foreach ( $a['actions'] as $name => $def ) {
				$actions[ $name ] = $def['desc'];
			}
			$out[] = array(
				'slug'      => $slug,
				'name'      => $a['name'],
				'available' => $active,
				'actions'   => $actions,
			);
		}
		return array( 'adapters' => $out );
	}

	/** Run a whitelisted adapter action (only when its plugin is active). */
	public static function run( $slug, $action, $args ) {
		$slug     = sanitize_key( $slug );
		$action   = sanitize_key( $action );
		$adapters = self::adapters();
		if ( empty( $adapters[ $slug ] ) ) {
			return PP_Helpers::error( 'pp_adapter_unknown', 'Unknown adapter. See GET /adapters.', 404 );
		}
		$a = $adapters[ $slug ];
		if ( ! call_user_func( $a['active'] ) ) {
			return PP_Helpers::error( 'pp_adapter_inactive', sprintf( 'The %s plugin is not active.', $a['name'] ), 409 );
		}
		if ( empty( $a['actions'][ $action ] ) ) {
			return PP_Helpers::error( 'pp_action_unknown', 'Unknown action for this adapter. See GET /adapters.', 404 );
		}
		return call_user_func( $a['actions'][ $action ]['run'], is_array( $args ) ? $args : array() );
	}
}
