# Nano Cart — Architecture

This document explains how Nano Cart's pieces fit together at runtime: layers, files, behaviours, conventions, and the rationale behind each. It is the architectural companion to [FORMAT.md](FORMAT.md). FORMAT.md says what is written to disk; ARCHITECTURE.md says how the system runs.

---

## 1. Three-layer architecture

Nano Cart is organised into three layers, mirroring Nano CMS's pattern. Each layer has a distinct lifecycle and security posture.

### Frontend — permanent, in the webroot

`/shop/` contains the renderers (`index.php`, `category.php`, `product.php`), the loader (`core.php`), the host-site wrapper (`template.php`), the URL rewriter (`.htaccess`), and the assets (`nano-cart.css`, `nano-cart.js`, `lib/Parsedown.php`). This layer runs on every page request. It reads JSON files from `products/` and `categories/`, reads `config.json` from outside the webroot, and renders HTML.

The frontend has no write paths. It cannot mutate JSON, upload images, or change config. A compromised frontend can only leak what is already public.

### Admin — ephemeral, uploaded when needed

`/shop/admin/` contains the operator UI: login, dashboard, product CRUD, category CRUD, settings, image manager, licence page. It is uploaded via SFTP when the operator wants to make changes, used, and then removed. **The recommended posture is "admin not on server unless actively editing"**: an admin folder that does not exist cannot be brute-forced, fingerprinted, or exploited.

The admin reads and writes the same JSON files the frontend reads, plus uploads to `media/`, plus updates `config.json` and `rate-limit.json`.

### Config — outside the webroot

`/path/to/shop-config/` holds `config.json` (settings, bcrypt password hash, licence key) and `rate-limit.json` (per-IP login backoff state). This directory must be readable and writable by the PHP process but never web-accessible. The path is set in `bootstrap.php`.

Placing the password hash and licence key outside the webroot means a webserver misconfiguration that exposes raw files (e.g. PHP execution disabled) cannot leak credentials.

---

## 2. File responsibilities

### Frontend PHP files

**`core.php`** — the loader and helper library. Reads JSON files for products, categories, and config; renders markdown via Parsedown; constructs canonical URLs at render time; resolves image paths to variant URLs; builds breadcrumb structures; emits SEO metadata blocks (title, OG, Twitter, JSON-LD). Every other frontend file requires `core.php`. Functions are namespaced with the `nano_cart_` prefix.

**`index.php`** — the shop homepage. Reads featured products (`featured: true`), the hero product (`hero_featured: true`), and all categories sorted by `sort_order`. Renders a default layout of hero + category grid + featured grid. Operators can replace this file with a custom homepage; the file is operator-owned, not framework-owned.

**`category.php`** — the category page renderer. Receives `?category=<slug>` from the rewrite, loads the category JSON, loads all published products with matching `category`. Renders breadcrumb + category header (banner + description) + product grid.

**`product.php`** — the product page renderer. Receives `?category=<slug>&product=<slug>`, loads the product JSON, validates the category matches (a mismatched URL returns 404 rather than serving the product under a wrong category — important for canonical-URL integrity). Renders breadcrumb + image gallery + title + price + buy button + long description.

**`template.php`** — the per-site HTML wrapper. Provides the `<html>`, `<head>`, `<body>`, site header, site footer. The renderers expose variables (`$nano_cart_title`, `$nano_cart_head`, `$nano_cart_content`, `$nano_cart_footer`) that the template inserts. Operators customise this file to match their host site's existing chrome — Nano Cart does not render site navigation, only its own content.

**`bootstrap.php`** — per-site configuration paths. Defines constants for the config directory location, the products directory, the categories directory, the media directory. Operators copy `bootstrap.example.php` to `bootstrap.php` and edit once at install time.

**`generators.php`** — sitemap generation. Single function `nano_cart_generate_sitemap()` that walks products and categories, writes `sitemap.xml`. Called by admin on every save (admin operations require it; the frontend does not).

**`licence.php`** — Ed25519 licence verification. Defines the embedded Digital Fracture public key, the `nano_verify_licence($key, $host)` function, and the `nano_is_dev_host($host)` development-domain bypass. Returns true if the footer should be hidden, false if the attribution should render. See §11.

**`nano-preflight.php`** — first-run setup check. Detects whether `config.json` exists. If not, redirects to `/shop/admin/setup.php`. Provides a friendly error page if the admin folder isn't present either.

**`.htaccess`** — URL rewriting rules, HTTPS enforcement, trailing-slash canonicalisation, no-directory-listing.

### Admin scope

The admin is described here at category level rather than file-by-file (Session 3 enumerates files):

- **Login + session management** — bcrypt password hash from `config.json`, HttpOnly Secure SameSite=Strict cookies, CSRF tokens on every POST, rate-limited via exponential backoff per-IP, never hard lockout (see §9).
- **Product list and CRUD** — table view, create/edit/delete forms, JSON validation, hero_featured uniqueness enforcement on save (see §12).
- **Category list and CRUD** — same pattern as products. Delete checks for products referencing the category and warns or offers bulk-reassign.
- **Image manager** — drag-drop multi-image upload, gallery view, drag-to-reorder, alt text editing, primary image selection, subfolder support (one level), three-size thumbnail generation pipeline. Built in Session 4.
- **Settings** — form-based editor for `config.json` fields. Validates ranges (image quality 60-95, etc.). Updates and writes back atomically.
- **Licence page** — paste licence key, verify against current host, save to config or report failure with reason.
- **Setup wizard** — first-time deployment flow that runs only when no `config.json` exists. Walks the operator through setting a password, choosing shop mode, entering site name and URL, choosing currency. Includes a use-case advisory panel (see §12).

---

## 3. Mobile-first design principles

Nano Cart's templates are designed for a 375px viewport first and progressively enhanced for larger screens. This isn't just a CSS choice — it shapes information architecture.

- **Single column at 375px.** Image, title, price, buy button, description stack vertically. The buy button is reachable with one thumb without scrolling on most pages.
- **Sticky buy button on mobile.** Once the user scrolls past the product hero, the buy button appears fixed at the bottom of the viewport. Conversion-positive: the call-to-action is never out of reach.
- **Scroll-snap gallery on mobile.** The product image gallery uses native CSS scroll-snap for swipe behaviour. No JavaScript carousel library, no third-party dependency.
- **Tap targets ≥ 44px.** All interactive elements (buy button, gallery thumbnails, category cards) meet Apple's minimum touch target. Reduces mis-taps on small screens.
- **No hover-dependent interactions.** Anything important reachable by hover on desktop must also be reachable by tap on mobile. No hover-only dropdowns, no hover-only tooltips with critical information.
- **Admin works on mobile.** The admin layout collapses cleanly to a phone screen so emergency edits (a typo in a product description, a wrong price) don't require finding a laptop.
- **Page weight target ≤ 500KB first paint** — HTML + critical CSS + hero image. Additional gallery images lazy-load on intersection. Aggressive, but achievable on a hand-written stack with no framework runtime.

---

## 4. SEO architecture

SEO is treated as a core output, not an optional plugin. Every page Nano Cart renders satisfies the contract in FORMAT.md §7 unconditionally.

- **Metadata is server-side.** Title, meta description, OG tags, Twitter card, JSON-LD all emitted in the initial HTML response. No JavaScript is required for crawlers to see them. Google and other crawlers reliably get the full SEO payload on the first request.
- **JSON-LD is core output, not a feature.** Every product page emits a `Product` schema. Every category and product page emits a `BreadcrumbList`. There is no toggle to disable structured data.
- **Image alt text is required at the data layer.** The admin form rejects an image with empty `alt`. The renderer treats missing alt as a bug, not a fallback to render. This guarantees images contribute positively to SEO and accessibility rather than being noise.
- **Sitemap auto-regenerates on every admin save.** Operators can't forget to regenerate. The sitemap is always current. New products appear in `/shop/sitemap.xml` the moment they are saved, ready for the next crawler visit.
- **Canonical URLs are computed at render time.** The frontend constructs canonical URLs from `site_url + shop_path + slugs` on every request. Canonicals are never stored. Moving the shop to a different domain or path requires zero data migration — canonicals update automatically.
- **Clean URLs end-to-end.** `mod_rewrite` produces the canonical URL the user sees, the canonical URL the renderer computes, and the canonical URL Google indexes. No query strings, no duplicate-content trap.

---

## 5. Visual design and card behaviour

The default `nano-cart.css` ships a neutral light-mode palette (whites, near-whites, dark grey text, restrained accent colour for buttons and link hover). This is **not** the cyberpunk marketing palette of nanocart.co.uk — that palette is for the marketing site only. Each operator overrides the default CSS (or its variables) to match the client's brand.

### Card hover behaviour

Product cards (and optionally category cards) lift on hover:

- Box-shadow grows from minimal resting state to a soft elevated shadow (`0 8px 24px rgba(0,0,0,0.12)`).
- Transform: `translateY(-2px)` for a subtle lift.
- Transition: `transform` and `box-shadow` at 200ms ease-out.
- Title link colour shifts to a slightly darker grey.
- When the card has an image, the image scales to 1.02x.

These effects are wholly in CSS, no JavaScript. The Session 6 polish pass tunes the values.

### Image loading

Images use an IntersectionObserver pattern: `data-src` attribute holds the real URL, `src` initially empty (or a 1×1 placeholder). When the image scrolls within ~200px of the viewport, the JS sets `src`, adds a `nano-cart-loaded` class on load, and transitions opacity from 0 to 1. Until then, a CSS gradient shimmer animates over the image's reserved space (width and height are known from the JSON, so the layout doesn't shift).

Broken images get a fallback: the shimmer hides cleanly and a placeholder takes the space, no broken-image icon.

Card structural reference: OGXbox.co.uk uses an `.rv-card` system with the same hover-and-lift behaviour. Nano Cart's `nano-cart-card` system mirrors it structurally, retuned for product photography.

---

## 6. CSS class naming

**All CSS classes use the prefix `nano-cart-` exclusively.** Examples:

- `nano-cart-card`, `nano-cart-card-image`, `nano-cart-card-body`, `nano-cart-card-title`, `nano-cart-card-price`
- `nano-cart-breadcrumb`, `nano-cart-breadcrumb-item`, `nano-cart-breadcrumb-separator`
- `nano-cart-buy-button`, `nano-cart-buy-button-sticky`
- `nano-cart-gallery`, `nano-cart-gallery-main`, `nano-cart-gallery-thumb`
- `nano-cart-category-header`, `nano-cart-category-banner`, `nano-cart-category-description`
- `nano-cart-product`, `nano-cart-product-meta`, `nano-cart-product-description`
- `nano-cart-footer`, `nano-cart-footer-attribution`

Admin classes use a distinct prefix: `nano-cart-admin-` (e.g. `nano-cart-admin-form`, `nano-cart-admin-markdown-editor`). This separates admin styles from frontend styles cleanly — the two CSS files never need to coexist on the same page.

**Never use the shorter `cart-` prefix.** "Cart" is a common naming pattern across the web, and `cart-button` or `cart-item` would collide with host site styles, third-party widgets, and analytics scripts.

CSS variables follow the same convention: `--nano-cart-accent`, `--nano-cart-text`, `--nano-cart-border`, `--nano-cart-shadow`. Operators retheme the entire shop by overriding these variables in one place.

---

## 7. Image fitting controls

Operators control how main images fit their space — width, height, fit mode. Everything else (borders, radius, shadows, backgrounds, hover effects, transitions, loading shimmer) is handled by `nano-cart.css` and applies shop-wide.

| Image | Operator controls (per item) | Shop-wide (via config) |
|-------|------------------------------|------------------------|
| Product main image | `image_width`, `image_height`, `image_fit` | — |
| Category banner | `image_width`, `image_height`, `image_fit`, `image_position` | — |
| Card thumbnails | — | `card_image_height`, `card_image_fit` |

The separation matters: **dimensional decisions are per-item** (a landscape product photo needs different framing from a portrait one). **Visual styling decisions are shop-wide** (uniform borders and shadows across all cards reads as polish, varying them per product reads as chaos).

Card thumbnails are deliberately not per-item — uniform thumbnail framing across the category grid is what gives a shop its "shop-ness". Per-product thumbnail tweaks would undermine that.

---

## 8. Image pipeline

Every upload runs through a six-step pipeline. The pipeline is built in Session 4 with about 80-100 lines of PHP — matches Nano CMS's image manager density.

1. **Receive upload.** Validate mime type AND image header bytes (not just extension — a `.jpg` extension on a PHP file is rejected by checking the magic bytes).
2. **EXIF strip.** Privacy: prevents leaking camera details, GPS coordinates, embedded thumbnails of unrelated photos.
3. **Re-encode through GD or Imagick.** Security hardening: kills any embedded payload (steganographic PHP, malformed structures targeting image-parser vulnerabilities). The decoded pixel data is re-encoded as a clean image.
4. **Generate three size variants plus WebP equivalents.** Six output files per upload (3 sizes × 2 formats), plus the cleaned-and-re-encoded original.
5. **Store with suffix naming.** See FORMAT.md §5 for the exact convention.
6. **Save metadata.** Alt text (operator-provided), dimensions, paths into the product JSON.

GD is built into PHP — no external dependencies. Imagick is available as a fallback for environments where GD is missing or restricted.

Templates use the `<picture>` element with the WebP source listed first and the JPEG as fallback:

```html
<picture>
  <source type="image/webp" srcset="...-hero-800.webp">
  <img src="...-hero-800.jpg" alt="..." loading="lazy" width="800" height="600">
</picture>
```

Browsers that support WebP load the smaller WebP file; legacy browsers fall back to JPEG automatically.

---

## 9. Admin authentication and rate limiting

Login uses a bcrypt password hash stored in `config.json` outside the webroot. CSRF tokens are required on every POST. Sessions are invalidated when the password changes. Cookies are HttpOnly, Secure, SameSite=Strict.

### Rate limiting: exponential backoff per-IP, never hard lockout

Failed login attempts are tracked in `/shop-config/rate-limit.json`, keyed by client IP. After each failure, a delay is added to the response before the failure is reported back:

| Failures from this IP | Delay added to response |
|-----------------------|-------------------------|
| 0–4 | 0 seconds |
| 5–9 | 2 seconds |
| 10–19 | 4 seconds |
| 20–49 | 8 seconds |
| 50+ | 16 seconds (maximum) |

Successful login resets the counter for that IP to 0. Failure records auto-purge after 24 hours of no activity for that IP.

**Why exponential backoff, not hard lockout.** A hard lockout creates a denial-of-service vector: anyone on the public internet could lock the operator out of their own admin by hitting the login endpoint a few times with garbage passwords. Exponential backoff makes brute force impractical (50+ failures means each attempt takes 16 seconds — at that rate, a 10-character password takes geological time to crack) while always allowing the legitimate operator to log in after waiting the current delay.

### Client IP detection

When the host is behind a reverse proxy (Cloudflare, nginx, etc.), `REMOTE_ADDR` is the proxy's IP, not the user's. The auth layer reads in priority order:

1. `HTTP_CF_CONNECTING_IP` (Cloudflare)
2. First value in `HTTP_X_FORWARDED_FOR` (generic reverse proxy)
3. `REMOTE_ADDR` (direct connection)

Operators behind exotic proxy setups may need to adjust this — the lookup function is small and well-marked.

---

## 10. Markdown editor

Two fields use markdown: `product.long_description` and `category.description`. Both render through Parsedown, which is vendored into `lib/Parsedown.php` as a single file — no Composer, no autoloader. License notice preserved at the top of the file.

The admin renders these fields in a textarea with a five-button toolbar: Bold, Italic, Link, Bullet list, Paragraph break. Each button wraps the current selection (or inserts at cursor) with the appropriate markdown syntax. An optional live preview toggle renders the textarea content through Parsedown into a preview div.

No WYSIWYG. No third-party rich-text library. No external CDN dependency. The toolbar is about 30 lines of vanilla JavaScript.

Markdown is stored verbatim as a plain string in JSON. Round-trip is lossless: what the operator types is what is stored is what is rendered.

---

## 11. Licence verification

Mirrors the Nano CMS pattern exactly. Same Ed25519 signed-key system, same localhost / `.test` / `.local` development-domain bypass, same silent fallback (verification failure renders the footer; no error to the public visitor). The only product-specific difference is the expected `product` field in the signed payload (`"nano-cart"` instead of `"nano-cms"`).

Integration points in the Nano Cart frontend:

- `licence.php` is loaded by `template.php` before footer rendering.
- `nano_is_dev_host($host)` is checked first — true means suppress footer regardless.
- Otherwise, `nano_verify_licence($licence_key, $host)` is called — true means suppress footer, false means render attribution.
- The verification function uses `sodium_crypto_sign_verify_detached()` against an embedded public key constant. No network calls.

Session 5 builds this. The full design — payload schema, dev bypass list, error handling, admin licence page — lives in cart-session-5-licence-v2.md.

---

## 12. Admin behaviour: hero_featured uniqueness and setup advisory

### hero_featured uniqueness enforcement

Only one product across the entire catalogue should have `hero_featured: true` at any time. The admin enforces this atomically at save:

1. Operator saves a product with `hero_featured: true`.
2. Admin loads all other product JSONs.
3. For each that currently has `hero_featured: true`, set it to false and save.
4. Save the current product.
5. Regenerate sitemap.

If two products end up with `hero_featured: true` (e.g. someone hand-edited a JSON file), the frontend's `nano_cart_load_products(['hero_featured' => true])` returns only the first match (sorted by SKU alphabetically). The admin's next save self-heals the duplicate.

Documented here and in FORMAT.md §2 field definition. Implementation lives in `admin/product-edit.php` save handler.

### Setup wizard: advisory, not gate

The first-time setup wizard shows an advisory panel listing the use cases Nano Cart works well for:

> Nano Cart works best when:
> - You sell roughly 20-50 products (scales to 150)
> - Each product is a single-item purchase at a fixed price
> - You don't need size, colour, or other variants
> - You don't need quantity selectors or a multi-item shopping cart
> - Checkout happens via Stripe Payment Link, PayPal, Square, Gumroad, Ko-fi, or similar
>
> If your shop needs are different:
> - Variant-heavy retail → Shopify
> - Larger catalogues over 150 SKUs → WooCommerce
> - Simple shops with multi-item cart → Big Cartel or Gumroad
> - Subscriptions or recurring billing → Lemon Squeezy
>
> You can still use Nano Cart if some of these don't quite match. This is guidance, not a restriction.
>
> **[I understand, continue setup →]**

**One button. No checkboxes. No blocking logic.** The wizard's job is to inform clearly, not to police installations. An operator with 18 products instead of 20, or who plans to add variants as separate SKUs, is not blocked from installing. The advisory ensures they have made an informed decision.

The same content appears in the README's first section and on the marketing site, so operators encounter it before they even download the tool.

---

## 13. Demo content and seed images

The repository ships with a `demo-content/` directory containing:

- 4 categories (Pottery, Prints, Jewellery, Books) with banner images and descriptions.
- 12 products (3 per category) with full metadata and image references.
- A complete `config.json` example for the demo shop.
- Pre-generated placeholder images at all three variant sizes (`-thumb-400`, `-hero-800`, `-thumb-120`) in both JPEG and WebP.

This serves two purposes:

1. **First-impression demo.** A developer evaluating Nano Cart can install it, run `admin/load-demo.php`, and immediately see a working shop with real-looking data. They can browse the demo, see the design, evaluate the SEO output, and then replace the demo content with their own products.

2. **Frontend testability before the image pipeline exists.** Session 2 builds the frontend before Session 4 builds the image-upload pipeline. Without pre-generated image variants, the frontend's `<picture>` element would reference variant files (`-hero-800.jpg`, `-thumb-400.webp`) that don't exist yet, breaking gallery and OG image rendering during Session 2 testing. Shipping the variants pre-generated unblocks frontend testing.

Seed images are hand-made placeholders, small file size, with appropriate alt text. They are committed to the repo so a fresh clone immediately works.

---

## 14. Build sequence

Nano Cart is built across six Claude Code sessions. Each session has a standalone prompt file under `nano/` (or wherever the prompt set is held). The sessions are strictly sequential — each reads the artefacts of the previous.

| Session | Output | Notes |
|---------|--------|-------|
| 1 | FORMAT.md and ARCHITECTURE.md | This session. Pure documentation. |
| 2 | Frontend rendering (PHP, CSS, JS) + seed data with pre-generated image variants | Substantial; natural split point is frontend PHP first, then CSS+JS+seed |
| 3 | Admin core (login with exponential backoff, CRUD, settings, advisory setup wizard) | Substantial; natural split point is auth+setup+products first, then categories+settings+upload |
| 4 | Image manager (full multi-image, drag-reorder, alt text, three-size pipeline) | Natural split: backend pipeline first, then frontend gallery UI |
| 5 | Licence verification (mirrors Nano CMS, `product=nano-cart`, includes test licence generator) | Adapt Nano CMS licence.php, add admin licence page |
| 6 | Documentation polish, demo content load script, README, INSTALL, CHANGELOG, version stamp, tag v1.0.0 | Release prep |

Sessions 2, 3, and 4 are context-heavy. If a session compacts mid-build, finish the current chunk, commit, and start a fresh continuation session with a brief state recap.

---

## Reading order for new contributors

If you have just cloned the repo and want to understand how Nano Cart works:

1. **README.md** — what Nano Cart is, who it's for, who it's not for.
2. **FORMAT.md** — the data shape. What goes on disk and why.
3. **ARCHITECTURE.md** (this document) — how the pieces fit together at runtime.
4. **Source files in `/shop/`** — start with `core.php`, then `product.php` for a representative render path, then `admin/product-edit.php` for a representative save path.

The whole codebase is designed to be readable end-to-end in an afternoon. There are no framework abstractions in the way.
