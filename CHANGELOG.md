# Changelog

All notable changes to Nano Cart are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.5.0] - 2026-05-26

### Added

- **Manual product ordering.** Each product gains an optional **Sort order**
  field (integer, lower appears first) in the editor, mirroring the existing
  category sort order. Products with a sort order lead the category grids and
  featured lists; products left blank fall to the bottom, ordered
  alphabetically by title. Intended for small catalogues (10-20 products) where
  a quick number per product is all the control you need.

### Changed

- `nano_cart_load_products()` now sorts by `sort_order` (then title), replacing
  the previous SKU-alphabetical order. Existing products with no `sort_order`
  set simply sort alphabetically by title, so nothing breaks on upgrade.

## [1.4.0] - 2026-05-26

### Added

- **Transparency-preserving images.** PNG uploads are now stored as PNG (any
  alpha is kept) and served with transparency intact through the on-demand
  resizer; the WebP and PNG variants both keep alpha. A WebP upload is kept as
  PNG when it carries alpha. Opaque uploads are still stored as JPEG.
  Previously every upload was flattened to JPEG, which dropped transparency and
  could leave a stray fill colour behind a cut-out image.
- **Per-image background colour.** A hex colour shown behind an image,
  including through the transparent areas of a PNG. Set it per product and per
  category in their editors, or shop-wide in Settings. Applied to card, hero,
  product, gallery, and category banner images; leave blank for none.

### Changed

- The on-demand image route (`image.php`) and `.htaccess` now accept a `png`
  variant alongside `jpg` and `webp`.
- The media manager threads the stored source extension (`.jpg` / `.png`)
  through upload, listing, copy, move, rename, delete, and cache purging.
- The gallery lightbox rebuilds the full-size URL with the source's real
  extension, so PNG product images open correctly instead of 404ing.

## [1.3.0] - 2026-05-25

### Added

- **Save draft** on the product editor. A draft save requires only a valid SKU,
  so an operator can save partway through, get the product on disk, add images
  in the picker (which needs the product to exist), and complete and publish it
  later. Publishing still runs the full field validation.
- README links to the live demo at nanocart.co.uk/shop.

### Fixed

- Product and category create/edit forms could fail to save in current browsers:
  the SKU/slug field's `pattern` (`[a-z0-9-]`) is rejected under the regex `v`
  flag that browsers now use for HTML pattern validation. The hyphen is now
  escaped, so the forms validate and save again.
- Product image alt text and selection no longer get clobbered on Save: the
  editor re-reads images from disk at save time instead of writing back the
  stale page-load snapshot.
- Image alt text now auto-saves while typing (and on Enter), not only when the
  field loses focus, so it can't be lost by saving before clicking away.

## [1.2.0] - 2026-05-25

Unified image handling: one media manager, and editors that select rather
than upload. This replaces the previous split where the product/category
editor and a separate browser both uploaded images.

### Added

- **Media manager** (`admin/media.php`, `media.js`, `media.css`): a two-pane
  browser over the `/media` folder. A folder **tree** of `category-images/`
  and `product-images/<sku>/` with their subfolders; a **thumbnail grid** of
  the selected folder; a real **uploader** (drop or browse) into the current
  folder; **create/remove** subfolders (one level, duplicate-guarded);
  **rename**, **delete**, and **drag a thumbnail onto a folder to move** it.
  Moving/renaming/deleting a file rewrites the referencing product/category
  JSON and purges its cached variants, so links never dangle. An in-use /
  unused badge flags orphans. Reached from the new **Media** nav item.
- Failsafe handler in `core.php`: a missing required file or unloadable config
  renders a clean 503 page instead of a blank 500.
- Dashboard **health check**: verifies PHP version, GD, required files, config,
  and media writability, so a half-finished upgrade is caught immediately.
- **Checkout trust notice**: in checkout mode a short "Secure checkout" line
  under the buy button names the payment provider (auto-detected from the
  product's checkout URL: PayPal, Stripe, Gumroad, Square, Shopify, and more)
  and notes that it opens in a new tab. Unknown hosts fall back to generic
  wording. New `show_checkout_notice` config flag (default on) with a toggle
  in Settings.

### Changed

- **Product page** refreshed: clearer hierarchy (image, title, price, buy
  button, then full-width description), a larger bordered price, a full-width
  dominant call-to-action carrying the price, more space around the gallery
  thumbnails, and a single shop font (Inter). Breadcrumb hover is now neutral
  rather than the link colour.

- **Product and category editors are now selection-only.** They pick images
  from the media library via a popup picker (the media browser in select mode)
  and set primary, gallery order, and alt text. They no longer upload or manage
  folders. Removing an image from a product/category unreferences it; the file
  stays in the library.
- `admin/upload.php` reduced to the single `update` action (persist image
  references). The upload, subfolder, and file-delete actions moved to the
  media manager.
- Saving a product ensures its `media/product-images/<sku>/` folder exists so
  its images can be uploaded in the manager.

### Security

- Markdown rendering (`Parsedown`) now runs in safe mode: raw HTML is escaped
  and `javascript:` / `data:` link and image URLs are filtered, so admin-authored
  descriptions can never become stored XSS for visitors.
- The buy button only emits `http(s)` checkout URLs, blocking any non-http
  scheme from reaching the link's `href`.

### Removed

- `admin/image-manager.js` and `admin/image-manager.css` (replaced by the media
  manager plus `editor-images.js` / `editor-images.css`).

## [1.1.0] - 2026-05-25

### Added

- `image.php` on-demand image resizer at the shop root. The first request for a given width/format resizes the source, caches the result under `/media/img/`, and serves it with a one-year cache header; the web server serves later requests directly via an `.htaccess` rewrite, so PHP runs once per variant rather than once per view.
- `nano_cart_image_widths()` helper and the `image_widths` config key (the whitelist the resizer is allowed to produce).
- `source_max_width` config key surfaced in the example config and setup wizard.

### Changed

- Image pipeline switched from pre-generated variants to on-demand resizing. Uploads now save a single sanitised JPEG source per image instead of seven files (1 original + 3 sizes x 2 formats). Validation, magic-byte check, EXIF orientation, source cap, and security re-encode are unchanged.
- `nano_cart_image_url()` now emits `/media/img/<path>-<width>.<fmt>` URLs; the `original` variant points at the stored source JPEG.
- Image deletes (a single image, and a whole product or category) now also purge cached variants under `/media/img/`.
- `thumbnail_widths` config key replaced by `image_widths`.
- Seed data ships one placeholder source JPEG per image; the generator writes source files only.

## [1.0.0] - 2026-05-24

Initial public release.

### Frontend

- Flat-file PHP renderers: `index.php` (homepage), `category.php` (category page), `product.php` (product page)
- `core.php` loader and helper library: config, product/category JSON loaders with filtering, markdown rendering via vendored Parsedown, canonical URL construction, image URL builder with variant suffix, breadcrumb data and HTML, SEO metadata block, JSON-LD Product and BreadcrumbList builders, buy/enquiry button, `nano_cart_image_set()` structured-variant view
- `template.php` per-site HTML wrapper that operators customise to match host site chrome
- `generators.php` sitemap generator (XML, regenerated on every admin save)
- `install.php` web-based first-time installer: detects whether `bootstrap.php` exists, prompts for an outside-webroot config directory, creates it (`chmod 0750`), writes `bootstrap.php` with absolute paths, hands off to the admin setup wizard. Refuses to run once `bootstrap.php` exists, so a forgotten `install.php` cannot reconfigure a live shop. Operator deletes it after install, same pattern as the admin folder.
- `nano-preflight.php` first-run detector
- `.htaccess` URL rewriting, HTTPS enforcement, trailing-slash canonicalisation
- `assets/nano-cart.css`: scoped stylesheet (`nano-cart-*` prefix), mobile-first, CSS variables for retheming
- `assets/nano-cart.js`: vanilla JS for lazy image loading via IntersectionObserver, broken-image fallback, gallery keyboard navigation, sticky buy button on mobile scroll
- `lib/Parsedown.php` 1.7.4 vendored (single file, no Composer)
- Two shop modes: `checkout` (external payment URL per product) and `catalogue` (site-wide enquiry action)
- Image fitting controls per product and per category (`image_width`, `image_height`, `image_fit`)
- Banner-with-text float per category (`image_position`)

### Admin

- Portable admin folder uploaded via SFTP when editing, removed afterwards
- Setup wizard with use-case advisory panel; bcrypt password storage in outside-webroot config
- Password-only login with exponential-backoff rate limiting per-IP (0-4 fails: instant; 5-9: +2s; 10-19: +4s; 20-49: +8s; 50+: +16s), NEVER hard-lockout
- CSRF tokens on every POST form
- Session cookies HttpOnly + Secure + SameSite=Strict, 1-hour idle timeout
- Dashboard with product/category counts and recent products
- Product CRUD with all FORMAT.md schema fields; markdown editor with Bold/Italic/Link/List/Paragraph toolbar plus preview toggle; `hero_featured` uniqueness enforced atomically at save time
- Category CRUD with reassignment-required check on delete
- Settings page (every operator-tunable config field; optional password change forces re-login)
- Atomic JSON writes (write-then-rename) to avoid partial-file states under crash
- Sitemap regenerated on every save

### Image manager

- Multi-file drag-and-drop upload, click-to-browse fallback
- Per-file mime + magic-byte validation via finfo
- EXIF orientation applied to JPEGs (phone portraits no longer display sideways)
- Source dimension cap (default 1600px wide, configurable via `source_max_width`)
- Re-encode through GD to strip embedded payloads
- Three width variants generated in JPEG and WebP: thumb-400 (cards), hero-800 (main/banner), thumb-120 (gallery)
- Gallery UI with drag-to-reorder, inline alt-text editor saved on blur, primary-image star, delete with confirmation
- Subfolder support (one level deep) for organising product images
- Configurable JPEG and WebP quality (60-95, default 85)

### Licence verification

- Ed25519 signed-key system via libsodium
- Embedded Digital Fracture public key, shared with Nano CMS (the same master keypair signs licences for both products; only the `product` field in the payload distinguishes them)
- Canonical host derived from `config.site_url`, NOT `HTTP_HOST`, to prevent Host-spoof and reverse-proxy cache-poisoning bypass
- Dev-host bypass: `localhost`, `127.0.0.1`, `::1`, any host with a port, `*.test`, `*.local`
- Admin Licence page with status display, paste-to-verify-save, remove with confirmation

### Documentation

- `FORMAT.md`: on-disk format contract (directory layout, product schema, category schema, config schema, image organisation rules, URL structure, SEO output contract, sitemap format, rate-limit state file)
- `ARCHITECTURE.md`: runtime architecture (layers, file responsibilities, mobile-first design, SEO architecture, visual design, CSS prefix convention, image fitting controls, image pipeline, admin auth and rate limiting, markdown editor, licence verification, build sequence)
- `STYLE.md`: prose style rules (no em-dashes, locked footer wording)
- `README.md`: project entry point, comparison vs WooCommerce/Shopify/etc, architecture diagram, SEO features, requirements, backup, roadmap, licensing
- `INSTALL.md`: step-by-step deployment guide with troubleshooting
- `UPGRADE.md`: upgrade philosophy
- `CONTRIBUTING.md`: bug-reporting and feedback approach
- This `CHANGELOG.md`

### Seed data

A minimal `seed-data/` directory ships with the repo containing one category, two products, and approximately 35 placeholder image files (3 size variants in JPEG and WebP per source). Intentionally disposable: operators delete the directory before deploying their own shop. Generator script (`seed-data/generate-seed-images.php`) is included for regeneration if image sizes are changed.

[1.2.0]: https://github.com/digifrac/Nano-Cart/releases/tag/v1.2.0
[1.1.0]: https://github.com/digifrac/Nano-Cart/releases/tag/v1.1.0
[1.0.0]: https://github.com/digifrac/Nano-Cart/releases/tag/v1.0.0
