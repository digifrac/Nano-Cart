# Changelog

All notable changes to Nano Cart are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

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

[1.0.0]: https://github.com/digifrac/Nano-Cart/releases/tag/v1.0.0
