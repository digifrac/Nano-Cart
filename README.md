# Nano Cart

[![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20A%20Coffee-FFDD00?logo=buymeacoffee&logoColor=black)](https://buymeacoffee.com/digitalfracture)

A flat-file PHP product catalogue framework for static client sites. Sells fixed-price products, one item per purchase, with one external checkout URL per product. Drops into existing sites at `/shop/`. No database, no frameworks, no JavaScript checkout flows.

**Status: v1 in development.** Design contracts ([FORMAT.md](FORMAT.md), [ARCHITECTURE.md](ARCHITECTURE.md)) are complete. Frontend, admin, image manager, and licence verification are scheduled for v1.0.0 release. Track progress on the [issues board](https://github.com/digifrac/Nano-Cart/issues).

---

## Not for you

Nano Cart is deliberately not a general-purpose e-commerce platform. **It does not support:**

- Size, colour, or other product variants
- Quantity selectors or multi-item shopping carts
- Inventory or stock tracking
- Search, filtering, or pagination
- Tax engines or shipping rules
- Subscriptions or recurring billing
- Catalogues larger than around 150 SKUs

If your shop needs any of these, **use the right tool instead:**

| Need | Use |
|------|-----|
| Variant-heavy retail (clothing, sized goods) | [Shopify](https://shopify.com) |
| Larger catalogues over 150 SKUs | [WooCommerce](https://woocommerce.com) |
| Simple shops with multi-item cart | [Big Cartel](https://bigcartel.com) or [Gumroad](https://gumroad.com) |
| Subscriptions or recurring billing | [Lemon Squeezy](https://lemonsqueezy.com) |

Nano Cart is the right tool for a potter, a print-maker, a jewellery designer, an author, a consultant, or a gallery: anyone selling 20-50 distinct items at fixed prices through hosted checkout links.

---

## How it works

- **Flat-file JSON.** One JSON file per product, one per category. No database. Edit by hand or through the admin.
- **External checkout.** Each product links to a Stripe Payment Link, PayPal hosted checkout, Square, Gumroad, Ko-fi, or any processor-hosted URL. Nano Cart renders its own "Buy" button as a plain `<a href>` to that URL. No SDKs, no embed code, no JavaScript on the shop page.
- **Catalogue mode** alternative: replace the buy button with a site-wide enquiry action (mailto, contact form, Calendly, WhatsApp) for businesses selling through quotes.
- **Removable admin.** A portable admin folder you upload via SFTP when you want to make changes, then remove. When the admin isn't on the server, it can't be attacked.
- **SEO as a core output.** Every page renders complete metadata, JSON-LD Product schema, JSON-LD BreadcrumbList, Open Graph and Twitter Card tags, canonical URLs computed at render time. Target Lighthouse SEO score 100.
- **Mobile-first.** Templates designed for a 375px viewport and progressively enhanced for larger screens. Sticky buy button on mobile. Native scroll-snap image gallery. Tap targets ≥ 44px.
- **No frameworks.** Hand-written PHP, hand-written CSS scoped to `nano-cart-*` class names, minimal vanilla JavaScript. The only vendored dependency is Parsedown (single file, no Composer).

---

## Architecture

Three layers:

```
┌─────────────────────────────────────────────────────────────┐
│  Frontend (permanent, in webroot)                           │
│  /shop/  →  core.php, index.php, category.php, product.php  │
│             template.php, generators.php, .htaccess         │
│             assets/, lib/, products/, categories/, media/   │
├─────────────────────────────────────────────────────────────┤
│  Admin (ephemeral, uploaded when needed)                    │
│  /shop/admin/  →  login, CRUD, image manager, settings      │
│                   removed via SFTP after edits              │
├─────────────────────────────────────────────────────────────┤
│  Config (outside webroot, not web-accessible)               │
│  /shop-config/  →  config.json, rate-limit.json             │
│                    password hash, licence key, settings     │
└─────────────────────────────────────────────────────────────┘
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

Same `rsync` approach as Nano CMS. The whole shop is a directory of JSON files and images. A daily rsync of `/shop/` and `/shop-config/` to a backup host is sufficient. Example cron:

```cron
0 3 * * *  rsync -az --delete /var/www/example.com/shop/ backup@host:/backups/example-shop/
5 3 * * *  rsync -az --delete /etc/shop-config/        backup@host:/backups/example-config/
```

Restore is `rsync` in the other direction. No database to dump, no migrations to replay.

---

## Roadmap

**v1.0.0 (in progress).** Six-session build covering frontend, admin, image manager, licence verification, and release polish.

**v1.1+ (planned, post-launch).** Features will be prioritised based on early-adopter feedback. Likely candidates: optional product collections (cross-category groupings), homepage block layouts, multi-language support via per-locale JSON files.

**Explicitly not planned.** Variants, quantity selectors, multi-item carts, inventory, search, tax/shipping engines, subscriptions. These are out of scope by design. See "Not for you" above.

---

## Licensing

**The code is MIT-licensed.** Free to use, fork, modify, and deploy commercially.

**Footer attribution is removable via a per-domain licence:**

| Tier | Price | Use |
|------|-------|-----|
| Single domain | £29 | One shop on one domain |
| Agency 3-pack | £69 | Up to three shops |
| Agency unlimited | £249 | Unlimited domains |

Without a licence, Nano Cart displays a small *"Powered by Nano Cart. Developed by Digital Fracture."* footer on the pages it renders. With a valid licence, the footer is hidden.

Localhost and `.test` / `.local` development domains skip the licence check, so local development is always footer-free.

Buy a licence at [digitalfracture.co.uk/licensing/nano-cart](https://digitalfracture.co.uk/licensing/nano-cart) (available once v1.0.0 ships).

---

## Contributing

Nano Cart is early-stage solo development. Bug reports, feature suggestions, and security issues are welcome via [GitHub Issues](https://github.com/digifrac/Nano-Cart/issues). Formal contribution guidelines will be added once the project stabilises after v1.0.0.

For security issues, please open a private security advisory rather than a public issue.

---

## Support the project

If Nano Cart saves you time on a client project, consider [buying me a coffee](https://buymeacoffee.com/digitalfracture). It helps fund ongoing development and the time spent answering issues.

---

## See also

- [FORMAT.md](FORMAT.md): on-disk format specification (schemas, paths, URLs)
- [ARCHITECTURE.md](ARCHITECTURE.md): runtime architecture and design contracts
- [Nano CMS](https://github.com/digifrac/Nano-CMS): sibling project for publishing blog posts on the same philosophy
- [nanocart.co.uk](https://nanocart.co.uk): marketing site
- [digitalfracture.co.uk](https://digitalfracture.co.uk): the studio behind Nano Cart
