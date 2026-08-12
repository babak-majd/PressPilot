=== PressPilot ===
Contributors: babak-majd
Donate link: https://bobclub.ir/coffee
Tags: gutenberg, rest-api, ai, automation, block-editor
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure REST API + agent Skill that lets an AI agent build and manage your whole WordPress site with native Gutenberg blocks.

== Description ==

PressPilot exposes a namespaced, API-key-protected REST API (`/wp-json/presspilot/v1/`) so an AI
agent can build and manage your whole site remotely — no FTP/SSH or PHP shell access required.

Features:

* Build pages/posts with **native Gutenberg blocks** (structured block tree or raw block markup) with no page-builder dependency.
* **Migrate legacy page-builder content to blocks in place**, keeping the same URLs.
* Block-theme (FSE) support: create/update `wp_template` and `wp_template_part` (header/footer, single, archive).
* Global styles without Elementor: Customizer Additional CSS and block-theme theme.json global styles (fonts, palette, CSS).
* Navigation menus: create, edit items (nested), and assign to theme locations.
* Plugins: install from the repo or a zip, activate/deactivate/delete (e.g. remove Elementor after migration).
* Discover the block/widget vocabulary and register reusable patterns.
* **Permissions**: per-capability toggles in the admin (default: everything on) so you control exactly what the API may touch.
* API-key authentication with constant-time comparison and one-click key regeneration.
* Safety controls: master API on/off switch and an optional IP/CIDR allow-list.

Licensed GPL-2.0-or-later (WordPress-compatible). Free to use. If it saves you time,
buy me a coffee ☕: https://bobclub.ir/coffee

Site: https://bobclub.ir · Telegram: https://t.me/bob_club

== Installation ==

1. Upload the plugin zip via Plugins > Add New > Upload Plugin, then Activate.
2. Make sure Elementor (and optionally Elementor Pro) is active.
3. Go to Settings > AI Assistant, copy the REST Base URL and API Key, and give them to your assistant.

== Frequently Asked Questions ==

= The /wp-json/ URL returns 404 =
Re-save Permalinks (Settings > Permalinks > Save). Or use the fallback form `/?rest_route=/presspilot/v1/...`.

= Is it safe? =
Every endpoint requires the secret API key. Keep it private and serve your site over HTTPS. You can
regenerate the key at any time from the settings page.

== Changelog ==

= 1.9.0 =
* **Universal reach** — configure virtually any plugin, whatever it stores config in:
  * Custom DB tables: GET `/db/tables` `/db/describe`, POST `/db/select` (structured or read-only raw SELECT) and `/db/write` (insert/update/delete) with dry-run, affected-row cap, before-image capture, and a core-table guard.
  * admin-ajax: POST `/admin-ajax` dispatches a `wp_ajax_{action}` handler as admin (for plugins that only save via ajax) and returns the decoded output.
  * Escape hatch: POST `/exec` runs PHP — **off by default**, enabled only via the `presspilot_allow_exec` option / `PP_ALLOW_EXEC` constant (equal in power to the plugin-install the key already allows).
* Together with 1.8.0's options/meta/terms/proxy/discovery/adapters, this covers options, meta, terms, custom tables, a plugin's own REST API, admin-ajax, and (opt-in) arbitrary code.

= 1.8.0 =
* **Configuration assistant** — PressPilot can now help configure other plugins & the site, not just build content:
  * Generic state: GET/POST `/options` (read/write any option, by key or prefix), GET/POST `/meta` (post/term/user), POST `/terms` + `/terms/assign`.
  * Safety: every write auto-creates a restore point; `dry_run` previews the diff; a denylist guards lock-out options; `/config/snapshot`, `/config/diff`, `/config/restore`.
  * Discovery: `/registered-settings`, `/rest-routes`, and `/discover?slug=` (option keys + registered settings + REST routes for a plugin). Learn any plugin's keys by snapshot → change in wp-admin → diff.
  * REST passthrough: POST `/proxy` dispatches to any registered REST route (drive a plugin's own API), acting as admin.
  * Adapters: `/adapters` + `/adapters/{slug}/{action}` — curated, self-describing connectors for plugins that need their own PHP API (ships a Polylang adapter: add_language, set_post_language, link_translations).
  * New `config` scope on the Permissions screen gates all of the above.

= 1.7.1 =
* Content: POST/PUT /content now accepts `parent` (post ID) to build page hierarchy — enables nested URLs like /fa/faq/ for language subdirectories. GET responses now include `parent`.

= 1.7.0 =
* Assets: POST /assets/upload — host fonts/CSS/SVG as real cacheable files (base64 → uploads), so huge base64 blobs no longer bloat inline CSS.
* Performance: GET /performance — one-call speed diagnostics (TTFB, page weight, inline CSS/base64, opcache, object cache, page cache, gzip, autoloaded-option bloat) with prioritized recommendations.
* Serializer: the structured blocks API can now wrap children in a real container element (innerContentOpen/innerContentClose) — build core/group, columns, cover natively.
* FSE writes now flush the block-template cache; /settings/custom-css can target a non-active theme.
* Native forms: POST /forms/submit (public, honeypot + min-time + per-IP rate limit) + /forms/config — dependency-free contact forms via wp_mail.
* Batch: POST /batch runs up to 25 sub-requests in one call. Cleanup: POST /cleanup/elementor-meta purges lingering _elementor_* meta.
* Security: master API on/off switch and an optional IP/CIDR allow-list, both on the Permissions screen.

= 1.6.0 =
* Gutenberg: write pages/posts with native blocks via a structured `blocks` tree or raw block markup; trusted (KSES-free) content writes so `<style>`/SVG survive.
* In-place Elementor → Gutenberg migration: `builder:"gutenberg"` / `clear_elementor:true` on POST/PUT /content.
* Block-theme (FSE) endpoints: `/fse-templates`, `/fse-template-parts`, `/global-styles`, `/settings/custom-css`.
* Menu management: create/update/delete menus, nested items, theme-location assignment.
* Plugin management: list, install (repo slug or base64 zip), activate/deactivate/delete.
* Block & pattern discovery (`/blocks`, `/patterns`) and reusable-pattern registration.
* Permissions screen: per-capability scopes gating the API (default all enabled); `/scopes` endpoint.
* Admin UI polish (native WP admin cards) and a Gutenberg-aware agent prompt.

= 1.1.0 =
* Add theme management endpoints (list, install from wordpress.org, activate).
* Add static front-page (homepage) get/set endpoints.

= 1.0.0 =
* Initial release.
