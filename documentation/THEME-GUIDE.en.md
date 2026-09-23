# FPDP Template Theme Guide — English

## 1. Overview

FPDP has a simple, folder-based template ("theme") system. Each node has one active theme (`nodes.theme` column, default `default`), and the owner can switch it anytime from **Dashboard → Template** (`/dashboard/themes`) with no redeploy required.

Themes are installed by **copying a folder into `/themes/` on the server** — not by uploading a file through the browser. That's deliberate: the dashboard never accepts or executes code from the outside, so this feature adds no new remote-code-execution surface. Anyone who can place a folder under `/themes/` already has file-level access to the server (FTP/SSH/hosting file manager) — the same trust level as editing any other PHP file in their own FPDP install.

## 2. Theme folder layout

```
themes/
  your-theme-slug/
    theme.json          (required)
    views/               (optional — anything not provided falls back to the core template)
      profile.php
      about-me.php
      youtube.php
      wall-coretan.php
      post.php
      public-cv.php
    assets/              (optional)
      theme.css
      (fonts, images, etc. — static files only)
```

The folder name (`your-theme-slug`) is the theme's **slug**: lowercase letters, digits, `-`, `_` only (`^[a-z0-9][a-z0-9_-]{0,63}$`).

### 2.1 `theme.json`

```json
{
    "name": "Your Theme Name",
    "description": "Short description shown in the dashboard.",
    "author": "Your name",
    "version": "1.0.0",
    "preview_color": "#185f48"
}
```

All fields are optional (fall back to the slug / `1.0.0` / a default green), but fill them in so your theme is easy to identify in the picker.

### 2.2 Overridable views

Only these exact filenames are recognized as public-page overrides. Anything else under `views/` is ignored:

| File | Page |
|---|---|
| `profile.php` | Public profile / home (`/`, `/@handle`) |
| `about-me.php` | About Me page |
| `youtube.php` | YouTube videos page |
| `wall-coretan.php` | Public wall/coretan page |
| `post.php` | Single post page |
| `public-cv.php` | Public CV page |

**You don't have to provide all of them.** A page with no override file automatically renders with the core template (`app/Views/{name}.php`, the "Default" theme). This means a theme can start small — e.g. override just `profile.php` — and grow incrementally.

Each view receives the same variables as its core counterpart (see `app/Controllers/ContentPageController.php` for the variable list per page, e.g. `$profile`, `$posts`, `$post`, `$title`). Views are plain PHP files — write HTML directly like the core views under `app/Views/`, and escape every piece of user data with `htmlspecialchars(...)` (required, to avoid XSS).

**Shared partial:** call `\App\Core\View::partial('post-card', ['post' => $post])` to render a post card — this is the same core partial every theme (including the default) uses, with escaping and media handling already exercised in tests. Your theme only needs to restyle its CSS classes (`.post-card`, `.post-meta`, `.post-content`, `.post-media`, `.media-link`) in your own `theme.css`, without re-implementing the logic.

### 2.3 Assets (`assets/`)

Everything under `assets/` is publicly reachable at:

```
/themes/<slug>/assets/<filename>
```

E.g. `themes/your-theme-slug/assets/theme.css` → `/themes/your-theme-slug/assets/theme.css`. Supported extensions: `css`, `js`, `png`, `jpg`/`jpeg`, `svg`, `webp`, `gif`, `woff`/`woff2`, `ttf`. PHP files under `views/` are **never** served over HTTP — only files under `assets/` are public.

Link your CSS from a view with a normal tag:

```html
<link rel="stylesheet" href="/themes/your-theme-slug/assets/theme.css">
```

## 3. Two ways to build a theme

**A. Metadata-only theme (no overrides)**

Just create `themes/your-theme-slug/theme.json`. With no `views/` folder, it renders every page with the core template — useful as a starting point before you add overrides, or as a placeholder entry in the picker. This is exactly how the built-in **default** theme is defined.

**B. Theme with its own look**

Copy a core view (e.g. `app/Views/profile.php`) to `themes/your-theme-slug/views/profile.php` as a starting point, then:

1. Change `<link rel="stylesheet" href="/assets/app.css">` to `<link rel="stylesheet" href="/themes/your-theme-slug/assets/theme.css">`.
2. Rework the nav/hero/layout markup however you like — the variables (`$profile`, `$posts`, etc.) stay the same as the core version.
3. Write `themes/your-theme-slug/assets/theme.css` from scratch, or copy `public/assets/app.css` as a starting point and change colors/fonts/spacing.

## 4. Three built-in themes as live examples

Look at `themes/default/`, `themes/editorial/`, and `themes/minimal/` in this repository:

- **default** — has no `views/` at all; always falls back to the core template. The simplest valid theme (just `theme.json`).
- **editorial** — overrides `profile.php` with a magazine look: cream background, large serif headlines, a maroon accent.
- **minimal** — overrides `profile.php` with a clean monochrome look: black/white, sans-serif, hairline borders.

Both `editorial` and `minimal` show the full pattern: their own nav, their own hero, the federation "how to follow" panel, then the shared `post-card` partial for the post feed.

## 5. Installing a theme someone else made

1. Get the theme folder (usually shared as a `.zip`).
2. Extract it locally and confirm the layout is `<slug>/theme.json` at the top of that folder.
3. Copy that folder into `/themes/` on your FPDP server — via FTP, your hosting file manager, or `scp`/`rsync` over SSH. The end result must be reachable as `themes/<slug>/theme.json` on the server.
4. Open **Dashboard → Template** (`/dashboard/themes`) and reload — the new theme appears in the list automatically.
5. Click **Activate** on the theme you want. It takes effect immediately, no server restart needed.

There is intentionally no browser upload step — see section 1 for why.

## 6. Selecting the active theme via the API

Besides the dashboard, the owner can use the API directly (bearer token required):

```
GET  /api/v1/me/themes            → installed themes + the currently active slug
PATCH /api/v1/me/theme            → body: {"slug": "editorial"}
```

## 7. Security & limits

- Theme slugs and view/asset filenames are validated against a strict allow-list (path traversal, unknown extensions, and view filenames outside the 6 allowed names are all rejected).
- A theme cannot change API endpoints, authentication, or dashboard pages — only the 6 public pages listed above.
- Because theme views are plain PHP files that get `require`d, a theme has exactly the same level of access as FPDP's own core code (the variables passed in, PHP's global functions, etc). **Only install themes from sources you trust**, the same way you would any other third-party code on your own server.
