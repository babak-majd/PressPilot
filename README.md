# PressPilot

**An AI copilot for WordPress.** Connect an AI agent *directly* to your site and let it build
and manage everything — pages, posts, native Gutenberg blocks, templates, menus, media, global
styles, plugins, and even **the configuration of other plugins**. No FTP, no SSH, no PHP shell.

---

## What's new in 2.0 — connect agents directly

### 1) MCP — the direct connection (preferred)

PressPilot now serves a **Model Context Protocol** endpoint over Streamable HTTP:

```
https://YOUR-SITE.com/wp-json/presspilot/v1/mcp
```

Point a client at it and your site appears inside it as **native tools** — nothing to paste,
no HTTP to hand-write. Works with **Claude Code**, **OpenAI Codex**, **Cursor / Windsurf /
VS Code**, and any other MCP client.

```bash
# Claude Code — run once
claude mcp add --transport http presspilot \
  https://YOUR-SITE.com/wp-json/presspilot/v1/mcp \
  --header "Authorization: Bearer YOUR-KEY"
```

```toml
# OpenAI Codex — ~/.codex/config.toml
[mcp_servers.presspilot]
url = "https://YOUR-SITE.com/wp-json/presspilot/v1/mcp"
bearer_token_env_var = "PRESSPILOT_KEY"
```

Ready-made snippets carrying your own URL and key live in the dashboard under
**PressPilot → Agents (MCP)**.

The endpoint is **dual-era**: it answers both the legacy `initialize` handshake
(`2024-11-05` … `2025-11-25`) and the modern stateless `server/discover` shape
(`2026-07-28`), so old and new clients both work against the same URL.

> **The Skill travels with the connection.** The plugin's operating manual is handed to the
> agent automatically on connect — as MCP `instructions`, as a resource (`presspilot://skill`),
> and as a tool. That is what makes the output actually work instead of merely looking like
> working code.

### 2) The built-in copilot — without leaving wp-admin

Connect a model directly to the plugin and chat with your site from the dashboard:

| Provider | Notes |
|---|---|
| **Anthropic (Claude)** | Direct from Anthropic |
| **OpenAI** | The same key your Codex CLI uses |
| **OpenRouter** | One key, hundreds of models across vendors |
| **AgentRouter** | OpenAI-compatible gateway across several providers |
| **Custom** | Anything that speaks `/chat/completions` — a local Ollama or vLLM server, LiteLLM, a company gateway |

Go to **PressPilot → Copilot**, add a key and pick a model (*Load available models* pulls the
list from the provider itself, so it is never out of date), and start.

The copilot runs **exactly** the same tools, under the same permissions, following the same
manual as an external agent over MCP. One source of truth, two ways in.

---

## Install

1. Install the plugin zip via **Plugins → Add New → Upload Plugin**, then **Activate**.
2. Go to **PressPilot → Connect**. Your API key is already generated and waiting.
3. Then either **Agents (MCP)** to connect an external agent, or **Copilot** to use the built-in one.

Elementor is optional — native Gutenberg builds work on any theme. When Elementor *is* present,
PressPilot supports it and can **migrate Elementor content to Gutenberg in place**, keeping the
same URLs.

### Test the connection

```bash
curl "https://YOUR-SITE.com/wp-json/presspilot/v1/ping" -H "X-PressPilot-Key: YOUR-KEY"
```

A `{"ok":true,...}` response means everything works. ✅

---

## Security & control

Everything is on by default, but you hold the switches — **PressPilot → Permissions**:

- **API key** compared in constant time, replaceable with one click.
- **Master on/off switch** to cut all access instantly.
- **Optional IP / CIDR allow-list**.
- **Per-capability permissions**: content, media, menus, templates, styles, themes, plugins,
  settings, config. A capability you turn off is not merely refused — **its tools are hidden
  from the agent entirely**, so the model never even reaches for it.
- **PHP code execution** (`/exec`) is **off by default** and must be enabled deliberately.

> Keep the key secret: anyone holding it has whatever access your permissions allow.
> Serve the site over **HTTPS**.

## If `/wp-json/` returns 404

Re-save **Settings → Permalinks** once to refresh the rewrite rules. Failing that, use the
fallback form: `https://YOUR-SITE.com/?rest_route=/presspilot/v1/ping`

If your server strips the `Authorization` header (common on Apache CGI), enable **Key in the
URL** on the *Agents (MCP)* screen and use the `?key=` form instead — note the key then travels
in the URL, where server and proxy logs can record it.

---

## Documentation

- [`docs/API.md`](docs/API.md) — full REST + MCP reference
- [`docs/SKILL.md`](docs/SKILL.md) — the agent operating manual
- `GET /wp-json/presspilot/v1/openapi` — OpenAPI 3.0 spec (public)
- `GET /wp-json/presspilot/v1/skill` — the Skill, served live

---

Licensed GPL-2.0-or-later.

Site: [bobclub.ir](https://bobclub.ir) · Telegram: [@bob_club](https://t.me/bob_club) ·
If it saves you time: [buy me a coffee ☕](https://bobclub.ir/coffee)
