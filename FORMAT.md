# Nano Cart — File Format Specification

This document is the on-disk file format contract for Nano Cart. Every subsequent build session implements against the schemas, paths, and conventions defined here. If a future change to the format is needed, this document is updated first, and code follows.

Companion document: [ARCHITECTURE.md](ARCHITECTURE.md) describes how the pieces fit together at runtime. FORMAT.md describes what is written to disk.

---

## 1. Directory layout

Nano Cart spans three locations on a host server: the public webroot (permanent), the ephemeral admin (uploaded only when needed), and a configuration directory outside the webroot.

```
/path/to/webroot/
  shop/                              ← Nano Cart's permanent home
    bootstrap.php                    Per-site config paths (operator edits once)
    core.php                         Parser/loader, helper functions
    index.php                        Shop homepage
    category.php                     Category page renderer
    product.php                      Product page renderer
    template.php                     Per-site HTML wrapper (operator customises)
    generators.php                   Sitemap generation
    licence.php                      Ed25519 licence verification
    nano-preflight.php               First-run setup check
    sitemap.xml                      Generated, regenerated on every admin save
    .htaccess                        URL rewriting + HTTPS enforcement
    assets/
      nano-cart.css                  Default stylesheet, scoped to nano-cart-*
      nano-cart.js                   Vanilla JS for lazy loading and gallery
    lib/
      Parsedown.php                  Vendored markdown parser, no Composer
    products/
      sku-001.json                   One JSON file per product
      sku-002.json
      ...
    categories/
      pottery.json                   One JSON file per category
      prints.json
      ...
    media/
      category-images/               Banner images for category pages
        pottery.jpg
        prints/                      Optional one-level subfolder
          banner.jpg
      product-images/
        sku-001/                     One folder per product, SKU as folder name
          main.jpg
          alt-1.jpg
          details/                   Optional one-level subfolder
            angle-1.jpg
    admin/                           ← Ephemeral; uploaded via SFTP when needed
      index.php
      login.php
      setup.php
      products.php
      product-edit.php
      product-delete.php
      categories.php
      category-edit.php
      category-delete.php
      settings.php
      licence.php
      upload.php
      auth.php
      .htaccess
      assets/
        admin.css
        admin.js

/path/to/shop-config/                ← Outside webroot, not web-accessible
  config.json                        Settings, password hash, licence key
  rate-limit.json                    Per-IP login backoff state
```

The `admin/` folder is removed via SFTP after the operator finishes editing. Removing it is the recommended security posture — when the admin isn't on the server, it can't be attacked. The frontend `/shop/` directory runs permanently and renders pages from the JSON files in `products/` and `categories/`.

`/shop-config/` lives one level above the webroot so its contents are never served over HTTP. The path is set in `bootstrap.php` and may live anywhere readable by PHP.

---

## 2. Product JSON schema

One product = one JSON file under `products/`. Filename is `<sku>.json` (lowercase). Loading a product means reading the file by SKU; the SKU is both the filename, the URL slug, and the canonical identifier.

### Operator-facing fields

| Field | Type | Required | Default | Notes |
|-------|------|----------|---------|-------|
| `sku` | string | yes | — | Lowercase alphanumeric + hyphens. Must match `^[a-z0-9][a-z0-9-]*[a-z0-9]$`. Used as filename and URL slug. Once set, do not change (URLs will break). |
| `title` | string | yes | — | Display name. Renders in `<h1>` on product page and as card title. |
| `short_description` | string | yes | — | Plain text, used as meta description and card preview. Recommended 120-160 characters for SEO. |
| `long_description` | string | yes | — | Markdown source. Rendered through Parsedown on the product page body. |
| `category` | string | yes | — | Slug of an existing category. Must match a file in `categories/`. Admin validates on save. |
| `price_display` | string | yes | — | Exact text displayed beside the title, e.g. `"£25.00"`, `"From £40"`, `"POA"`. No numeric parsing — operator writes what should appear. |
| `checkout_url` | string | yes (checkout mode) | — | Full HTTPS URL to external payment processor. Validated as HTTPS at admin save. Ignored in catalogue mode. |
| `images` | array | yes | `[]` | Array of image objects, see below. At least one image strongly recommended. |
| `featured` | boolean | no | `false` | Marks product for the homepage "featured" grid. |
| `hero_featured` | boolean | no | `false` | Marks product as the homepage hero. **Uniqueness enforced by admin** — only one product can have this `true` at a time. Saving a new hero clears the flag on all others. |

### Image fitting fields (per product main image)

| Field | Type | Required | Default | Valid values |
|-------|------|----------|---------|--------------|
| `image_width` | string | no | `"400"` | `"300"`, `"400"`, `"500"`, `"600"`, `"full"` |
| `image_height` | string | no | `"auto"` | `"auto"`, `"300"`, `"400"`, `"500"`, `"600"` |
| `image_fit` | string | no | `"contain"` | `"contain"`, `"cover"` |

These control how the main product image is sized. Visual styling (borders, radius, shadow, hover) is shop-wide in `nano-cart.css` and not per-product.

### Auto-handled fields

| Field | Type | Notes |
|-------|------|-------|
| `slug` | string | Derived from SKU. Operators don't edit. Present in JSON for explicitness. |
| `canonical_url` | — | **Not stored.** Computed at render time from `site_url + shop_path + category + slug`. |
| `created` | string | ISO-8601 UTC timestamp, set when admin first saves the product. |
| `updated` | string | ISO-8601 UTC timestamp, updated on every save. |
| `status` | string | `"published"` or `"draft"`. Defaults `"published"`. Draft products are excluded from category pages, sitemaps, and the homepage. |

### Images array

Each entry in `images[]`:

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `file` | string | yes | Relative path under `/media/product-images/<sku>/`, without extension. E.g. `"main"` or `"details/angle-1"`. The renderer appends size suffix and extension. |
| `alt` | string | yes | Descriptive alt text. Required for SEO and accessibility — admin enforces non-empty on save. |
| `is_primary` | boolean | no | Exactly one image per product should be primary. The primary image is used as the main hero, the OG image, and the card thumbnail. If absent on all, the first image is treated as primary. |

### Example product JSON

```json
{
  "sku": "pot-001",
  "title": "Hand-thrown stoneware mug",
  "short_description": "Deep cobalt glaze on natural stoneware, 350ml capacity. Dishwasher safe.",
  "long_description": "Each mug is thrown on the wheel and glazed by hand...\n\n## Care\n\nDishwasher and microwave safe.",
  "category": "pottery",
  "price_display": "£32.00",
  "checkout_url": "https://buy.stripe.com/test_abc123",
  "images": [
    { "file": "main",            "alt": "Cobalt blue stoneware mug, side view",  "is_primary": true },
    { "file": "details/handle",  "alt": "Close-up of the mug handle",            "is_primary": false },
    { "file": "details/glaze",   "alt": "Glaze pattern detail",                  "is_primary": false }
  ],
  "featured": true,
  "hero_featured": false,
  "image_width": "500",
  "image_height": "auto",
  "image_fit": "contain",
  "slug": "pot-001",
  "created": "2026-05-21T10:00:00Z",
  "updated": "2026-05-21T10:30:00Z",
  "status": "published"
}
```

### Validation rules

- **SKU**: must be 2-64 characters, lowercase alphanumeric plus hyphens, not start or end with a hyphen. No spaces, no uppercase, no underscores.
- **Title**: 1-200 characters.
- **short_description**: 1-300 characters. Anything over 200 risks truncation in OG/Twitter cards.
- **long_description**: no hard limit. Stored verbatim as markdown.
- **category**: must reference a file that exists in `categories/`. Admin re-validates on every save.
- **price_display**: 1-50 characters, no parsing — write exactly what should appear.
- **checkout_url** (checkout mode): must be a valid URL starting with `https://`. HTTP is rejected. Admin warns if the domain looks like a known phishing pattern but does not block.
- **images**: array allowed to be empty but strongly discouraged. Soft maximum 12 per product (no hard limit; warn in admin above 12). Each `file` path must not contain `..`, must not begin with `/`, must be at most one subfolder deep.
- **hero_featured**: admin enforces uniqueness on save. If the JSON is hand-edited so two products have it true, the frontend returns only the first match sorted alphabetically by SKU. Admin's next save self-heals duplicates.

---

## 3. Category JSON schema

One category = one JSON file under `categories/`. Filename is `<slug>.json`.

| Field | Type | Required | Default | Notes |
|-------|------|----------|---------|-------|
| `slug` | string | yes | — | Lowercase alphanumeric + hyphens. Used as filename and URL segment. Same character set rules as product SKU. |
| `name` | string | yes | — | Display name, used in `<h1>` and breadcrumbs. |
| `description` | string | no | `""` | Markdown source. Rendered alongside the banner image on the category page. Truncated to 150 plain-text characters for the meta description if `meta_description` not set. |
| `image` | string | no | `null` | Path under `/media/category-images/`, without extension. May include one subfolder, e.g. `"prints/banner"`. |
| `sort_order` | integer | no | — | Lower values appear first. If unset for all categories, ordering is alphabetical by `name`. Mixing set and unset is allowed (set values win, unset fall through to alphabetical). |
| `homepage_slot` | integer | no | — | Position in the homepage category grid, `1`..`cap` where `cap = categories_per_row × 2` (6 or 8). **Uniqueness enforced by admin** — a slot is held by one category and a category holds one slot, both edited from the Homepage slots picker on the Categories page. If **no** category has a `homepage_slot`, the homepage shows all categories (default). If any do, the homepage shows only slotted categories, ordered by slot; the rest remain in the off-canvas category nav. |
| `meta_title` | string | no | `null` | Overrides the `<title>` tag. If null, defaults to `name + " — " + site_name`. |
| `meta_description` | string | no | `null` | Overrides the meta description. If null, derived from `description`. |

### Image fitting fields (per category banner)

| Field | Type | Required | Default | Valid values |
|-------|------|----------|---------|--------------|
| `image_width` | string | no | `"400"` | `"300"`, `"400"`, `"500"`, `"600"`, `"full"` |
| `image_height` | string | no | `"auto"` | `"auto"`, `"300"`, `"400"`, `"500"`, `"600"` |
| `image_fit` | string | no | `"contain"` | `"contain"`, `"cover"` |
| `image_position` | string | no | `"left"` | `"left"`, `"right"` (which side of the description on desktop) |

### Example category JSON

```json
{
  "slug": "pottery",
  "name": "Pottery",
  "description": "Hand-thrown stoneware and porcelain pieces, glazed and fired in our studio.\n\nEach piece is one of a kind.",
  "image": "pottery",
  "sort_order": 10,
  "homepage_slot": 1,
  "meta_title": null,
  "meta_description": null,
  "image_width": "500",
  "image_height": "auto",
  "image_fit": "cover",
  "image_position": "left"
}
```

### Validation rules

- **slug**: same character set rules as product SKU. Cannot be `admin`, `sitemap`, `assets`, `lib`, `products`, `categories`, `media` — these are reserved path segments.
- **name**: 1-100 characters.
- **description**: optional. No length limit.
- **image**: if set, must reference a file that exists under `/media/category-images/`. Same path-safety rules as product images.
- **sort_order**: any integer including negative.
- A category may be referenced by zero products. The category page renders with an empty product grid in that case.

---

## 4. Config schema

`config.json` lives in the outside-webroot config directory. One file per installation.

| Field | Type | Required | Default | Notes |
|-------|------|----------|---------|-------|
| `site_name` | string | yes | — | Display name of the host site, used in titles and footer. |
| `site_url` | string | yes | — | Canonical site URL with scheme, no trailing slash. E.g. `"https://example.com"`. |
| `shop_path` | string | no | `"/shop"` | Path under `site_url` where Nano Cart is mounted. Leading slash, no trailing slash. |
| `shop_mode` | string | yes | `"checkout"` | `"checkout"` or `"catalogue"`. Changes how the buy button renders. |
| `enquiry_action` | string | conditional | `null` | Required in catalogue mode. Either `mailto:address@example.com` or an `https://` URL to a contact form. Product name and SKU appended as query string for mailto. |
| `show_checkout_notice` | bool | no | `true` | Checkout mode only. Shows a "Secure checkout" notice under the buy button naming the payment provider (auto-detected from the product's `checkout_url` host) and noting it opens in a new tab. |
| `password_hash` | string | yes | — | bcrypt hash of admin password. Written by setup wizard, never read in plaintext. |
| `licence_key` | string | no | `""` | Signed Ed25519 licence in `base64(payload).base64(signature)` form. Empty means unlicensed (footer attribution shows). |
| `image_quality_jpeg` | integer | no | `85` | JPEG encode quality, 60-95. |
| `image_quality_webp` | integer | no | `80` | WebP encode quality, 60-95. |
| `source_max_width` | integer | no | `1600` | Source images wider than this are downscaled to it on upload (clamped 400-4000). |
| `image_widths` | array | no | `[120, 400, 800]` | Widths the on-demand resizer is allowed to produce. The templates request 120/400/800; only change this if you also serve custom widths. |
| `default_currency` | string | no | `"GBP"` | ISO 4217 code. Used only in JSON-LD Product schema's `priceCurrency` field. The visible price comes from each product's `price_display`. |
| `card_image_height` | string | no | `"240"` | Pixel height for card thumbnails, shop-wide. |
| `card_image_fit` | string | no | `"cover"` | `"cover"` or `"contain"`. Shop-wide. |
| `seo` | object | no | see below | Nested object for default SEO settings. |

### `seo` nested object

| Field | Type | Required | Default | Notes |
|-------|------|----------|---------|-------|
| `default_meta_description` | string | no | `""` | Fallback meta description for the homepage. |
| `og_image` | string | no | `""` | Path (under `site_url`) to a fallback Open Graph image for pages without their own. Recommended 1200×630. |
| `twitter_handle` | string | no | `""` | Twitter/X handle including `@`. Used in `twitter:site`. |
| `brand_name` | string | no | uses `site_name` | Used in JSON-LD Product schema's `brand` field. |

### Example config.json

```json
{
  "site_name": "Riverside Pottery",
  "site_url": "https://riverside-pottery.co.uk",
  "shop_path": "/shop",
  "shop_mode": "checkout",
  "enquiry_action": null,
  "password_hash": "$2y$10$abc...",
  "licence_key": "",
  "image_quality_jpeg": 85,
  "image_quality_webp": 80,
  "source_max_width": 1600,
  "image_widths": [120, 400, 800],
  "default_currency": "GBP",
  "card_image_height": "240",
  "card_image_fit": "cover",
  "seo": {
    "default_meta_description": "Hand-thrown pottery from a small Cotswolds studio.",
    "og_image": "/shop/assets/og-default.jpg",
    "twitter_handle": "@riversidepots",
    "brand_name": "Riverside Pottery"
  }
}
```

---

## 5. Image organisation rules

Two image roots: `/media/category-images/` (banner images for category pages) and `/media/product-images/<sku>/` (per-product galleries).

### Folder rules

- Root files allowed in both roots.
- **One level** of organisational subfolders allowed. Examples: `category-images/prints/banner.jpg`, `product-images/sku-001/details/angle.jpg`.
- **No subfolders inside subfolders.** Admin UI enforces this on create and on upload.
- One folder per product, named after its SKU exactly. Created automatically by admin on first image upload.

### File naming

- Lowercase only.
- Alphanumeric and hyphens only. No spaces, no underscores, no uppercase, no punctuation.
- Original extensions: `.jpg`, `.jpeg`, `.png`, `.webp`, `.gif`. All re-encoded to a single JPEG source on save.

### On-demand variants

Upload saves exactly one file per image: a sanitised, EXIF-corrected, size-capped JPEG source at `<basename>.jpg`. No variant files are written at upload time.

Sized variants are produced on demand by `image.php` and cached to disk under `/media/img/`, mirroring the source path:

| Variant | Request URL | Use |
|---------|-------------|-----|
| Source | `/media/<path>.jpg` | Served directly; source for resizing |
| Card thumbnail (400px wide) | `/media/img/<path>-400.<jpg\|webp>` | Category page product cards |
| Hero / banner (800px wide) | `/media/img/<path>-800.<jpg\|webp>` | Product main image, category banner |
| Gallery thumbnail (120px wide) | `/media/img/<path>-120.<jpg\|webp>` | Gallery thumbnail strip on product page |

Heights scale to maintain aspect ratio — only width is constrained, and the resizer never upscales. The first request for a given width/format generates and caches the file; subsequent requests are served as a static file by the web server (the `.htaccess` skip-if-exists rule), never touching PHP. The `<picture>` element offers the WebP source first with the JPEG as fallback; if the build lacks WebP support the WebP URL 404s and the browser uses the JPEG.

Because the source is preserved, widths or quality can be changed later in `config.json` — deleting the cached files under `/media/img/` forces a regeneration at the new settings.

### JSON references

Image paths in product and category JSON are stored **without** the extension and **without** the variant suffix. The renderer appends both. Example: `"file": "details/angle-1"` resolves to `/shop/media/img/product-images/sku-001/details/angle-1-800.webp` (or `.jpg`) at render time.

This keeps the JSON variant-agnostic — adding or changing a width never touches the JSON.

---

## 6. URL structure

Two-level maximum hierarchy. Slugs come from SKUs (products) and category `slug` fields. Operators don't manually edit URL slugs.

| Pattern | Rewrites to | Purpose |
|---------|-------------|---------|
| `/shop/` | `index.php` | Shop homepage |
| `/shop/<category-slug>/` | `category.php?category=<category-slug>` | Category page |
| `/shop/<category-slug>/<product-slug>/` | `product.php?category=<category-slug>&product=<product-slug>` | Product page |
| `/shop/media/img/<path>-<width>.<jpg\|webp>` | `image.php?path=<path>&w=<width>&fmt=<jpg\|webp>` (cache miss only) | On-demand resized image |
| `/shop/sitemap.xml` | served as static file from disk | XML sitemap |
| `/shop/admin/` | served by `admin/index.php` | Admin (when uploaded) |

The `.htaccess` file enforces a trailing slash on category and product URLs, redirects HTTP to HTTPS, and serves the sitemap as-is.

Breadcrumbs never exceed three segments: `Shop → Category → Product`. The category page has two segments; the homepage has none rendered (or just "Shop", depending on template).

---

## 7. SEO output contract

Every page rendered by Nano Cart must produce the metadata listed here. This is the SEO contract — Lighthouse SEO score 100 is the target on category and product pages.

### Product page

| Element | Source |
|---------|--------|
| `<title>` | `product.meta_title` if set, else `product.title + " — " + site_name` |
| `<meta name="description">` | `product.short_description` |
| `<link rel="canonical">` | computed: `site_url + shop_path + "/" + category + "/" + slug + "/"` |
| `<meta property="og:title">` | same as `<title>` |
| `<meta property="og:description">` | same as meta description |
| `<meta property="og:image">` | URL to primary image at the 800px (hero) variant |
| `<meta property="og:url">` | canonical URL |
| `<meta property="og:type">` | `"product"` |
| `<meta name="twitter:card">` | `"summary_large_image"` |
| `<meta name="twitter:site">` | `seo.twitter_handle` if set |
| JSON-LD `Product` schema | name, description, image array (primary at 800/400/120px), brand, sku, url, offers (price, priceCurrency, availability="https://schema.org/InStock", url) |
| JSON-LD `BreadcrumbList` schema | three items: Shop → Category → Product |
| Semantic HTML | `<article>` wraps product content, `<figure>` for gallery items, `<picture>` for responsive images |
| Image `loading` | `lazy` on every image |
| Image `alt` | `images[].alt` for each, never empty (admin enforces) |

### Category page

| Element | Source |
|---------|--------|
| `<title>` | `category.meta_title` if set, else `category.name + " — " + site_name` |
| `<meta name="description">` | `category.meta_description` if set, else first 150 plain-text characters of `category.description`, else `seo.default_meta_description` |
| `<link rel="canonical">` | computed: `site_url + shop_path + "/" + slug + "/"` |
| `<meta property="og:title">` | same as `<title>` |
| `<meta property="og:description">` | same as meta description |
| `<meta property="og:image">` | URL to category banner at the 800px (hero) variant if `image` set, else `seo.og_image` |
| `<meta property="og:url">` | canonical URL |
| `<meta property="og:type">` | `"website"` |
| JSON-LD `BreadcrumbList` schema | two items: Shop → Category |

### Homepage

`<title>` = `site_name`. Meta description = `seo.default_meta_description`. Canonical URL = `site_url + shop_path + "/"`. OG and Twitter tags from `seo` defaults. No JSON-LD on the homepage (the homepage template is operator-customisable and may not represent a coherent schema.org type).

### Pricing in JSON-LD

The visible `price_display` is freeform string for design flexibility (`"From £40"`, `"POA"`). The JSON-LD `offers.price` field requires a numeric value, so the renderer extracts digits from `price_display` to populate it. If extraction yields no number, the `offers` block is omitted and the Product schema renders without pricing — preferable to bogus structured data.

---

## 8. Sitemap format

`sitemap.xml` lives at `/shop/sitemap.xml` and is regenerated on every admin save. Plain XML following sitemaps.org 0.9.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>https://example.com/shop/</loc>
    <lastmod>2026-05-21</lastmod>
    <changefreq>weekly</changefreq>
    <priority>1.0</priority>
  </url>
  <url>
    <loc>https://example.com/shop/pottery/</loc>
    <lastmod>2026-05-21</lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.8</priority>
  </url>
  <url>
    <loc>https://example.com/shop/pottery/pot-001/</loc>
    <lastmod>2026-05-21</lastmod>
    <changefreq>monthly</changefreq>
    <priority>0.6</priority>
  </url>
</urlset>
```

### Priority and changefreq conventions

| URL type | priority | changefreq |
|----------|----------|------------|
| Homepage | 1.0 | weekly |
| Category page | 0.8 | weekly |
| Product page | 0.6 | monthly |

`lastmod` for products comes from `product.updated`. For categories, from the most recent `updated` across products in that category, or the category file's mtime if no products. For the homepage, the most recent `updated` across all products.

Draft products and unlisted pages are excluded. Admin pages are excluded.

---

## 9. Rate-limit state file

`rate-limit.json` lives in the outside-webroot config directory. Keyed by IP address. Used by `admin/login.php` for exponential backoff.

```json
{
  "192.0.2.1": {
    "failures": 7,
    "first_failure": "2026-05-21T14:00:00Z",
    "last_failure": "2026-05-21T14:12:00Z"
  },
  "203.0.113.42": {
    "failures": 2,
    "first_failure": "2026-05-21T13:50:00Z",
    "last_failure": "2026-05-21T13:51:00Z"
  }
}
```

Records auto-purge after 24 hours of no activity for that IP. Successful login resets the failure count for that IP to 0. See ARCHITECTURE.md §9 for the backoff schedule and rationale.
