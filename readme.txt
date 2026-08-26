=== PressPilot ===
Contributors: babak-majd
Donate link: https://bobclub.ir/coffee
Tags: mcp, ai, gutenberg, rest-api, automation
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude Code, Codex, OpenRouter or AgentRouter straight to WordPress over MCP and let an AI agent build and manage your whole site.

== Description ==

PressPilot turns your WordPress site into something an AI agent can actually drive. Connect an
agent **directly** over MCP — or use the copilot built into wp-admin — and it can build and manage
the whole site remotely: no FTP, no SSH, no PHP shell access.

**Connect an agent directly (MCP).** The plugin serves a Model Context Protocol endpoint at
`/wp-json/presspilot/v1/mcp` over Streamable HTTP, so **Claude Code**, **OpenAI Codex**, **Cursor**
and any other MCP client see your site as native tools — no prompt to paste, no HTTP to hand-write.
The *Agents* screen gives you the exact copy-paste setup for each client. Both MCP eras are
supported on the same endpoint (the legacy `initialize` handshake and the modern stateless
`server/discover` shape), so it works with clients old and new.

**Or use the built-in copilot.** Connect **Anthropic**, **OpenAI**, **OpenRouter**, **AgentRouter**
or any OpenAI-compatible endpoint and chat with your site from wp-admin. Same tools, same
permissions, same rules as an external agent — one source of truth, two ways in.

**The Skill travels with the connection.** The plugin ships an operating manual that is delivered
to the agent automatically on connect (as MCP `instructions`, as a resource, and as a tool). That
is what makes the output actually work rather than merely look like working code.

Features:

* **MCP server** (Streamable HTTP): tools, resources and prompts for any MCP client. Disabled capabilities are hidden from the tool list entirely, so the model never wastes a call on a 403.
* **Built-in copilot** with Anthropic / OpenAI / OpenRouter / AgentRouter / custom OpenAI-compatible providers, and a live model list pulled from the provider.
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
2. Go to **PressPilot > Agents (MCP)** and copy the setup line for your client (Claude Code, Codex, Cursor, …).
3. Or go to **PressPilot > Copilot**, add a model provider API key, and chat with your site from wp-admin.
4. Elementor is optional — native Gutenberg builds work on any theme.

== Frequently Asked Questions ==

= The /wp-json/ URL returns 404 =
Re-save Permalinks (Settings > Permalinks > Save). Or use the fallback form `/?rest_route=/presspilot/v1/...`.

= Is it safe? =
Every endpoint — MCP included — requires the secret API key, compared in constant time. Keep it
private and serve your site over HTTPS; you can regenerate it at any time. The Permissions screen
adds a master on/off switch, an optional IP/CIDR allow-list, and per-capability toggles: a
capability you turn off is not just refused, its tools are hidden from the agent entirely. PHP
code execution (`/exec`) is off by default.

= Which agents can connect? =
Anything that speaks MCP over HTTP — Claude Code, OpenAI Codex, Cursor, Windsurf, VS Code and
others. For the built-in copilot: Anthropic, OpenAI, OpenRouter, AgentRouter, or any endpoint that
speaks OpenAI `/chat/completions` (a local Ollama or vLLM server, LiteLLM, a company gateway).

= My server strips the Authorization header =
Common on Apache CGI. Turn on "Key in the URL" on the Agents screen and use the `?key=` form
instead — note the key then travels in the URL, where server and proxy logs can record it.

== Changelog ==

= 2.0.1 =
* Compatibility verified end to end (activation, MCP handshake, tools/list, a real block-content write through tools/call, and the full copilot loop) on **WordPress 5.8, 6.9.5 and 7.1** across **PHP 7.4, 8.2 and 8.4** — no notices, warnings or deprecations on any combination. Syntax additionally checked on PHP 8.0 and 8.3.
* `Tested up to` raised to 7.1 (previously 7.0), now actually exercised rather than assumed.
* Fix: `GET /agent/models` reported "the provider returned HTTP 200" when a 2xx response body was not JSON — it now says the base URL is likely pointing at a web page rather than an API root.
* Fix: the MCP authentication-failure path read the error status without checking its shape; hardened so the failure path cannot itself raise a warning.
* Remove dead code in the `/agent/config` write handler.

= 2.0.0 =
* **MCP server** — `POST /mcp`, a Model Context Protocol endpoint over Streamable HTTP (JSON-RPC 2.0). Connect Claude Code, OpenAI Codex, Cursor or any MCP client directly; the site shows up as native tools.
  * Dual-era on one endpoint: the legacy `initialize` handshake (2024-11-05 … 2025-11-25) **and** the modern stateless `server/discover` shape (2026-07-28), with spec-correct version negotiation (`-32022`) and header/body validation (`-32020`).
  * Tools, resources (`presspilot://skill`, `presspilot://site`, `presspilot://openapi`) and prompts (`build_site`, `migrate_to_gutenberg`, `configure_plugin`, `audit_site`).
  * The agent Skill is delivered on connect as MCP `instructions`, so agents follow the site's build rules from their first message.
* **Built-in copilot** — connect Anthropic, OpenAI, OpenRouter, AgentRouter or any OpenAI-compatible endpoint and chat with your site from wp-admin. `GET /agent/models` pulls the live model list from the provider; `POST /agent/step` runs one round trip (the browser drives the loop, so no request can hit max_execution_time) and `POST /agent/run` runs the whole loop server-side for headless callers.
* **One tool registry** behind both surfaces (`GET /tools`, `POST /tools/call`) — every tool is a thin wrapper over an existing REST route, dispatched internally, so handlers, validation and capability scopes stay the single source of truth.
* **Permissions carry over**: a tool whose capability scope is disabled is omitted from `tools/list` entirely rather than failing at call time. New "Essential/Full" tool-profile setting trims the surface for smaller models.
* New admin screens: **Agents (MCP)** (endpoint, per-client copy-paste setup, tool inventory) and **Copilot** (provider, model, chat).
* Optional `?key=` URL auth for clients that cannot send custom headers (off by default).

= 1.9.0 =
* **Universal reach** — configure virtually any plugin, whatever it stores config in:
  * Custom DB tables: GET `/db/tables` `/db/describe`, POST `/db/select` (structured or read-only raw SELECT) and `/db/write` (insert/update/delete) with dry-run, affected-row cap, before-image capture, and a core-table guard.
  * admin-ajax: POST `/admin-ajax` dispatches a `wp_ajax_{action}` handler as admin (for plugins that only save via ajax) and returns the decoded output.
  * Escape hatch: POST `/exec` runs PHP — **off by default**, enabled only via the `presspilot_allow_exec` option / `PP_ALLOW_EXEC` constant (equal in power to the plugin-install the key already allows).
* Together with 1.8.0's options/meta/terms/proxy/discovery/adapters, this covers options, meta, terms, custom tables, a plugin's own REST API, admin-ajax, and (opt-in) arbitrary code.
* Permissions screen: the new `config` scope appears automatically, plus a dedicated (red, off-by-default) **"Allow code execution"** toggle for `/exec`. Full docs: SKILL §12, API.md, and the OpenAPI spec (`/openapi`) now cover every config endpoint.

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
