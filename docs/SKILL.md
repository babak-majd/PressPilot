# PressPilot — Agent Skill

This is the operating manual for an AI agent that builds and edits a WordPress site
through this plugin's REST API. It supports **two build modes** — native **Gutenberg
blocks** (no page-builder dependency, preferred for new/clean sites) and **Elementor**
— and can **migrate Elementor → Gutenberg in place**. It encodes the hard rules that
make each actually work. **Read it fully before building.**

---

## 0. Choose a build mode

- **Gutenberg (native blocks)** — no dependency, works on any theme. Send content as a
  structured `blocks` tree or raw block markup. For a block (FSE) theme, build the
  header/footer with `/fse-template-parts` and templates with `/fse-templates`, and put
  global CSS/fonts in `/global-styles` or `/settings/custom-css`. **Prefer this** when the
  goal is "no dependencies / use native elements".
- **Elementor** — only when the site must keep Elementor. Send `elementor_data`.

`GET /site` tells you what's installed: Elementor active?, `active_theme.is_block_theme`,
and the enabled **scopes** (capabilities the admin allowed — a disabled one returns
`403 pp_scope_disabled`).

## 1. Connect

- Base URL: `https://SITE/wp-json/presspilot/v1` (pretty permalinks) or the always-works
  fallback `https://SITE/?rest_route=/presspilot/v1/<path>`.
- Auth: header `X-PressPilot-Key: <API key>` on every request (key is on the plugin's
  settings screen). JSON bodies; send `Content-Type: application/json`.
- **First call every session:** `GET /site`. It reports Elementor + Pro versions,
  the active theme (+ `is_block_theme`), enabled `scopes`, and whether the flexbox
  **container** experiment is on. Build accordingly.

## 1b. Gutenberg content — how to write blocks

`POST/PUT /content` accepts, in priority order:

- **`blocks`** — a structured tree; the plugin serializes it to valid block markup so you
  never hand-write `<!-- wp:… -->` delimiters (mismatched delimiters break the editor):
  ```json
  { "type":"page","title":"About","status":"publish","builder":"gutenberg",
    "blocks":[
      {"blockName":"core/heading","attrs":{"level":1},"innerHTML":"<h1 class=\"wp-block-heading\">About us</h1>"},
      {"blockName":"core/group","attrs":{"layout":{"type":"constrained"}},"innerBlocks":[
        {"blockName":"core/paragraph","innerHTML":"<p>Hello.</p>"}
      ]}
    ] }
  ```
- **`content`** — raw block markup / HTML string (used as-is).

**Wrapping children (native containers).** For a container block that must wrap its children
in a real element (`core/group`, `core/columns`, `core/cover`, `core/column`), give the node
`innerContentOpen` and `innerContentClose` (the wrapper's opening/closing markup); children
are serialized *between* them. Prefer decomposing designs into native blocks (heading, group,
columns, buttons, cover, image) over one big `core/html` block — use `core/html` only for
markup that has no native equivalent (e.g. bespoke gradient/mask backgrounds).

```json
{ "blockName":"core/group","attrs":{"className":"card"},
  "innerContentOpen":"<div class=\"wp-block-group card\">","innerContentClose":"</div>",
  "innerBlocks":[ {"blockName":"core/heading","innerHTML":"<h3>Hi</h3>"} ] }
```

**Speed:** never inline large base64 fonts/images in CSS — host them with `POST /assets/upload`
and reference the returned URL. Use `GET /performance` to check page weight and server health
(TTFB, opcache, object cache, page cache, gzip, autoloaded-option bloat).

Writes are **trusted** by default (API-key gated), so `<style>`, inline SVG and full block
markup are **not** KSES-stripped. Pass `allow_unfiltered_html:false` to force classic KSES
filtering. Discover the exact block vocabulary with `GET /blocks`; list/registered patterns
with `GET /patterns`; register a reusable synced pattern with `POST /patterns`.

## 1c. Elementor → Gutenberg, in place (the migration)

To convert an existing Elementor page and keep the **same URL/slug** (no menu churn):

```
PUT /content/{id}  { "builder":"gutenberg", "blocks":[ … ] }
```

`builder:"gutenberg"` (or `clear_elementor:true`) removes every Elementor meta
(`_elementor_data`, `_elementor_edit_mode`, `_elementor_page_settings`, `_elementor_version`,
cached CSS…) and resets `_wp_page_template` to the theme default, so Elementor stops
hijacking `the_content` and your block content renders. Finish a full migration by
deactivating Elementor via `POST /plugins/deactivate { "slug":"elementor" }`.

## 1d. Know what you're allowed to touch (permissions)

The site admin can turn API capabilities on/off. **Check before you build** so you
don't waste calls on a disabled area:

- `GET /scopes` → every capability group, whether it's `enabled`, and the exact
  endpoints it gates, plus an `always_available` list (discovery + this check).
- `GET /site` also returns a `scopes` map.

Capability groups: `content` (pages/posts), `media`, `menus`, `templates`
(Elementor Theme Builder + FSE + patterns), `styles` (Additional CSS + global
styles), `themes`, `plugins`, `settings` (options/homepage/permalinks).

Default is **everything enabled**. A call into a disabled group returns
`403 pp_scope_disabled` — surface that to the user (they can enable it on the
plugin's *Permissions* screen); don't retry blindly.

## 1e. Verify the Elementor → Gutenberg migration

`GET /migration-status` is the definitive completion check. It returns:

```json
{ "fully_migrated": false,
  "elementor_plugin_active": true,
  "content_using_elementor": { "count": 3, "items": [ { "id":7, "title":"Home", "url":"…" } ] },
  "elementor_library": { "count": 2, "items": [ … ] },
  "posts_with_elementor_meta": 5,
  "next_steps": [ "Convert 3 item(s)…", "Rebuild 2 template(s)…", "Deactivate Elementor…" ] }
```

`fully_migrated` is `true` only when **no content is in Elementor builder mode, no
`elementor_library` entries remain, and the Elementor plugin is deactivated.** Work
the `next_steps` until it flips to `true`, re-checking after each batch.

## 2. KSES (Elementor HTML widget only)

> **Note:** `post_content` writes (`blocks`/`content`) are **trusted** and skip KSES —
> `<style>`, SVG and full block markup survive. The rules below apply specifically to the
> **Elementor HTML widget**, whose `settings.html` still goes through KSES.

Text put into an **Elementor HTML widget** is **not** attributed to a user with the
`unfiltered_html` capability, so WordPress KSES-filters it. Concretely, inside an HTML widget:

| Survives | Stripped |
|---|---|
| `<div> <span> <a> <p> <h1..6> <ul/li> <img> <details> <summary>` | `<style>` |
| inline `style="..."` attributes | `<script>` |
| `class`, `id`, `href` | `<svg> <path>` (inline SVG) |
| | `<input> <textarea> <select> <button> <form>` |
| | `<link>` (external CSS/font) |

Design around it:

- **CSS** → never `<style>` in an HTML widget. Put it in Elementor **Custom CSS**
  (page settings `custom_css`, or the Kit's `custom_css` for site-wide). That is
  compiled into Elementor's own stylesheet and is not KSES-filtered.
- **Inline `style` *values* are filtered too** (`safecss_filter_attr`): `radial-gradient`/
  `linear-gradient`, `mask-image`, `-webkit-mask-image`, `backdrop-filter`, `clip-path` and
  similar are stripped from `style="…"`. Put decorative backgrounds — glows, grid patterns,
  glassmorphism blur — in Custom CSS **classes**, not inline styles.
- **Icons** → not inline `<svg>`. Use **CSS mask** data-URIs in Custom CSS
  (`.ico{-webkit-mask:url("data:image/svg+xml,...") ...;background-color:currentColor}`)
  so icons inherit color, or use a native Elementor Icon widget.
- **Fonts** → not `<link>`. Embed `@font-face { src:url(data:font/woff2;base64,...) }`
  in the Kit Custom CSS for fully-local, no-external-request fonts.
- **Forms** → not raw `<input>`. Use the native **Elementor Pro Form** widget.
- **Interactivity** → no `<script>`. Use CSS-only: `<details>/<summary>` for
  accordions, `:target` + `:has()` for tabs, the checkbox-less approaches.

## 3. Global styles live in the Kit

Put shared CSS + embedded fonts **once** in the active Kit's `custom_css` (Site
Settings) so every page gets them from one cached stylesheet — do **not** repeat a
250 KB font blob in each page. Find the kit id from the `elementor-kit-N` body class
or `GET /globals`; then `PUT /content/{kitId}` with
`elementor_settings.custom_css`.

## 4. Custom CSS flush gotcha

Elementor compiles page `custom_css` into the post CSS file only when that file is
regenerated **after** `custom_css` is in meta. This plugin saves `elementor_data`
(which flushes CSS) before `elementor_settings`, so after setting `custom_css` do a
second `PUT /content/{id}` with just `elementor_data` to force a correct rebuild.
(The reference client does this automatically.)

## 5. Header & footer = Theme Builder, once

Never repeat header/footer markup per page. Create them **once** as global parts:

`POST /template` with `template_type: "header"` (or `"footer"`), `elementor_data`,
and `conditions: ["include/general"]` (whole site). Pages then contain **body
sections only**.

For the global header/footer to render, a page must use a template that outputs the
header/footer locations — the theme default or **Full Width**
(`page_template: "full_width"`). Do **not** use `canvas` for those pages (canvas
strips all header/footer locations). Use `canvas` only for standalone landing pages
that include their own chrome.

## 6. Page template & theme chrome

- `page_template`: `canvas` (no theme header/footer/title), `full_width`
  (theme/Theme-Builder header + footer, no title), or `default`.
- To hide only the title, set page settings `hide_title: "yes"`.

## 7. URLs / permalinks

If the site uses **plain** permalinks, pretty paths 404. Either enable pretty
permalinks — `POST /settings/options { "options": { "permalink_structure":
"/%postname%/" } }` (rebuilds rewrite rules) — or link internally with
`/?pagename=<slug>`, which works under any setting.

## 8. Direction (RTL/LTR)

If the site locale is RTL but the design is LTR (or vice-versa), wrap page content in
a container with `dir` and `direction` set, or force it in Custom CSS. Don't rely on
the site default.

## 9. Everything local

No external CDNs: embed fonts (base64 `@font-face`) and icons (data-URI masks) in
Custom CSS, and turn off Elementor's own Google Fonts with
`POST /settings/options { "options": { "elementor_google_fonts": "0" } }`.

## 10. Elementor element model (quick ref)

```json
{ "elType": "container|widget", "widgetType": "heading|button|html|form|icon-box|...",
  "settings": { ... }, "elements": [ ...children... ] }
```
- You don't set element `id`s — the plugin injects unique 7-char ids.
- Full-bleed section = top-level `container` with `content_width:"full"`, padding 0.
- Learn a widget's exact setting keys with `GET /widgets/{name}`.

## 11. Endpoint map

| Method | Path | Scope | Purpose |
|---|---|---|---|
| GET | `/ping` `/site` `/scopes` `/migration-status` `/performance` `/globals` `/widgets` `/widgets/{name}` `/templates` `/blocks` `/patterns` | — | discovery |
| GET/POST | `/assets` `/assets/upload` | media | host fonts/CSS/SVG as real cacheable files (base64) — avoid inlining big base64 blobs |
| POST | `/batch` | — | run up to 25 sub-requests `{method,path,body}` in one call |
| POST | `/cleanup/elementor-meta` | content | purge leftover `_elementor_*` meta (optionally `revisions_only`) |
| POST | `/forms/submit` (public) · GET/POST `/forms/config` | settings | native contact form → `wp_mail` (honeypot + rate limit) |
| GET/POST | `/content` | content | list / create page or post (`blocks`, `content`, `elementor_data`, `builder`, `clear_elementor`) |
| GET/PUT/DELETE | `/content/{id}` | content | read / update / trash |
| POST | `/patterns` | templates | register a reusable synced pattern (`title`, `blocks`/`content`) |
| GET/POST | `/media` | media | list / sideload image from URL |
| GET | `/taxonomies` | — | terms |
| GET/POST | `/menus` | menus | list / create menu (`name`, `items[]`, `locations[]`) |
| GET/PUT/DELETE | `/menus/{id}` | menus | read / update / delete a menu |
| GET | `/menu-locations` | menus | theme menu locations + assignments |
| GET/POST | `/themes` `/themes/activate` | themes | list / install+activate theme |
| GET | `/plugins` | plugins | list installed plugins |
| POST | `/plugins/install` | plugins | install from repo `slug` or base64 `zip` (+`activate`) |
| POST | `/plugins/{activate\|deactivate\|delete}` | plugins | manage a plugin by `slug` or `file` |
| GET/POST | `/fse-templates` | templates | list / upsert a block-theme `wp_template` |
| GET/POST | `/fse-template-parts` | templates | list / upsert a `wp_template_part` (`area`: header/footer) |
| GET/POST | `/global-styles` | styles | read / merge block-theme theme.json (`settings`,`styles`,`css`) |
| GET/POST | `/settings/custom-css` | styles | read / set Customizer Additional CSS (`css`, `append`) |
| GET/POST | `/homepage` | settings | read / set static front page |
| POST | `/settings/options` | settings | set allowlisted options (permalinks, google fonts, WPLANG, admin_locale, …) |
| POST | `/template` | templates | create/update an Elementor Theme Builder part |
| GET | `/skill` `/openapi` | — | this document / OpenAPI 3.0 spec (public) |

`POST/PUT /content` also accepts `excerpt`, `categories[]`, `tags[]` (posts) and `page_template`.
A disabled **scope** (see the Permissions screen / `GET /scopes`) returns `403 pp_scope_disabled`.

## 16. Gutenberg header/footer, menus, global CSS (no Elementor)

- **Block-theme header/footer:** `POST /fse-template-parts { "slug":"header", "area":"header",
  "blocks":[ …core/site-title, core/navigation… ] }`; same for `"footer"`. Full templates
  (index/single/archive) via `POST /fse-templates { "slug":"single", "blocks":[…] }`.
- **Menus:** `POST /menus { "name":"Primary", "items":[{"title":"Home","page_id":12},
  {"title":"Docs","url":"/docs"}], "locations":["primary"] }`. Items nest via `children[]`.
- **Global CSS & fonts (no Kit):** classic themes → `POST /settings/custom-css
  { "css":"@font-face{…base64…} .btn{…}" }`. Block themes → `POST /global-styles
  { "settings":{…palette/typography…}, "styles":{…}, "css":"…" }`.
- **Plugins:** install a form plugin with `POST /plugins/install { "slug":"...", "activate":true }`;
  at the end of a migration, `POST /plugins/deactivate { "slug":"elementor" }`. (The API refuses
  to deactivate/delete PressPilot itself.)


## 13. Posts, taxonomies & a dynamic blog

- Create real posts with `POST /content { "type":"post", "title", "slug", "excerpt",
  "content", "categories":["Growth"], "tags":[...] }`. Categories/tags are created if missing.
- Build a **dynamic** listing (not hardcoded cards) with a native **Loop Grid**: first
  `POST /template { template_type:"loop-item", conditions:[] , elementor_data:[…card with
  dynamic tags…] }`, then add a `loop-grid` widget with `template_id` = that template id.
- Dynamic field in a widget: put the tag in `settings.__dynamic__`, e.g.
  `"__dynamic__": { "title": "[elementor-tag id=\"aaa\" name=\"post-title\" settings=\"%7B%7D\"]" }`.
  Useful tags: `post-title`, `post-excerpt`, `post-terms` (settings `{"taxonomy":"category"}`),
  `post-date`, `post-url`, `featured-image`.
- Give posts a styled article page with a `single-post` Theme Builder template
  (`conditions:["include/singular/post"]`) using `theme-post-content` + a `post-title`
  dynamic heading.

## 14. Locale / English site on an RTL install

If the design is LTR/English but the WP install is RTL, set the front-end to English while
keeping the admin in the original language:
`POST /settings/options { "options": { "WPLANG": "", "admin_locale": "fa_IR",
"date_format": "M j, Y" } }`. This also makes native widgets (forms, loop grids) render LTR.

## 15. Machine-readable docs & example

- **OpenAPI 3.0** spec: `GET /openapi` (public) — import into Swagger/Postman/an SDK
  generator. `GET /skill` returns this document.
- **Web-service example** — create a page end to end:

```bash
BASE="https://SITE/wp-json/presspilot/v1"; KEY="pp_xxx"
# 1) discover the environment
curl -s "$BASE/site" -H "X-PressPilot-Key: $KEY"
# 2) create a full-width Elementor page
curl -s -X POST "$BASE/content" -H "X-PressPilot-Key: $KEY" -H "Content-Type: application/json" -d '{
  "type":"page","title":"Landing","slug":"landing","status":"publish","page_template":"full_width",
  "elementor_data":[{"elType":"container","settings":{"content_width":"full"},"elements":[
    {"elType":"widget","widgetType":"heading","settings":{"title":"Hello","align":"center"}}
  ]}],
  "elementor_settings":{"hide_title":"yes"}
}'
```

```javascript
// Node / fetch
const BASE = "https://SITE/wp-json/presspilot/v1", KEY = "pp_xxx";
const h = { "X-PressPilot-Key": KEY, "Content-Type": "application/json" };
await fetch(`${BASE}/content`, { method: "POST", headers: h, body: JSON.stringify({
  type: "page", title: "Landing", status: "publish", page_template: "full_width",
  elementor_data: [{ elType: "container", settings: { content_width: "full" }, elements: [
    { elType: "widget", widgetType: "heading", settings: { title: "Hello", align: "center" } }
  ]}]
})}).then(r => r.json());
```
