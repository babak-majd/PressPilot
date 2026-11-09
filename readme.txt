=== PressPilot ===
Contributors: babak-majd
Donate link: https://bobclub.ir/coffee
Tags: mcp, ai, gutenberg, rest-api, multilingual
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 4.0.0
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
* Plugins: install from the WordPress.org repository by slug, activate/deactivate/delete (e.g. remove Elementor after migration).
* Configure plugins through vetted, validated operations: read/write options & meta (every write makes a restore point), structured read-only database queries, curated per-plugin adapters, and read-only discovery of settings and REST routes.
* **Security & maintenance**: read-only scans (executable files in uploads, hardening state, admin accounts, plugin health) and a vetted, reversible catalogue of hardening toggles (disable the file editor, disable XML-RPC, hide the version, block user enumeration, security headers) and cleanup actions.
* Read-only file access under allow-listed roots (debug log, theme/plugin source) for debugging and review.
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
capability you turn off is not just refused, its tools are hidden from the agent entirely. Every
operation is a specific, predefined, validated action with its own capability check — there is no
arbitrary code execution, no arbitrary SQL and no arbitrary file writing. Reads are read-only;
configuration writes create a restore point you can roll back; and the hardening actions are
reversible.

= Which agents can connect? =
Anything that speaks MCP over HTTP — Claude Code, OpenAI Codex, Cursor, Windsurf, VS Code and
others. For the built-in copilot: Anthropic, OpenAI, OpenRouter, AgentRouter, or any endpoint that
speaks OpenAI `/chat/completions` (a local Ollama or vLLM server, LiteLLM, a company gateway).

= My server strips the Authorization header =
Common on Apache CGI. Turn on "Key in the URL" on the Agents screen and use the `?key=` form
instead — note the key then travels in the URL, where server and proxy logs can record it.

== External services ==

PressPilot does not phone home and sends nothing anywhere on its own. It contacts an external
service only in the configurations you set up yourself, and only then:

* **Built-in Copilot (optional).** If you enter a model-provider API key on the Copilot screen and
chat with your site from wp-admin, your messages and the site content needed to answer them are
sent from your server to the provider you choose: Anthropic (`api.anthropic.com`), OpenAI
(`api.openai.com`), OpenRouter (`openrouter.ai`), AgentRouter, or any OpenAI-compatible endpoint
you enter yourself (including a local or self-hosted server). No provider is contacted until you
add a key and use the Copilot. Each provider's own terms and privacy policy then apply — for
example Anthropic (terms: https://www.anthropic.com/legal/consumer-terms , privacy:
https://www.anthropic.com/legal/privacy), OpenAI (terms: https://openai.com/policies/terms-of-use ,
privacy: https://openai.com/policies/privacy-policy), OpenRouter (terms: https://openrouter.ai/terms ,
privacy: https://openrouter.ai/privacy).
* **Installing a plugin or theme by slug (optional).** When you ask PressPilot to install a plugin
or theme from the WordPress.org repository, it is downloaded from WordPress.org
(`api.wordpress.org`, `downloads.wordpress.org`) through WordPress's own installer — the same
request WordPress makes from Plugins → Add New.

When you instead connect an external agent over MCP, the model call is made by that agent on your
own machine or in your own client, not by this plugin.

== Changelog ==

= 4.0.0 =
* **Rebuilt around a fixed set of vetted, predefined operations.** Arbitrary PHP execution (`/exec`), raw SQL, arbitrary database writes, admin-ajax dispatch and the generic REST proxy have been **removed**. In their place: a structured, read-only database query (`/db/query`), read-only file access under allow-listed roots (`/files/list`, `/files/read`), and a security & maintenance engine (`/security/scan`, `/security/actions`, `/security/apply`) with read-only scans and a reversible hardening/cleanup catalogue. Installing a plugin is now by WordPress.org slug only — never an uploaded zip.
* New capability scopes split the old catch-all `config` into `database`, `files`, `security`, `discovery` and `adapters`, each independently toggleable.
* This is a breaking change: agents that relied on `/exec`, `/proxy`, `/db/write`, raw `/db/select` or `/admin-ajax` should use the typed operations, a structured `/db/query`, a curated adapter, or the new security engine instead.

= 3.1.2 =
* The most powerful capabilities now have a dedicated, time-limited control. Running PHP (`/exec`), installing plugins, raw SQL `SELECT` and admin-ajax dispatch are each a separate **red opt-in** on the Permissions screen — off by default, and each one **auto-disables 30 minutes after it is switched on**. Their tool descriptions say the same, and the endpoints refuse the call while the toggle is off.

Older versions are listed in changelog.txt.

== Upgrade Notice ==

= 4.0.0 =
Breaking change. Arbitrary PHP exec, raw SQL, arbitrary DB writes, admin-ajax and the REST proxy are removed in favour of vetted operations: structured read-only DB queries, read-only file access, and a reversible security engine. Plugin installs are by WordPress.org slug only.

= 3.1.2 =
The most powerful capabilities (PHP exec, plugin install, raw SQL, admin-ajax) are now separate red opt-ins that auto-disable 30 minutes after you enable them. The GitHub self-updater is gone — WordPress serves updates.
