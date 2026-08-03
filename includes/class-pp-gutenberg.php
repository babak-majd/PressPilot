<?php
/**
 * Gutenberg / block-editor service.
 *
 * Everything needed to build a site with **native WordPress blocks** (no page
 * builder dependency) lives here: writing trusted block markup, turning a
 * structured block tree into valid block markup, and discovering the block and
 * pattern vocabulary available on the site.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Gutenberg {

	/**
	 * Run a callback with WordPress' KSES content filters disabled, then restore
	 * them. The REST layer is already gated by the secret API key, so writes made
	 * through it are trusted — this lets full block markup (group blocks with
	 * `<style>`, inline SVG, `<script>`-free embeds, etc.) round-trip intact
	 * instead of being silently stripped as it is for a capability-less user.
	 *
	 * @param callable $cb Callback to run.
	 * @return mixed Whatever the callback returns.
	 */
	public static function without_kses( $cb ) {
		$had = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		kses_remove_filters();
		try {
			return $cb();
		} finally {
			if ( false !== $had ) {
				kses_init_filters();
			}
		}
	}

	/**
	 * Strip orphan `<style>`/`<script>` blocks whose text KSES would otherwise
	 * dump as visible plain text in the middle of the page. Used only on the
	 * non-trusted path (when the caller opted out of unfiltered writing).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function strip_orphan_style( $html ) {
		return (string) preg_replace( '#<(style|script)\b[^>]*>.*?</\1>#is', '', (string) $html );
	}

	/**
	 * Convert a simplified block tree into canonical Gutenberg block markup.
	 *
	 * Each node: {
	 *   "blockName": "core/paragraph",   // required
	 *   "attrs":     { ... },            // optional block attributes (JSON)
	 *   "innerHTML": "<p>Hi</p>",        // optional saved HTML for this block
	 *   "innerBlocks": [ ...nodes ]      // optional children
	 * }
	 *
	 * Returns a block-markup string ready to store in post_content, so an agent
	 * can send structured data instead of hand-writing `<!-- wp:… -->` delimiters
	 * (which break the editor if the HTML inside doesn't match exactly).
	 *
	 * @param array $tree List of block nodes.
	 * @return string
	 */
	public static function serialize_tree( $tree ) {
		if ( ! is_array( $tree ) ) {
			return '';
		}
		$blocks = array();
		foreach ( $tree as $node ) {
			$block = self::to_wp_block( $node );
			if ( null !== $block ) {
				$blocks[] = $block;
			}
		}
		if ( ! function_exists( 'serialize_blocks' ) ) {
			require_once ABSPATH . WPINC . '/blocks.php';
		}
		return serialize_blocks( $blocks );
	}

	/**
	 * Normalise one simplified node into the shape serialize_blocks() expects.
	 *
	 * @param array $node Simplified block node.
	 * @return array|null
	 */
	private static function to_wp_block( $node ) {
		if ( ! is_array( $node ) || empty( $node['blockName'] ) ) {
			return null;
		}
		$inner_html   = isset( $node['innerHTML'] ) ? (string) $node['innerHTML'] : '';
		$inner_blocks = array();
		if ( ! empty( $node['innerBlocks'] ) && is_array( $node['innerBlocks'] ) ) {
			foreach ( $node['innerBlocks'] as $child ) {
				$block = self::to_wp_block( $child );
				if ( null !== $block ) {
					$inner_blocks[] = $block;
				}
			}
		}

		// inner_content interleaves literal HTML chunks (strings) with child-block
		// placeholders (null). To let a container block (core/group, core/columns,
		// core/cover…) actually *wrap* its children in a real element, a node may
		// provide `innerContentOpen` / `innerContentClose` (the wrapper's opening
		// and closing markup); children are then placed *between* them:
		//   [open, null, null, …, close]
		// Without them we keep the simple behavior (innerHTML then children), which
		// is correct for leaf/simple blocks. This is the fix for wrapper blocks that
		// previously collapsed because the wrapper couldn't surround its children.
		$open  = isset( $node['innerContentOpen'] ) ? (string) $node['innerContentOpen'] : '';
		$close = isset( $node['innerContentClose'] ) ? (string) $node['innerContentClose'] : '';

		$inner_content = array();
		if ( '' !== $open || '' !== $close ) {
			if ( '' !== $open ) {
				$inner_content[] = $open;
			}
			foreach ( $inner_blocks as $unused ) {
				$inner_content[] = null;
			}
			if ( '' !== $close ) {
				$inner_content[] = $close;
			}
			// innerHTML for a serialized block is the concatenation of its literal chunks.
			$inner_html = $open . $close;
		} else {
			if ( '' !== $inner_html ) {
				$inner_content[] = $inner_html;
			}
			foreach ( $inner_blocks as $unused ) {
				$inner_content[] = null;
			}
		}

		return array(
			'blockName'    => (string) $node['blockName'],
			'attrs'        => isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array(),
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		);
	}

	/**
	 * Validate a block-markup string by parsing it and reporting the block names
	 * found plus any parse anomalies. Non-fatal — purely informational.
	 *
	 * @param string $markup Block markup.
	 * @return array
	 */
	public static function validate_markup( $markup ) {
		if ( ! function_exists( 'parse_blocks' ) ) {
			require_once ABSPATH . WPINC . '/blocks.php';
		}
		$parsed = parse_blocks( (string) $markup );
		$names  = array();
		$freeform = 0;
		foreach ( $parsed as $block ) {
			if ( empty( $block['blockName'] ) ) {
				// A non-empty freeform chunk means stray HTML outside any block.
				if ( '' !== trim( (string) $block['innerHTML'] ) ) {
					$freeform++;
				}
				continue;
			}
			$names[] = $block['blockName'];
		}
		return array(
			'valid'         => true,
			'block_count'   => count( $names ),
			'blocks'        => array_values( array_unique( $names ) ),
			'freeform_html' => $freeform,
		);
	}

	/**
	 * List the block types registered on the site (native + any from plugins),
	 * so an agent knows the exact vocabulary it can use.
	 *
	 * @return array
	 */
	public static function list_block_types() {
		$out = array();
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return $out;
		}
		foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			$out[] = array(
				'name'       => $name,
				'title'      => isset( $type->title ) ? $type->title : '',
				'category'   => isset( $type->category ) ? $type->category : '',
				'attributes' => is_array( $type->attributes ) ? array_keys( $type->attributes ) : array(),
				'is_core'    => 0 === strpos( $name, 'core/' ),
			);
		}
		return $out;
	}

	/**
	 * List registered block patterns (reusable layout starting points).
	 *
	 * @return array
	 */
	public static function list_patterns() {
		$out = array();
		if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
			return $out;
		}
		foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			$out[] = array(
				'name'        => isset( $pattern['name'] ) ? $pattern['name'] : '',
				'title'       => isset( $pattern['title'] ) ? $pattern['title'] : '',
				'categories'  => isset( $pattern['categories'] ) ? $pattern['categories'] : array(),
				'description' => isset( $pattern['description'] ) ? $pattern['description'] : '',
			);
		}
		return $out;
	}

	/**
	 * Register a reusable pattern as a wp_block (synced pattern / reusable block)
	 * so it can be inserted by reference across pages.
	 *
	 * @param array $args title, content (block markup) or blocks (tree).
	 * @return array|WP_Error
	 */
	public static function create_pattern( $args ) {
		$title = isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : '';
		if ( '' === $title ) {
			return PP_Helpers::error( 'pp_missing_title', 'A pattern "title" is required.', 400 );
		}
		$content = '';
		if ( ! empty( $args['blocks'] ) && is_array( $args['blocks'] ) ) {
			$content = self::serialize_tree( $args['blocks'] );
		} elseif ( isset( $args['content'] ) ) {
			$content = (string) $args['content'];
		}

		$post_id = self::without_kses(
			function () use ( $title, $content ) {
				return wp_insert_post(
					array(
						'post_type'    => 'wp_block',
						'post_title'   => $title,
						'post_status'  => 'publish',
						'post_content' => $content,
					),
					true
				);
			}
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		return array(
			'id'        => (int) $post_id,
			'title'     => $title,
			'reference' => sprintf( '<!-- wp:block {"ref":%d} /-->', (int) $post_id ),
		);
	}
}
