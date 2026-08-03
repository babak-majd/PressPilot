# PressPilot — API Reference

Base URL: `https://SITE/wp-json/presspilot/v1`
Fallback (no pretty permalinks): `https://SITE/?rest_route=/presspilot/v1/<path>`

**Auth (every request):** header `X-PressPilot-Key: <key>` (or `Authorization: Bearer <key>`).
All bodies are JSON; send `Content-Type: application/json`.

Builds two ways: **native Gutenberg blocks** (no page-builder dependency) and **Elementor**,
and can **migrate Elementor → Gutenberg in place**. Each route belongs to a capability
**scope** the site admin can toggle on the plugin's **Permissions** screen; a request to a
disabled scope returns `403 pp_scope_disabled`.

---

## Diagnostics
| Method | Path | Purpose |
|---|---|---|
| GET | `/ping` | Health check. Returns `{ ok, version, time }`. |
| GET | `/site` | WP/PHP versions, active theme (+ `is_block_theme`), Elementor + Pro status & versions, enabled `scopes`, whether the **flexbox `container` experiment** is on, and the plugin list. **Call this first each session.** |
| GET | `/scopes` | The capability groups, whether each is `enabled`, and the endpoints each one gates (self-documenting permissions). A disabled scope returns `403 pp_scope_disabled`. |
| GET | `/migration-status` | Elementor → Gutenberg completion check: `fully_migrated`, `elementor_plugin_active`, `content_using_elementor{count,items}`, `elementor_library{count,items}`, `posts_with_elementor_meta`, and `next_steps[]`. |
| GET | `/performance?url=` | Speed diagnostics: server (opcache, object cache, gzip, autoloaded-option bloat), response `time_ms`/`html_kb`, `page_weight` (inline CSS, base64), and prioritized `recommendations[]`. |

### Assets, batch, cleanup, forms (v1.7)
| Method | Path | Purpose |
|---|---|---|
| GET/POST | `/assets`, `/assets/upload` | List / write a base64 asset (font/CSS/SVG/image) to `uploads/presspilot-assets` and get its URL — host fonts as cacheable files instead of inline base64. Body: `filename`, `base64`. |
| POST | `/batch` | Run up to 25 sub-requests in one call. Body: `{requests:[{method,path,body}]}`; each still passes its own scope check. |
| POST | `/cleanup/elementor-meta` | Purge leftover `_elementor_*` meta (`revisions_only:true` for the safe subset). |
| POST | `/forms/submit` | **Public** contact-form submit → `wp_mail`. Anti-spam: honeypot `pp_hp`, min-time `_t`, per-IP rate limit. Body: `fields{}` (or a flat map). |
| GET/POST | `/forms/config` | Recipient, subject, success message, rate limits. |

**Security (Permissions screen / options):** a master **API on/off** switch (`503 pp_api_disabled` when off) and an optional **IP/CIDR allow-list** (`403 pp_ip_blocked`; empty = allow all).

### Permissions (scopes)

Every write route belongs to a scope the admin toggles on the plugin's **Permissions** screen (default: all on). Read `GET /scopes` to see the live state and the endpoints each gates:

| Scope | Gates |
|---|---|
| `content` | `/content`, `/content/{id}` |
| `media` | `/media` |
| `menus` | `/menus`, `/menus/{id}`, `/menu-locations` |
| `templates` | `/template`, `/fse-templates`, `/fse-template-parts`, `/patterns` (POST) |
| `styles` | `/global-styles`, `/settings/custom-css` |
| `themes` | `/themes`, `/themes/activate` |
| `plugins` | `/plugins`, `/plugins/install`, `/plugins/{activate\|deactivate\|delete}` |
| `settings` | `/settings/options`, `/homepage` |

Discovery routes (`/ping`, `/site`, `/scopes`, `/migration-status`, `/globals`, `/widgets`, `/templates`, `/blocks`, `/patterns` GET, `/taxonomies`, `/skill`, `/openapi`) are always available.

## Gutenberg content

`POST/PUT /content` accepts (priority order) `blocks` → `content` → `elementor_data`.

| Field | Purpose |
|---|---|
| `blocks` | Structured block tree `[{ blockName, attrs, innerHTML, innerBlocks[] }]`, serialized server-side to valid block markup. |
| `content` | Raw block markup / HTML string. |
| `builder` | `"gutenberg"` (a.k.a. `block`) detaches the post from Elementor before writing (in-place migration). |
| `clear_elementor` | `true` — same effect as `builder:"gutenberg"`. |
| `allow_unfiltered_html` | Default `true` (trusted, API-key gated — `<style>`/SVG survive). Set `false` to force classic KSES. |

| Method | Path | Purpose |
|---|---|---|
| GET | `/blocks` | Registered block types (`name`, `title`, `category`, `attributes`). |
| GET | `/patterns` | Registered block patterns. |
| POST | `/patterns` | Register a reusable synced pattern. Body: `title`, `blocks`/`content`. Returns a `<!-- wp:block {ref} /-->` reference. |

## Content (pages & posts)
| Method | Path | Purpose |
|---|---|---|
| GET | `/content?type=page&search=&status=any&per_page=30` | List content. `type` = any post type (`page`, `post`, …). |
| GET | `/content/{id}` | Full item incl. `elementor_data` (parsed tree), `elementor_settings`, `is_elementor`, `content_raw`. |
| POST | `/content` | Create. Body: `type`, `title` (required), `status`, `slug`, `blocks`/`content`/`elementor_data`, `builder`, `clear_elementor`, `page_template`, `categories[]`, `tags[]`. |
| PUT/PATCH | `/content/{id}` | Update any of the above. Only fields you send change. Use `builder:"gutenberg"` to migrate an Elementor page in place. |
| DELETE | `/content/{id}?force=false` | Trash (or permanently delete with `force=true`). |

> Writing `elementor_data` automatically flips the post into Elementor **builder mode**, assigns any
> missing element IDs, stores the JSON correctly (slashed), and regenerates the Elementor CSS cache so the
> change renders on the front-end immediately.

## Elementor discovery
| Method | Path | Purpose |
|---|---|---|
| GET | `/widgets` | All registered widgets: `name`, `title`, `categories`, `keywords`, `is_pro`. Pro widgets appear only when Pro is active. |
| GET | `/widgets/{name}` | Simplified control schema for one widget: each control's `name`, `type`, `label`, `default`, and `options`. Use to learn valid `settings` keys. |
| GET | `/globals` | Active Kit's global colors (`system_colors`, `custom_colors`) and typography — reuse the site's brand values. |
| GET | `/templates` | Saved Elementor library templates (`id`, `title`, `type`). |

## Media
| Method | Path | Purpose |
|---|---|---|
| GET | `/media?per_page=30` | Recent image attachments (`id`, `title`, `url`). |
| POST | `/media` | Sideload an image from a URL into the media library. Body: `url` (required), `title`, `alt`. Returns `{ id, url }` — use the `id`/`url` in image widgets. |

## Site structure & navigation menus
| Method | Path | Purpose |
|---|---|---|
| GET | `/taxonomies` | Public taxonomies + terms (categories, tags, …). |
| GET | `/menus` | Navigation menus (`id`, `name`, `count`). |
| POST | `/menus` | Create (or reuse by name) a menu. Body: `name`, `items[]`, `locations[]`. Items: `{ title, page_id\|post_id\|url\|category_id, children[] }`. |
| GET | `/menus/{id}` | One menu with its items and assigned locations. |
| PUT | `/menus/{id}` | Rename / replace `items` (or `append:true`) / reassign `locations`. |
| DELETE | `/menus/{id}` | Delete a menu. |
| GET | `/menu-locations` | Theme menu locations and what is assigned to each. |

## Block-theme (FSE) templates & global styles
| Method | Path | Purpose |
|---|---|---|
| GET | `/fse-templates` | Customized `wp_template`s for the active theme. |
| POST | `/fse-templates` | Upsert a template. Body: `slug` (index/single/archive/…), `blocks`/`content`, `title`, `description`. |
| GET | `/fse-template-parts` | `wp_template_part`s (header/footer). |
| POST | `/fse-template-parts` | Upsert a part. Body: `slug`, `area` (`header`/`footer`), `blocks`/`content`. |
| GET | `/global-styles` | Block-theme user global styles (theme.json) + resolved custom CSS. |
| POST | `/global-styles` | Merge `settings`, `styles`, and/or `css` into the theme's global styles. |
| GET/POST | `/settings/custom-css` | Read / set Customizer **Additional CSS** (`css`, `append`) — works on classic *and* block themes; KSES-free home for global CSS + base64 fonts. |

## Themes, plugins & homepage
| Method | Path | Purpose |
|---|---|---|
| GET | `/themes` | Installed themes (`slug`, `name`, `version`, `active`). |
| POST | `/themes/activate` | Activate a theme. Body: `slug`, `install` (default `true`). |
| GET | `/plugins` | Installed plugins (`file`, `slug`, `name`, `version`, `active`, `update`). |
| POST | `/plugins/install` | Install from repo `slug` or base64 `zip`; optional `activate`. |
| POST | `/plugins/{activate\|deactivate\|delete}` | Manage a plugin by `slug` or `file`. (Refuses to disable/delete PressPilot itself.) |
| GET | `/homepage` | Front-page config. |
| POST | `/homepage` | Set a static front page. Body: `page_id` (required), `posts_page_id` (optional). |

---

## Elementor element model (cheat-sheet)

Each node in `elementor_data`:

```json
{
  "elType": "section | column | container | widget",
  "widgetType": "heading",            // only when elType = "widget"
  "settings": { "...": "..." },        // widget/element options
  "elements": [ /* children */ ]
}
```

- **Classic layout:** `section` → `column` (`settings._column_size` 1–100) → `widget`s.
- **Flexbox layout (when `/site` shows `container_experiment: true`):** `container` → `container`/`widget`.
- You do **not** need to supply `id`s — the server injects unique 7-char ids for any node missing one.
- Common widgets: `heading` (`title`, `header_size`, `align`, `title_color`), `text-editor` (`editor` HTML),
  `button` (`text`, `link.url`, `align`), `image` (`image.id`, `image.url`), `icon-list`, `spacer`, `divider`.
- For exact setting keys of any widget, call `GET /widgets/{name}` first.

### Minimal create example
```bash
curl -X POST "https://SITE/wp-json/presspilot/v1/content" \
  -H "X-PressPilot-Key: KEY" -H "Content-Type: application/json" -d '{
    "type":"page","title":"Landing","status":"publish",
    "elementor_data":[{"elType":"section","elements":[
      {"elType":"column","settings":{"_column_size":100},"elements":[
        {"elType":"widget","widgetType":"heading","settings":{"title":"Hello","align":"center"}}
      ]}
    ]}]
  }'
```
