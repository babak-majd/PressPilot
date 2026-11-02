# WordPress.org directory assets

These images are for the **WordPress.org plugin page**, not the plugin itself.
They are NOT shipped in the plugin zip (`build.sh` excludes `assets/`). At publish
time they go in the **top-level `assets/` folder of the SVN repo** (next to
`trunk/` and `tags/`), e.g. `svn co https://plugins.svn.wordpress.org/presspilot/`
then commit these under `assets/`.

Files here now (starter art — replace with final branding when ready):

| File | Size | Purpose |
|------|------|---------|
| `icon-128x128.png` | 128×128 | plugin icon |
| `icon-256x256.png` | 256×256 | plugin icon (high-DPI) |
| `icon.svg` | vector | plugin icon (a PNG fallback is still required, hence the two above) |
| `banner-772x250.png` | 772×250 | plugin header banner |
| `banner-1544x500.png` | 1544×500 | banner (high-DPI; only works alongside the 772×250) |

Still to add (optional, improve the listing):

* `screenshot-1.png`, `screenshot-2.png`, … — one per numbered line in a
  `== Screenshots ==` section of `readme.txt`. Capture them from the admin
  screens (Connect, Agents/MCP, Copilot, Permissions). Filenames must be
  lowercase; links to external files do not work.

Limits: icons ≤ 1 MB, banners ≤ 4 MB, screenshots ≤ 10 MB. After committing to
SVN, if an image downloads instead of displaying, set its MIME type, e.g.
`svn propset svn:mime-type image/png assets/*.png`.
