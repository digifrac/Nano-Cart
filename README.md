# Nano Cart

A flat-file PHP product catalogue framework for static client sites. Sells fixed-price products through hosted checkout links (Stripe Payment Link, PayPal, Square, Gumroad, Ko-fi, or any URL). Drops into existing sites at `/shop/`. No database, no frameworks, no JavaScript checkout flows.

**Live demo: [nanocart.co.uk/shop](https://nanocart.co.uk/shop/)**

[![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20A%20Coffee-FFDD00?logo=buymeacoffee&logoColor=black)](https://buymeacoffee.com/digitalfracture)

> **Status: v1.6.0, feature-complete.** Production-ready and feature-locked: future releases are bug fixes, security, and documentation only. See [INSTALL.md](INSTALL.md) for deployment, [CHANGELOG.md](CHANGELOG.md) for release notes.

---

## Not for you

Nano Cart sells one item, one price, one click. It is deliberately not a general-purpose e-commerce platform. **It does not support:**

- Size, colour, or other product variants
- Quantity selectors or multi-item shopping carts
- Inventory or stock tracking
- Search, filtering, or pagination
- Tax engines or shipping rules
- Subscriptions or recurring billing
- Catalogues larger than around 150 SKUs

If your shop needs any of these, a full database-backed e-commerce platform is the better fit.

Nano Cart is the right tool for a potter, a print-maker, a jewellery designer, an author, a consultant, or a gallery: anyone selling 20-50 distinct items at fixed prices through hosted checkout links.

---

## How it works

- **Flat-file JSON.** One JSON file per product, one per category. No database. Edit by hand or through the admin.
- **External checkout.** Each product links to a Stripe Payment Link, PayPal hosted checkout, Square, Gumroad, Ko-fi, or any processor-hosted URL. Nano Cart renders its own "Buy" button as a plain `<a href>` to that URL. No SDKs, no embed code, no JavaScript on the shop page. A "Secure checkout" trust notice under the button names the provider (auto-detected from the checkout URL's host) and notes it opens in a new tab; toggle it in Settings.
- **Catalogue mode** alternative: replace the buy button with a site-wide enquiry action (mailto, contact form, Calendly, WhatsApp) for businesses selling through quotes.
- **Removable admin.** A portable admin folder you upload via SFTP when you want to make changes, then remove. When the admin isn't on the server, it can't be attacked.
- **Web installer.** A small `install.php` script auto-detects your hosting layout (cPanel, addon domains, standard), creates the outside-webroot config directory, writes `bootstrap.php` for you, and hands off to the setup wizard. One-click delete from the admin dashboard after setup. No SFTP-and-edit-bootstrap-by-hand required.
- **SEO as a core output.** Every page renders complete metadata, JSON-LD Product schema, JSON-LD BreadcrumbList, Open Graph and Twitter Card tags, canonical URLs computed at render time.
- **Mobile-first.** Templates designed for a 375px viewport and progressively enhanced for larger screens. Sticky buy button on mobile. Native scroll-snap image gallery. Tap targets at least 44px.
- **No frameworks.** Hand-written PHP, hand-written CSS scoped to `nano-cart-*` class names, minimal vanilla JavaScript. The only vendored dependency is Parsedown (single file, no Composer).
- **Tiny on purpose.** Around 8,800 lines of code total, deploys in under 250KB on disk. For comparison, WooCommerce is ~50MB and a single one of its bundled JavaScript files is often larger than the whole of Nano Cart.

Total size: around 8,800 lines of code (about 7,100 hand-written PHP / CSS / JS, plus the 1,700-line vendored Parsedown). The whole shop deploys in under 250KB on disk.

---

## Who it's for

Developers and agencies building static sites for clients who need to sell a small, fixed catalogue without taking on a database, a CMS, or a hosted platform.

Suitable shops:

- A potter selling 30 hand-thrown pieces through Stripe Payment Links
- A jewellery designer with 50 one-off pieces and PayPal checkout
- An author selling signed editions and merch with Gumroad
- A consultant offering fixed-price service packages with Calendly enquiry
- A gallery cataloguing artworks for sale with a contact-form enquiry action

Not suitable: a clothing brand with variants, a marketplace with multiple sellers, anyone needing inventory tracking. See "Not for you" above.

---

## Why not WooCommerce, Shopify, or OpenCart?

All excellent for shops that need them. For a static client site that needs to sell a fixed catalogue:

- **Shopify**: hosted, monthly fee, full e-commerce platform. Right answer for variant retail; overkill for 20 fixed-price items.
- **WooCommerce**: requires WordPress + MySQL + ongoing security patching. Same overkill for a static client site.
- **OpenCart**: standalone PHP, requires MySQL and admin maintenance. Same problem.

Nano Cart keeps the host site static. No database to migrate, no plugins to patch, no admin sitting on the server when not in use. When the admin is removed, only flat JSON files and images remain.

---

## Why not similar flat-file shop tools?

There aren't many. The flat-file CMS space (Pico, Bludit, Grav) is mostly oriented at blogs and content sites; the only flat-file shop tools in active use tend to be Shopify themes that backend onto Shopify (which defeats the purpose).

The closest comparison is rolling your own static catalogue in Jekyll or Eleventy and pasting Stripe Buy Buttons. That works for one shop but doesn't scale to "I need to do this for 10 clients." Nano Cart packages the pattern and adds an admin so non-developer operators can make edits.

---

## Architecture

Three layers:

```
+-------------------------------------------------------------+
|  Frontend (permanent, in webroot)                           |
|  /shop/   core.php, index.php, category.php, product.php    |
|           template.php, generators.php, .htaccess           |
|           assets/, lib/, products/, categories/, media/     |
+-------------------------------------------------------------+
|  Admin (ephemeral, uploaded when needed)                    |
|  /shop/admin/   login, CRUD, image manager, settings        |
|                 removed via SFTP after edits                |
+-------------------------------------------------------------+
|  Config (outside webroot, not web-accessible)               |
|  /shop-config/   config.json, rate-limit.json               |
|                  password hash, licence key, settings       |
+-------------------------------------------------------------+
```

See [ARCHITECTURE.md](ARCHITECTURE.md) for the full design and [FORMAT.md](FORMAT.md) for the on-disk format contract.

---

## SEO features (per page)

- Server-rendered `<title>`, meta description, canonical URL
- Open Graph tags (`og:title`, `og:description`, `og:image`, `og:url`, `og:type`)
- Twitter Card tags
- JSON-LD `Product` schema on every product page (name, image, brand, sku, offers with price and currency)
- JSON-LD `BreadcrumbList` schema on category and product pages
- Semantic HTML5 (`<article>`, `<figure>`, `<picture>`)
- All images with `loading="lazy"` and descriptive alt text (admin enforces non-empty alt)
- WebP variants with JPEG fallback via `<picture>` element
- XML sitemap regenerated on every admin save
- Clean URLs end-to-end via `mod_rewrite`

---

## Requirements

- PHP 8.1 or newer
- Apache with `mod_rewrite` (or equivalent on nginx/Caddy with hand-translated rewrites)
- HTTPS strongly recommended (the buy-button flow assumes a secure context)
- SFTP access to the host
- GD extension (built into most PHP installs). Imagick is supported as a fallback.
- libsodium (built into PHP 8.1+) for licence verification

---

## Backup

The whole shop is a directory of JSON files and images. A daily rsync of `/shop/` and `/shop-config/` to a backup host is sufficient. Example cron:

```cron
0 3 * * *  rsync -az --delete /var/www/example.com/shop/ backup@host:/backups/example-shop/
5 3 * * *  rsync -az --delete /etc/shop-config/        backup@host:/backups/example-config/
```

Restore is `rsync` in the other direction. No database to dump, no migrations to replay.

---

## Roadmap

**v1.5.1 is the current release, and Nano Cart is feature-complete.** Frontend, admin, media manager, on-demand image pipeline, transparency-aware images, manual product ordering, licence verification, and full documentation are all in place. See [CHANGELOG.md](CHANGELOG.md) for the full history.

**The feature set is now locked.** Future releases are limited to bug fixes, security patches, code cleanup, and documentation. No new features will be added: a small, fixed surface is the point of the product, not a stage it is passing through.

**Explicitly out of scope.** Variants, quantity selectors, multi-item carts, inventory, search, tax/shipping engines, subscriptions. These will not be added. See "Not for you" above.

---

## Licensing

**The code is MIT-licensed.** Free to use, fork, modify, and deploy commercially.

**Footer attribution is removable via a per-domain licence:**

| Tier | Price | Use |
|------|-------|-----|
| Single domain | £29 | One shop on one domain |
| Agency 3-pack | £69 | Up to three shops |
| Agency unlimited | £249 | Unlimited domains (wildcard) |

Without a licence, Nano Cart displays a small "Powered by Nano Cart. Developed by Digital Fracture." footer on the pages it renders. With a valid licence, the footer is hidden. Localhost, `127.0.0.1`, any host with a non-default port, and `.test` / `.local` zones skip the licence check, so local development is always footer-free.

The check is local: no phone-home, no network calls, no telemetry. Verification uses libsodium's Ed25519 against an embedded Digital Fracture public key.

Paste your licence key into the admin under **Licence**, or directly into `/shop-config/config.json` as the `licence_key` field.

Buy a licence at [digitalfracture.co.uk/nano-cart.html](https://digitalfracture.co.uk/nano-cart.html).

---

## Contributing

Nano Cart is early-stage solo development. Bug reports, feature suggestions, and security issues are welcome via [GitHub Issues](https://github.com/digifrac/Nano-Cart/issues). See [CONTRIBUTING.md](CONTRIBUTING.md) for full details.

For security issues, please open a private security advisory rather than a public issue.

---

## Support the project

If Nano Cart saves you time on a client project, consider [buying me a coffee](https://buymeacoffee.com/digitalfracture). It helps fund ongoing development and the time spent answering issues.

---

## See also

- [GUIDE.md](GUIDE.md): standalone user guide for shop operators (day-to-day use: products, images, checkout links, publishing). Same guide as a ready-to-host page in [GUIDE.html](GUIDE.html).
- [INSTALL.md](INSTALL.md): step-by-step deployment guide
- [FORMAT.md](FORMAT.md): on-disk format specification (schemas, paths, URLs)
- [ARCHITECTURE.md](ARCHITECTURE.md): runtime architecture and design contracts
- [CHANGELOG.md](CHANGELOG.md): version history
- [CONTRIBUTING.md](CONTRIBUTING.md): how to report bugs and suggest features
- [Nano CMS](https://github.com/digifrac/Nano-CMS): sibling project for publishing blog posts on the same philosophy
- [nanocart.co.uk](https://nanocart.co.uk): marketing site
- [digitalfracture.co.uk](https://digitalfracture.co.uk): the studio behind Nano Cart
