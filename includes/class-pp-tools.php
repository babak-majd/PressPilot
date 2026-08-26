<?php
/**
 * The tool registry — one description of "what an agent can do here", shared by
 * every agent surface.
 *
 * A tool is a thin, self-describing wrapper around a PressPilot REST route: name,
 * JSON-Schema input, and the route to dispatch to. Calls go back through
 * rest_do_request(), so the existing handlers, validation and per-capability
 * scope gating stay the single source of truth — nothing is duplicated here.
 *
 * Consumed by:
 *  - PP_MCP    (Model Context Protocol server — Claude Code, Codex, any MCP client)
 *  - PP_Agent  (the built-in copilot driving Anthropic / OpenAI / OpenRouter / AgentRouter)
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Tools {

	/** Tool-surface size: 'full' = every tool, 'essential' = the core building set. */
	const OPTION_PROFILE = 'presspilot_tool_profile';

	/** Tool result payloads larger than this are truncated before reaching the model. */
	const MAX_RESULT_BYTES = 60000;

	/** @var array|null Memoised registry. */
	private static $registry = null;

	/* ------------------------------------------------------------------ */
	/* Schema shorthands                                                  */
	/* ------------------------------------------------------------------ */

	/** A JSON-Schema object node. Empty property maps must encode as {}, not []. */
	private static function obj( $props = array(), $required = array() ) {
		$schema = array(
			'type'       => 'object',
			'properties' => empty( $props ) ? new stdClass() : $props,
		);
		if ( ! empty( $required ) ) {
			$schema['required'] = array_values( $required );
		}
		return $schema;
	}

	private static function str( $desc, $extra = array() ) {
		return array_merge( array( 'type' => 'string', 'description' => $desc ), $extra );
	}

	private static function int( $desc ) {
		return array( 'type' => 'integer', 'description' => $desc );
	}

	private static function bool( $desc ) {
		return array( 'type' => 'boolean', 'description' => $desc );
	}

	private static function arr( $desc, $items = array( 'type' => 'string' ) ) {
		return array( 'type' => 'array', 'description' => $desc, 'items' => $items );
	}

	/** A free-form object (plugin settings, block attrs, …). */
	private static function map( $desc ) {
		return array( 'type' => 'object', 'description' => $desc, 'additionalProperties' => true );
	}

	/** The recursive-ish block node used by every content-writing tool. */
	private static function blocks_arg() {
		return array(
			'type'        => 'array',
			'description' => 'Structured Gutenberg block tree; the plugin serialises it to valid block markup so you never hand-write <!-- wp:… --> delimiters. Each node: {blockName, attrs, innerHTML, innerBlocks, innerContentOpen, innerContentClose}. Containers (core/group, core/columns, core/cover) need innerContentOpen/innerContentClose to wrap their children.',
			'items'       => array( 'type' => 'object', 'additionalProperties' => true ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* The registry                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Every tool this plugin can offer, keyed by tool name.
	 *
	 * Descriptor keys:
	 *   title       Human label.
	 *   description What the model reads to decide whether to call it.
	 *   scope       PP_Auth capability group gating it ('' = always available).
	 *   method      HTTP method to dispatch.
	 *   path        REST path under presspilot/v1; {placeholders} are filled from args.
	 *   schema      JSON Schema for the arguments.
	 *   read_only   True for tools that never change the site.
	 *   essential   True when the tool is part of the trimmed 'essential' profile.
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		if ( null !== self::$registry ) {
			return self::$registry;
		}

		$t = array();

		/* ---------------- Discovery (always available) ---------------- */

		$t['wp_get_skill'] = array(
			'title'       => 'Read the operating manual',
			'description' => 'Return the PressPilot Skill — the operating manual with the hard rules for building this site (block authoring, KSES limits, templates, RTL/multilingual, the plugin-configuration decision order). CALL THIS FIRST, before building or changing anything, and follow it exactly.',
			'scope'       => '',
			'method'      => 'GET',
			'path'        => '/skill',
			'schema'      => self::obj(),
			'read_only'   => true,
			'essential'   => true,
		);

		$t['wp_get_site'] = array(
			'title'       => 'Inspect the site',
			'description' => 'The environment: WordPress/PHP version, active theme (and whether it is a block/FSE theme), locale, timezone, installed plugins, Elementor status, and which capability scopes the administrator has enabled. Call this once at the start of a session and build accordingly.',
			'scope'       => '',
			'method'      => 'GET',
			'path'        => '/site',
			'schema'      => self::obj(),
			'read_only'   => true,
			'essential'   => true,
		);

		$t['wp_performance'] = array(
			'title'       => 'Performance report',
			'description' => 'Server and page-weight health: TTFB, opcache, object cache, page cache, gzip, autoloaded-option bloat. Use it to check a build did not make the site slow.',
			'scope'       => '',
			'method'      => 'GET',
			'path'        => '/performance',
			'schema'      => self::obj(),
			'read_only'   => true,
			'essential'   => false,
		);

		$t['wp_migration_status'] = array(
			'title'       => 'Elementor → Gutenberg migration status',
			'description' => 'Definitive completion check for an Elementor→Gutenberg migration: what still uses Elementor, leftover library templates, leftover meta, and the remaining next_steps. fully_migrated turns true only when nothing is left and Elementor is deactivated.',
			'scope'       => '',
			'method'      => 'GET',
			'path'        => '/migration-status',
			'schema'      => self::obj(),
			'read_only'   => true,
			'essential'   => false,
		);

		$t['wp_list_blocks'] = array(
			'title'       => 'List registered blocks',
			'description' => 'The exact Gutenberg block vocabulary available on this site (names + attribute schemas). Use it before writing a blocks tree that relies on a block you are not certain exists.',
			'scope'       => '',
			'method'      => 'GET',
			'path'        => '/blocks',
			'schema'      => self::obj(),
			'read_only'   => true,
			'essential'   => false,
		);

		$t['wp_list_patterns'] = array(
			'title'       => 'List block patterns',
			'description' => 'Registered block patterns (theme + core + synced), which you can reuse instead of authoring a layout from scratch.',
			'scope'       => '',
			'method'      => 'GET',
			'path'        => '/patterns',
			'schema'      => self::obj(),
			'read_only'   => true,
			'essential'   => false,
		);

		$t['wp_get_widget'] = array(
			'title'       => 'Elementor widget schema',
			'description' => 'The exact setting keys and controls of one Elementor widget. Only useful in Elementor build mode.',
			'scope'       => '',
			'method'      => 'GET',
			'path'        => '/widgets/{name}',
			'schema'      => self::obj( array( 'name' => self::str( 'Widget name, e.g. "heading", "button", "icon-box".' ) ), array( 'name' ) ),
			'read_only'   => true,
			'essential'   => false,
		);

		/* ---------------- Content ---------------- */

		$t['wp_list_content'] = array(
			'title'       => 'List pages / posts',
			'description' => 'List content with id, title, slug, status, URL and which builder each item uses. Use it to find the id of something before editing it.',
			'scope'       => 'content',
			'method'      => 'GET',
			'path'        => '/content',
			'schema'      => self::obj( array(
				'type'     => self::str( 'Post type. Default "page". Use "post" for blog posts.' ),
				'status'   => self::str( 'publish, draft, any … Default "any".' ),
				'search'   => self::str( 'Free-text search across titles/content.' ),
				'per_page' => self::int( 'How many to return (max 100, default 30).' ),
			) ),
			'read_only'   => true,
			'essential'   => true,
		);

		$t['wp_get_content'] = array(
			'title'       => 'Read a page / post',
			'description' => 'Read one item by id, including its raw content, block markup, Elementor data and page settings. Read before you edit so you do not clobber existing work.',
			'scope'       => 'content',
			'method'      => 'GET',
			'path'        => '/content/{id}',
			'schema'      => self::obj( array( 'id' => self::int( 'Post/page ID.' ) ), array( 'id' ) ),
			'read_only'   => true,
			'essential'   => true,
		);

		$content_write = array(
			'title'         => self::str( 'The title.' ),
			'type'          => self::str( 'post type: "page" (default) or "post".' ),
			'slug'          => self::str( 'URL slug. Auto-derived from the title when omitted.' ),
			'status'        => self::str( 'publish | draft | private. Default publish.' ),
			'builder'       => self::str( 'gutenberg (native blocks — preferred) or elementor. Setting "gutenberg" on an existing Elementor page strips every Elementor meta and migrates it in place on the same URL.' ),
			'blocks'        => self::blocks_arg(),
			'content'       => self::str( 'Raw block markup / HTML, used as-is. Prefer "blocks" — it cannot produce mismatched block delimiters.' ),
			'excerpt'       => self::str( 'Excerpt (posts).' ),
			'categories'    => self::arr( 'Category names (posts). Created if missing.' ),
			'tags'          => self::arr( 'Tag names (posts). Created if missing.' ),
			'parent'        => self::int( 'Parent page ID — nests the page so its URL becomes /{parent-slug}/{slug}/. This is how you build a language subdirectory such as /fa/faq/.' ),
			'page_template' => self::str( 'default | full_width | canvas. canvas removes the theme header/footer.' ),
			'elementor_data'     => array( 'type' => 'array', 'description' => 'Elementor element tree (Elementor build mode only).', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
			'elementor_settings' => self::map( 'Elementor page settings, e.g. {"hide_title":"yes","custom_css":"…"}.' ),
			'clear_elementor'    => self::bool( 'Remove all Elementor meta from the post.' ),
			'allow_unfiltered_html' => self::bool( 'Set false to force classic KSES filtering. Writes are trusted (unfiltered) by default.' ),
		);

		$t['wp_create_content'] = array(
			'title'       => 'Create a page or post',
			'description' => 'Create a page or post. Prefer native Gutenberg blocks via "blocks" (a structured tree) over raw markup, and decompose designs into real blocks (heading, group, columns, cover, buttons) rather than one big core/html block.',
			'scope'       => 'content',
			'method'      => 'POST',
			'path'        => '/content',
			'schema'      => self::obj( $content_write, array( 'title' ) ),
			'read_only'   => false,
			'essential'   => true,
		);

		$t['wp_update_content'] = array(
			'title'       => 'Update a page or post',
			'description' => 'Update an existing page/post in place, keeping its URL. Pass builder:"gutenberg" to convert an Elementor page to native blocks on the same slug (no menu churn).',
			'scope'       => 'content',
			'method'      => 'PUT',
			'path'        => '/content/{id}',
			'schema'      => self::obj( array_merge( array( 'id' => self::int( 'Post/page ID to update.' ) ), $content_write ), array( 'id' ) ),
			'read_only'   => false,
			'essential'   => true,
		);

		$t['wp_delete_content'] = array(
			'title'       => 'Trash a page or post',
			'description' => 'Move a page/post to the trash (or delete permanently with force:true). Destructive — confirm with the user first.',
			'scope'       => 'content',
			'method'      => 'DELETE',
			'path'        => '/content/{id}',
			'schema'      => self::obj( array(
				'id'    => self::int( 'Post/page ID.' ),
				'force' => self::bool( 'true = delete permanently instead of trashing.' ),
			), array( 'id' ) ),
			'read_only'   => false,
			'essential'   => false,
		);

		$t['wp_cleanup_elementor_meta'] = array(
			'title'       => 'Purge leftover Elementor meta',
			'description' => 'Remove orphaned _elementor_* post meta left behind after a migration. Pass revisions_only:true to limit it to revisions.',
			'scope'       => 'content',
			'method'      => 'POST',
			'path'        => '/cleanup/elementor-meta',
			'schema'      => self::obj( array( 'revisions_only' => self::bool( 'Only clean revisions.' ) ) ),
			'read_only'   => false,
			'essential'   => false,
		);

		/* ---------------- Media & assets ---------------- */

		$t['wp_list_media'] = array(
			'title'       => 'List media',
			'description' => 'List media-library items with their URLs and ids.',
			'scope'       => 'media',
			'method'      => 'GET',
			'path'        => '/media',
			'schema'      => self::obj( array( 'per_page' => self::int( 'How many to return.' ), 'search' => self::str( 'Filter by filename/title.' ) ) ),
			'read_only'   => true,
			'essential'   => false,
		);

		$t['wp_upload_media'] = array(
			'title'       => 'Sideload an image from a URL',
			'description' => 'Download a remote image into the media library and return its local URL and attachment id.',
			'scope'       => 'media',
			'method'      => 'POST',
			'path'        => '/media',
			'schema'      => self::obj( array(
				'url'         => self::str( 'Source image URL.' ),
				'title'       => self::str( 'Attachment title.' ),
				'alt'         => self::str( 'Alt text.' ),
				'attach_to'   => self::int( 'Post ID to attach it to.' ),
				'featured_of' => self::int( 'Post ID to set this image as the featured image of.' ),
			), array( 'url' ) ),
			'read_only'   => false,
			'essential'   => true,
		);

		$t['wp_upload_asset'] = array(
			'title'       => 'Upload a file (font / CSS / SVG)',
			'description' => 'Host a file as a real, cacheable upload from base64 content and return its URL. Use this for webfonts, stylesheets and SVGs instead of inlining large base64 blobs in CSS — inlining is the #1 cause of a slow AI-built page.',
			'scope'       => 'media',
			'method'      => 'POST',
			'path'        => '/assets/upload',
			'schema'      => self::obj( array(
				'filename' => self::str( 'File name including extension, e.g. "vazirmatn.woff2".' ),
				'base64'   => self::str( 'Base64-encoded file content.' ),
				'mime'     => self::str( 'MIME type; guessed from the extension when omitted.' ),
			), array( 'filename', 'base64' ) ),
			'read_only'   => false,
			'essential'   => false,
		);

		/* ---------------- Menus ---------------- */

		$t['wp_list_menus'] = array(
			'title'       => 'List navigation menus',
			'description' => 'Every nav menu with its items and assigned theme locations.',
			'scope'       => 'menus',
			'method'      => 'GET',
			'path'        => '/menus',
			'schema'      => self::obj(),
			'read_only'   => true,
			'essential'   => false,
		);

		$menu_items = array(
			'type'        => 'array',
			'description' => 'Menu items. Each: {title, url | page_id, children:[…]} — children nest one level deeper.',
			'items'       => array( 'type' => 'object', 'additionalProperties' => true ),
		);

		$t['wp_create_menu'] = array(
			'title'       => 'Create a navigation menu',
			'description' => 'Create a nav menu, its items (nestable via children[]) and assign it to theme locations.',
			'scope'       => 'menus',
			'method'      => 'POST',
			'path'        => '/menus',
			'schema'      => self::obj( array(
				'name'      => self::str( 'Menu name.' ),
				'items'     => $menu_items,
				'locations' => self::arr( 'Theme location slugs to assign it to, e.g. ["primary"].' ),
			), array( 'name' ) ),
			'read_only'   => false,
			'essential'   => true,
		);

		$t['wp_update_menu'] = array(
			'title'       => 'Update a navigation menu',
			'description' => 'Replace a menu\'s items and/or locations by menu id.',
			'scope'       => 'menus',
			'method'      => 'PUT',
			'path'        => '/menus/{id}',
			'schema'      => self::obj( array(
				'id'        => self::int( 'Menu ID.' ),
				'name'      => self::str( 'New name.' ),
				'items'     => $menu_items,
				'locations' => self::arr( 'Theme location slugs.' ),
			), array( 'id' ) ),
			'read_only'   => false,
			'essential'   => false,
		);

		$t['wp_menu_locations'] = array(
			'title'       => 'List theme menu locations',
			'description' => 'The menu locations this theme registers and what is currently assigned to each.',
			'scope'       => 'menus',
			'method'      => 'GET',
			'path'        => '/menu-locations',
			'schema'      => self::obj(),
			'read_only'   => true,
			'essential'   => false,
		);

		/* ---------------- Templates (FSE + Elementor + patterns) ---------------- */

		$t['wp_list_fse_templates'] = array(
			'title'       => 'List block-theme templates',
			'description' => 'The wp_template entries (index, single, archive, page…) of a block theme.',
			'scope'       => 'templates',
			'method'      => 'GET',
			'path'        => '/fse-templates',
			'schema'      => self::obj(),
			'read_only'   => true,
			'essential'   => false,
		);

		$t['wp_upsert_fse_template'] = array(
			'title'       => 'Create/update a block-theme template',
			'description' => 'Upsert a full block-theme (FSE) template such as index, single, archive or page.',
			'scope'       => 'templates',
			'method'      => 'POST',
			'path'        => '/fse-templates',
			'schema'      => self::obj( array(
				'slug'    => self::str( 'Template slug: index, single, archive, page, 404 …' ),
				'title'   => self::str( 'Human title.' ),
				'blocks'  => self::blocks_arg(),
				'content' => self::str( 'Raw block markup instead of a blocks tree.' ),
			), array( 'slug' ) ),
			'read_only'   => false,
			'essential'   => false,
		);

		$t['wp_list_fse_parts'] = array(
			'title'       => 'List block-theme template parts',
			'description' => 'The wp_template_part entries (header, footer, sidebar) of a block theme.',
			'scope'       => 'templates',
			'method'      => 'GET',
			'path'        => '/fse-template-parts',
			'schema'      => self::obj(),
			'read_only'   => true,
			'essential'   => false,
		);

		$t['wp_upsert_fse_part'] = array(
			'title'       => 'Create/update a header or footer (block theme)',
			'description' => 'Upsert a block-theme template part. This is how you build a site-wide header/footer ONCE on a block theme — never repeat header markup on every page. <script> survives here, which makes it the right home for a language switcher.',
			'scope'       => 'templates',
			'method'      => 'POST',
			'path'        => '/fse-template-parts',
			'schema'      => self::obj( array(
				'slug'    => self::str( 'Part slug, e.g. "header" or "footer".' ),
				'area'    => self::str( 'header | footer | uncategorized.' ),
				'title'   => self::str( 'Human title.' ),
				'blocks'  => self::blocks_arg(),
				'content' => self::str( 'Raw block markup instead of a blocks tree.' ),
			), array( 'slug' ) ),
			'read_only'   => false,
			'essential'   => true,
		);

		$t['wp_create_pattern'] = array(
			'title'       => 'Register a synced pattern',
			'description' => 'Register a reusable synced block pattern so one edit updates every place it is used.',
			'scope'       => 'templates',
			'method'      => 'POST',
			'path'        => '/patterns',
			'schema'      => self::obj( array(
				'title'   => self::str( 'Pattern title.' ),
				'blocks'  => self::blocks_arg(),
				'content' => self::str( 'Raw block markup instead of a blocks tree.' ),
			), array( 'title' ) ),
			'read_only'   => false,
			'essential'   => false,
		);

		$t['wp_upsert_elementor_template'] = array(
			'title'       => 'Create/update an Elementor Theme Builder part',
			'description' => 'Elementor build mode only: upsert a Theme Builder header, footer, single or loop-item template with display conditions.',
			'scope'       => 'templates',
			'method'      => 'POST',
			'path'        => '/template',
			'schema'      => self::obj( array(
				'slug'               => self::str( 'Template slug.' ),
				'title'              => self::str( 'Template title.' ),
				'template_type'      => self::str( 'header | footer | single | archive | loop-item | section.' ),
				'elementor_data'     => array( 'type' => 'array', 'description' => 'Elementor element tree.', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
				'conditions'         => self::arr( 'Display conditions, e.g. ["include/general"] for the whole site.' ),
				'elementor_settings' => self::map( 'Page settings for the template.' ),
			), array( 'template_type' ) ),
			'read_only'   => false,
			'essential'   => false,
		);

		/* ---------------- Styles ---------------- */

		$t['wp_get_custom_css'] = array(
			'title'       => 'Read Additional CSS',
			'description' => 'The Customizer "Additional CSS" for the active theme.',
			'scope'       => 'styles',
			'method'      => 'GET',
			'path'        => '/settings/custom-css',
			'schema'      => self::obj(),
			'read_only'   => true,
			'essential'   => false,
		);

		$t['wp_set_custom_css'] = array(
			'title'       => 'Write Additional CSS',
			'description' => 'Set (or append to) the Customizer Additional CSS — the global stylesheet for a classic theme. Note: this store HTML-escapes ">", so use descendant selectors here, never child combinators.',
			'scope'       => 'styles',
			'method'      => 'POST',
			'path'        => '/settings/custom-css',
			'schema'      => self::obj( array(
				'css'    => self::str( 'The CSS.' ),
				'append' => self::bool( 'true = append to the existing CSS instead of replacing it.' ),
			), array( 'css' ) ),
			'read_only'   => false,
			'essential'   => true,
		);

		$t['wp_get_global_styles'] = array(
			'title'       => 'Read block-theme global styles',
			'description' => 'The active block theme\'s theme.json user layer: palette, typography, spacing and custom CSS.',
			'scope'       => 'styles',
			'method'      => 'GET',
			'path'        => '/global-styles',
			'schema'      => self::obj(),
			'read_only'   => true,
			'essential'   => false,
		);

		$t['wp_set_global_styles'] = array(
			'title'       => 'Write block-theme global styles',
			'description' => 'Merge into the block theme\'s theme.json user layer — the right place for a site-wide palette, font families and global CSS on an FSE theme.',
			'scope'       => 'styles',
			'method'      => 'POST',
			'path'        => '/global-styles',
			'schema'      => self::obj( array(
				'settings' => self::map( 'theme.json "settings" fragment (palette, typography, spacing).' ),
				'styles'   => self::map( 'theme.json "styles" fragment.' ),
				'css'      => self::str( 'Raw global CSS.' ),
			) ),
			'read_only'   => false,
			'essential'   => true,
		);

		/* ---------------- Themes, plugins, settings ---------------- */

		$t['wp_list_themes'] = array(
			'title'       => 'List themes',
			'description' => 'Installed themes and which one is active.',
			'scope'       => 'themes',
			'method'      => 'GET',
			'path'        => '/themes',
			'schema'      => self::obj(),
			'read_only'   => true,
			'essential'   => false,
		);

		$t['wp_activate_theme'] = array(
			'title'       => 'Install / activate a theme',
			'description' => 'Install a theme from the WordPress.org repo by slug and activate it. Switching themes changes the whole site — confirm with the user first.',
			'scope'       => 'themes',
			'method'      => 'POST',
			'path'        => '/themes/activate',
			'schema'      => self::obj( array(
				'slug'    => self::str( 'Theme slug on WordPress.org, e.g. "twentytwentyfive".' ),
				'install' => self::bool( 'Install it first if it is not present. Default true.' ),
			), array( 'slug' ) ),
			'read_only'   => false,
			'essential'   => false,
		);

		$t['wp_list_plugins'] = array(
			'title'       => 'List plugins',
			'description' => 'Installed plugins with version and active state.',
			'scope'       => 'plugins',
			'method'      => 'GET',
			'path'        => '/plugins',
			'schema'      => self::obj(),
			'read_only'   => true,
			'essential'   => true,
		);

		$t['wp_install_plugin'] = array(
			'title'       => 'Install a plugin',
			'description' => 'Install a plugin from the WordPress.org repo (by slug) or from a base64 zip, optionally activating it.',
			'scope'       => 'plugins',
			'method'      => 'POST',
			'path'        => '/plugins/install',
			'schema'      => self::obj( array(
				'slug'     => self::str( 'WordPress.org plugin slug.' ),
				'zip'      => self::str( 'Base64-encoded plugin zip (alternative to slug).' ),
				'activate' => self::bool( 'Activate after installing.' ),
			) ),
			'read_only'   => false,
			'essential'   => false,
		);

		$t['wp_manage_plugin'] = array(
			'title'       => 'Activate / deactivate / delete a plugin',
			'description' => 'Change a plugin\'s state. Deactivating or deleting a plugin can take the site down — confirm with the user first. PressPilot refuses to disable itself.',
			'scope'       => 'plugins',
			'method'      => 'POST',
			'path'        => '/plugins/{action}',
			'schema'      => self::obj( array(
				'action' => self::str( 'activate | deactivate | delete.', array( 'enum' => array( 'activate', 'deactivate', 'delete' ) ) ),
				'slug'   => self::str( 'Plugin slug (folder name).' ),
				'file'   => self::str( 'Plugin main file, e.g. "elementor/elementor.php" (alternative to slug).' ),
			), array( 'action' ) ),
			'read_only'   => false,
			'essential'   => false,
		);

		$t['wp_set_options'] = array(
			'title'       => 'Set core site options',
			'description' => 'Write allow-listed core options: permalink_structure, blogname, WPLANG, admin_locale, date_format, elementor_google_fonts … Use wp_config_set_options for arbitrary plugin options.',
			'scope'       => 'settings',
			'method'      => 'POST',
			'path'        => '/settings/options',
			'schema'      => self::obj( array( 'options' => self::map( 'Map of option_name → value.' ) ), array( 'options' ) ),
			'read_only'   => false,
			'essential'   => true,
		);

		$t['wp_set_homepage'] = array(
			'title'       => 'Set the front page',
			'description' => 'Set a static page as the front page (and optionally a page as the posts page).',
			'scope'       => 'settings',
			'method'      => 'POST',
			'path'        => '/homepage',
			'schema'      => self::obj( array(
				'page_id'       => self::int( 'Page ID to use as the front page.' ),
				'posts_page_id' => self::int( 'Page ID to use as the blog index.' ),
			) ),
			'read_only'   => false,
			'essential'   => true,
		);

		$t['wp_get_homepage'] = array(
			'title'       => 'Read the front-page setting',
			'description' => 'What the site currently shows on its front page.',
			'scope'       => 'settings',
			'method'      => 'GET',
			'path'        => '/homepage',
			'schema'      => self::obj(),
			'read_only'   => true,
			'essential'   => false,
		);

		$t['wp_form_config'] = array(
			'title'       => 'Configure the native contact form',
			'description' => 'Read or set the built-in contact-form endpoint config (recipient, subject, redirect) — a dependency-free alternative to a form plugin.',
			'scope'       => 'settings',
			'method'      => 'POST',
			'path'        => '/forms/config',
			'schema'      => self::obj( array(
				'to'       => self::str( 'Recipient email address.' ),
				'subject'  => self::str( 'Subject line.' ),
				'redirect' => self::str( 'Thank-you URL.' ),
			) ),
			'read_only'   => false,
			'essential'   => false,
		);

		/* ---------------- Configuration assistant (config scope) ---------------- */

		$t['wp_config_discover'] = array(
			'title'       => 'Discover a plugin\'s configuration surface',
			'description' => 'Given a plugin slug or an option prefix, return its option keys, registered settings and its own REST routes. START HERE when asked to configure a plugin — it tells you which keys exist before you write anything.',
			'scope'       => 'config',
			'method'      => 'GET',
			'path'        => '/discover',
			'schema'      => self::obj( array(
				'slug'   => self::str( 'Plugin slug, e.g. "woocommerce".' ),
				'prefix' => self::str( 'Option-name prefix to scan instead of a slug.' ),
			) ),
			'read_only'   => true,
			'essential'   => true,
		);

		$t['wp_config_get_options'] = array(
			'title'       => 'Read options',
			'description' => 'Read any WordPress option by exact keys or by prefix. Secret-looking values are redacted unless reveal:true.',
			'scope'       => 'config',
			'method'      => 'GET',
			'path'        => '/options',
			'schema'      => self::obj( array(
				'keys'   => self::str( 'Comma-separated option names.' ),
				'prefix' => self::str( 'Return every option whose name starts with this.' ),
				'reveal' => self::bool( 'Do not redact secret-looking values.' ),
			) ),
			'read_only'   => true,
			'essential'   => true,
		);

		$t['wp_config_set_options'] = array(
			'title'       => 'Write options',
			'description' => 'Write any WordPress option. ALWAYS run once with dry_run:true first and show the user the diff. Every real write creates a restore point (restore_id in the response) you can roll back with wp_config_restore. Lock-out options (siteurl, home, active_plugins, template, stylesheet) are refused unless force:true.',
			'scope'       => 'config',
			'method'      => 'POST',
			'path'        => '/options',
			'schema'      => self::obj( array(
				'options' => self::map( 'Map of option_name → value.' ),
				'dry_run' => self::bool( 'Preview the before/after diff without writing.' ),
				'force'   => self::bool( 'Allow writing a lock-out option. Dangerous.' ),
			), array( 'options' ) ),
			'read_only'   => false,
			'essential'   => true,
		);

		$t['wp_config_snapshot'] = array(
			'title'       => 'Snapshot the configuration',
			'description' => 'Capture current option values as a restore point. Also the trick for finding an unknown option key: snapshot → ask the user to change the setting once in wp-admin → wp_config_diff tells you exactly which option changed.',
			'scope'       => 'config',
			'method'      => 'POST',
			'path'        => '/config/snapshot',
			'schema'      => self::obj( array(
				'prefix' => self::str( 'Only snapshot options with this prefix.' ),
				'keys'   => self::arr( 'Only snapshot these exact option names.' ),
				'label'  => self::str( 'A label so you can recognise it later.' ),
			) ),
			'read_only'   => false,
			'essential'   => true,
		);

		$t['wp_config_diff'] = array(
			'title'       => 'Diff a snapshot against now',
			'description' => 'What changed since a snapshot was taken — the exact option names and their before/after values.',
			'scope'       => 'config',
			'method'      => 'GET',
			'path'        => '/config/diff',
			'schema'      => self::obj( array( 'id' => self::str( 'Snapshot id.' ) ), array( 'id' ) ),
			'read_only'   => true,
			'essential'   => true,
		);

		$t['wp_config_restore'] = array(
			'title'       => 'Roll back to a restore point',
			'description' => 'Restore the option values captured in a snapshot or an automatic restore point. This is the undo button.',
			'scope'       => 'config',
			'method'      => 'POST',
			'path'        => '/config/restore',
			'schema'      => self::obj( array( 'id' => self::str( 'Snapshot / restore-point id.' ) ), array( 'id' ) ),
			'read_only'   => false,
			'essential'   => false,
		);

		$t['wp_get_meta'] = array(
			'title'       => 'Read post/term/user meta',
			'description' => 'Read metadata for one object — where many plugins keep per-item configuration.',
			'scope'       => 'config',
			'method'      => 'GET',
			'path'        => '/meta',
			'schema'      => self::obj( array(
				'type' => self::str( 'post | term | user.' ),
				'id'   => self::int( 'Object ID.' ),
				'keys' => self::str( 'Comma-separated meta keys; omit for all.' ),
			), array( 'type', 'id' ) ),
			'read_only'   => true,
			'essential'   => false,
		);

		$t['wp_set_meta'] = array(
			'title'       => 'Write post/term/user meta',
			'description' => 'Write metadata onto one object.',
			'scope'       => 'config',
			'method'      => 'POST',
			'path'        => '/meta',
			'schema'      => self::obj( array(
				'type' => self::str( 'post | term | user.' ),
				'id'   => self::int( 'Object ID.' ),
				'meta' => self::map( 'Map of meta_key → value.' ),
			), array( 'type', 'id', 'meta' ) ),
			'read_only'   => false,
			'essential'   => false,
		);

		$t['wp_proxy_rest'] = array(
			'title'       => 'Call another plugin\'s REST API',
			'description' => 'Dispatch a request to ANY REST route on this site as an administrator — WooCommerce, Yoast, the core wp/v2 API, anything. This is the cleanest way to configure a plugin that exposes REST. Use wp_list_rest_routes to find the paths.',
			'scope'       => 'config',
			'method'      => 'POST',
			'path'        => '/proxy',
			'schema'      => self::obj( array(
				'method' => self::str( 'GET | POST | PUT | PATCH | DELETE.' ),
				'path'   => self::str( 'Route path, e.g. "/wc/v3/settings" or "/wp/v2/users/me".' ),
				'body'   => self::map( 'Request body / query parameters.' ),
			), array( 'path' ) ),
			'read_only'   => false,
			'essential'   => true,
		);

		$t['wp_list_rest_routes'] = array(
			'title'       => 'List REST routes',
			'description' => 'Every REST route registered on this site, optionally filtered by namespace prefix — the map for wp_proxy_rest.',
			'scope'       => 'config',
			'method'      => 'GET',
			'path'        => '/rest-routes',
			'schema'      => self::obj( array( 'prefix' => self::str( 'Namespace prefix, e.g. "/wc/v3".' ) ) ),
			'read_only'   => true,
			'essential'   => false,
		);

		$t['wp_list_adapters'] = array(
			'title'       => 'List plugin adapters',
			'description' => 'Curated connectors for plugins that only expose a PHP API (Polylang, …): which adapters exist, their whitelisted actions, and whether the plugin is active.',
			'scope'       => 'config',
			'method'      => 'GET',
			'path'        => '/adapters',
			'schema'      => self::obj(),
			'read_only'   => true,
			'essential'   => false,
		);

		$t['wp_run_adapter'] = array(
			'title'       => 'Run a plugin adapter action',
			'description' => 'Run one whitelisted adapter action, e.g. slug "polylang" action "add_language" with {locale:"fa_IR", name:"Persian", rtl:1}. Only registered actions run, and only when the plugin is active.',
			'scope'       => 'config',
			'method'      => 'POST',
			'path'        => '/adapters/{slug}/{action}',
			'schema'      => self::obj( array(
				'slug'   => self::str( 'Adapter slug, e.g. "polylang".' ),
				'action' => self::str( 'Action name, e.g. "add_language".' ),
				'args'   => self::map( 'Action arguments (also accepted as top-level keys).' ),
			), array( 'slug', 'action' ) ),
			'read_only'   => false,
			'essential'   => false,
		);

		$t['wp_db_tables'] = array(
			'title'       => 'List database tables',
			'description' => 'Custom plugin tables with row counts. Use it when a plugin stores its configuration outside the options table.',
			'scope'       => 'config',
			'method'      => 'GET',
			'path'        => '/db/tables',
			'schema'      => self::obj( array( 'prefix' => self::str( 'Table-name prefix filter.' ) ) ),
			'read_only'   => true,
			'essential'   => false,
		);

		$t['wp_db_describe'] = array(
			'title'       => 'Describe a database table',
			'description' => 'Column names, types and keys of one table.',
			'scope'       => 'config',
			'method'      => 'GET',
			'path'        => '/db/describe',
			'schema'      => self::obj( array( 'table' => self::str( 'Table name.' ) ), array( 'table' ) ),
			'read_only'   => true,
			'essential'   => false,
		);

		$t['wp_db_select'] = array(
			'title'       => 'Read from a database table',
			'description' => 'Structured read ({table, where, columns, order, limit}) or a single read-only SELECT via raw. Never modifies anything.',
			'scope'       => 'config',
			'method'      => 'POST',
			'path'        => '/db/select',
			'schema'      => self::obj( array(
				'table'   => self::str( 'Table name.' ),
				'where'   => self::map( 'Equality filters {column: value}.' ),
				'columns' => self::arr( 'Columns to return.' ),
				'order'   => self::str( 'ORDER BY clause, e.g. "id DESC".' ),
				'limit'   => self::int( 'Row limit.' ),
				'raw'     => self::str( 'A single read-only SELECT statement (alternative to the structured form).' ),
			) ),
			'read_only'   => true,
			'essential'   => false,
		);

		$t['wp_db_write'] = array(
			'title'       => 'Write to a database table',
			'description' => 'insert / update / delete on a custom plugin table. ALWAYS dry_run first. Updates and deletes require a where clause, affected rows are capped and returned as a before-image, and core WordPress tables are refused (use the typed tools instead) unless force:true. Dangerous — prefer options, meta, the plugin\'s REST API or an adapter.',
			'scope'       => 'config',
			'method'      => 'POST',
			'path'        => '/db/write',
			'schema'      => self::obj( array(
				'op'      => self::str( 'insert | update | delete.', array( 'enum' => array( 'insert', 'update', 'delete' ) ) ),
				'table'   => self::str( 'Table name.' ),
				'data'    => self::map( 'Column → value (insert/update).' ),
				'where'   => self::map( 'Equality filters (required for update/delete).' ),
				'dry_run' => self::bool( 'Preview without writing.' ),
				'force'   => self::bool( 'Allow writing a core WordPress table. Dangerous.' ),
			), array( 'op', 'table' ) ),
			'read_only'   => false,
			'essential'   => false,
		);

		$t['wp_admin_ajax'] = array(
			'title'       => 'Dispatch an admin-ajax action',
			'description' => 'Run a wp_ajax_{action} handler as an administrator — for plugins that only persist settings through admin-ajax.',
			'scope'       => 'config',
			'method'      => 'POST',
			'path'        => '/admin-ajax',
			'schema'      => self::obj( array(
				'action' => self::str( 'The action name (without the wp_ajax_ prefix).' ),
				'args'   => self::map( 'POST/GET arguments for the handler.' ),
				'nopriv' => self::bool( 'Dispatch the wp_ajax_nopriv_ variant.' ),
			), array( 'action' ) ),
			'read_only'   => false,
			'essential'   => false,
		);

		$t['wp_exec_php'] = array(
			'title'       => 'Run PHP (last resort)',
			'description' => 'Execute PHP inside WordPress and return the value. Disabled by default and as powerful as installing a plugin. This is the LAST resort: try an adapter, then the plugin\'s own REST API, then options/meta/terms, then the db tools, then admin-ajax first.',
			'scope'       => 'config',
			'method'      => 'POST',
			'path'        => '/exec',
			'schema'      => self::obj( array( 'code' => self::str( 'PHP source; use return to send a value back, e.g. return get_option("x");' ) ), array( 'code' ) ),
			'read_only'   => false,
			'essential'   => false,
		);

		/* ---------------- Generic escape hatches ---------------- */

		$t['wp_api_request'] = array(
			'title'       => 'Call any PressPilot endpoint',
			'description' => 'Escape hatch to any PressPilot REST route that has no dedicated tool (see the endpoint map in the Skill). Capability scopes still apply.',
			'scope'       => '',
			'method'      => 'POST',
			'path'        => ':raw',
			'schema'      => self::obj( array(
				'method' => self::str( 'GET | POST | PUT | PATCH | DELETE. Default GET.' ),
				'path'   => self::str( 'Path under presspilot/v1, e.g. "/globals" or "/content/12".' ),
				'body'   => self::map( 'Body / query parameters.' ),
			), array( 'path' ) ),
			'read_only'   => false,
			'essential'   => false,
		);

		$t['wp_batch'] = array(
			'title'       => 'Run several calls in one request',
			'description' => 'Execute up to 25 PressPilot sub-requests in a single round trip — much faster than one call per page when building a whole site.',
			'scope'       => '',
			'method'      => 'POST',
			'path'        => '/batch',
			'schema'      => self::obj( array(
				'requests' => array(
					'type'        => 'array',
					'description' => 'Sub-requests, each {method, path, body}.',
					'items'       => array( 'type' => 'object', 'additionalProperties' => true ),
				),
			), array( 'requests' ) ),
			'read_only'   => false,
			'essential'   => false,
		);

		self::$registry = $t;
		return self::$registry;
	}

	/* ------------------------------------------------------------------ */
	/* Filtering                                                          */
	/* ------------------------------------------------------------------ */

	/** The configured tool profile: 'full' (default) or 'essential'. */
	public static function profile() {
		return 'essential' === get_option( self::OPTION_PROFILE, 'full' ) ? 'essential' : 'full';
	}

	/**
	 * The tools an agent may actually see right now: the profile, minus any whose
	 * capability scope the administrator turned off, minus /exec when it is not
	 * opted in. Hiding a tool the site would refuse anyway saves the model a
	 * wasted call and a confusing 403.
	 *
	 * @return array<string,array>
	 */
	public static function available() {
		$profile = self::profile();
		$out     = array();
		foreach ( self::all() as $name => $tool ) {
			if ( 'essential' === $profile && empty( $tool['essential'] ) ) {
				continue;
			}
			if ( ! empty( $tool['scope'] ) && ! PP_Auth::scope_allowed( $tool['scope'] ) ) {
				continue;
			}
			if ( 'wp_exec_php' === $name && ! PP_Config::exec_enabled() ) {
				continue;
			}
			$out[ $name ] = $tool;
		}
		return $out;
	}

	/**
	 * The tool list in MCP shape.
	 *
	 * @return array[]
	 */
	public static function mcp_definitions() {
		$out = array();
		foreach ( self::available() as $name => $tool ) {
			$out[] = array(
				'name'        => $name,
				'title'       => $tool['title'],
				'description' => $tool['description'],
				'inputSchema' => $tool['schema'],
				'annotations' => array(
					'title'           => $tool['title'],
					'readOnlyHint'    => (bool) $tool['read_only'],
					'destructiveHint' => ! $tool['read_only'],
					'idempotentHint'  => (bool) $tool['read_only'],
					'openWorldHint'   => false,
				),
			);
		}
		return $out;
	}

	/**
	 * The tool list in Anthropic Messages shape.
	 *
	 * @return array[]
	 */
	public static function anthropic_definitions() {
		$out = array();
		foreach ( self::available() as $name => $tool ) {
			$out[] = array(
				'name'         => $name,
				'description'  => $tool['description'],
				'input_schema' => $tool['schema'],
			);
		}
		return $out;
	}

	/**
	 * The tool list in OpenAI chat-completions shape (also OpenRouter / AgentRouter).
	 *
	 * @return array[]
	 */
	public static function openai_definitions() {
		$out = array();
		foreach ( self::available() as $name => $tool ) {
			$out[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => $name,
					'description' => $tool['description'],
					'parameters'  => $tool['schema'],
				),
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Dispatch                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Run a tool by name.
	 *
	 * The call is dispatched back through the site's own REST stack, so the same
	 * handlers, argument validation and capability-scope checks apply as they do
	 * to an external HTTP caller — a disabled scope still returns pp_scope_disabled.
	 *
	 * @param string $name Tool name.
	 * @param array  $args Arguments from the model.
	 * @return array{ok:bool,status:int,data:mixed}
	 */
	public static function call( $name, $args ) {
		$tools = self::all();
		if ( ! isset( $tools[ $name ] ) ) {
			return array(
				'ok'     => false,
				'status' => 404,
				'data'   => array( 'code' => 'pp_unknown_tool', 'message' => sprintf( 'No tool named "%s".', $name ) ),
			);
		}
		if ( ! isset( self::available()[ $name ] ) ) {
			return array(
				'ok'     => false,
				'status' => 403,
				'data'   => array(
					'code'    => 'pp_tool_unavailable',
					'message' => sprintf( 'The "%s" tool is turned off on this site (capability "%s" is disabled, or the tool profile excludes it). Tell the user to enable it on the PressPilot → Permissions screen; do not retry.', $name, $tools[ $name ]['scope'] ),
				),
			);
		}

		$tool = $tools[ $name ];
		$args = is_array( $args ) ? $args : array();

		// The generic escape hatch carries its own method + path.
		if ( ':raw' === $tool['path'] ) {
			$method = isset( $args['method'] ) ? strtoupper( sanitize_text_field( (string) $args['method'] ) ) : 'GET';
			$path   = '/' . ltrim( (string) ( isset( $args['path'] ) ? $args['path'] : '' ), '/' );
			$body   = isset( $args['body'] ) && is_array( $args['body'] ) ? $args['body'] : array();
			return self::dispatch( $method, $path, $body );
		}

		// Fill {placeholders} from the arguments and drop them from the payload.
		$path = $tool['path'];
		if ( preg_match_all( '/\{([a-z_]+)\}/', $path, $m ) ) {
			foreach ( $m[1] as $key ) {
				$value = isset( $args[ $key ] ) ? $args[ $key ] : '';
				if ( '' === $value && 0 !== $value ) {
					return array(
						'ok'     => false,
						'status' => 400,
						'data'   => array( 'code' => 'pp_missing_arg', 'message' => sprintf( 'The "%s" argument is required.', $key ) ),
					);
				}
				$path = str_replace( '{' . $key . '}', rawurlencode( (string) $value ), $path );
				unset( $args[ $key ] );
			}
		}

		// Adapters take their action arguments as top-level body keys.
		if ( 'wp_run_adapter' === $name && isset( $args['args'] ) && is_array( $args['args'] ) ) {
			$args = array_merge( $args['args'], array_diff_key( $args, array( 'args' => 1 ) ) );
		}

		return self::dispatch( $tool['method'], $path, $args );
	}

	/**
	 * Dispatch one internal request to a PressPilot route with API-key auth waived
	 * (the caller was already authenticated) but capability scopes still enforced.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path under the plugin namespace.
	 * @param array  $params Parameters.
	 * @return array{ok:bool,status:int,data:mixed}
	 */
	public static function dispatch( $method, $path, $params = array() ) {
		$method  = strtoupper( $method );
		$request = new WP_REST_Request( $method, '/' . PP_REST_NS . $path );

		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
			$request->set_body( wp_json_encode( $params ) );
			$request->set_header( 'Content-Type', 'application/json' );
		}
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		PP_Auth::begin_internal();
		try {
			$response = rest_do_request( $request );
		} catch ( \Throwable $e ) {
			PP_Auth::end_internal();
			return array(
				'ok'     => false,
				'status' => 500,
				'data'   => array( 'code' => 'pp_tool_exception', 'message' => $e->getMessage() ),
			);
		}
		PP_Auth::end_internal();

		$status = $response->get_status();
		return array(
			'ok'     => $status >= 200 && $status < 300,
			'status' => $status,
			'data'   => $response->get_data(),
		);
	}

	/**
	 * Render a tool result as the text an LLM reads. Big payloads (a full block
	 * tree, every option on the site) are truncated with a visible marker so a
	 * single call cannot blow up the context window.
	 *
	 * @param array $result Result from ::call().
	 * @return string
	 */
	public static function render_result( $result ) {
		$json = wp_json_encode( $result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			$json = wp_json_encode( array( 'note' => 'Result could not be encoded as JSON.' ) );
		}
		if ( strlen( $json ) > self::MAX_RESULT_BYTES ) {
			$json = substr( $json, 0, self::MAX_RESULT_BYTES )
				. "\n\n…[truncated: the result was larger than " . self::MAX_RESULT_BYTES
				. " bytes. Narrow the request — fewer items, a prefix filter, or specific keys.]";
		}
		if ( ! $result['ok'] ) {
			return 'ERROR (HTTP ' . $result['status'] . "):\n" . $json;
		}
		return $json;
	}
}
