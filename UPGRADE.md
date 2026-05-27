# Upgrading Nano Cart

Nano Cart is flat-file: the code is PHP files on disk, your data is JSON and
images on disk, and your configuration lives outside the webroot. Upgrading is
replacing the **code** with a new release and leaving your **data and config**
untouched. Done the way below, an upgrade cannot lose your products, images, or
settings.

---

## The one rule that prevents broken sites

**Deploy the official release zips. Do not upload a folder of hand-picked
files, and do not upload your own working copy of the project.**

Two things go wrong when people cherry-pick files or copy a development folder
over a live shop:

1. **A required file gets missed.** If even one front-end file (for example
   `nano-preflight.php`) is absent, every public page fails. The complete zip
   can never miss a file.
2. **`bootstrap.php` or `config.json` gets overwritten.** `bootstrap.php` holds
   the absolute path to your config directory and is unique to your server. A
   development copy points somewhere else, so overwriting it breaks the link to
   your configuration and takes the whole shop down. The release zips contain
   `bootstrap.example.php`, never `bootstrap.php`, and never `config.json`, so
   they cannot clobber it.

If you only remember one thing: **extract the zip, never overwrite
`bootstrap.php`, config, or your content folders.**

---

## Files: what a release replaces, and what it must never touch

**Replaced by the release (safe to overwrite):**

- Front end: `core.php`, `index.php`, `category.php`, `product.php`,
  `template.php`, `generators.php`, `licence.php`, `nano-preflight.php`,
  `image.php`, `.htaccess`, `assets/`, `lib/`
- Admin (when present): everything under `admin/`

**Never overwritten or deleted by you during an upgrade (your data and config):**

- `bootstrap.php` (your server's config path; generated once by `install.php`)
- Your config directory outside the webroot (holds `config.json`)
- `products/`, `categories/`, `media/`
- `sitemap.xml` (regenerated automatically)

The release zips are built to respect this: the front-end zip ships empty
`.gitkeep` placeholders for `products/`, `categories/`, and `media/`, so
extracting it adds nothing to those folders and removes nothing from them.

---

## Safe upgrade, step by step

1. **Back up first.** Restore is trivial if anything goes wrong:

   ```bash
   rsync -az --delete /var/www/example.com/shop/ /var/backups/example-shop-$(date +%Y-%m-%d)/
   rsync -az --delete /path/to/your-config-dir/ /var/backups/example-config-$(date +%Y-%m-%d)/
   ```

2. **Read the [CHANGELOG.md](CHANGELOG.md) entry** for the new version to see
   whether anything beyond a file replacement is needed.

3. **Download the release zips** for the new version (`nano-cart-frontend.zip`
   and, if you are editing content, `nano-cart-admin.zip`).

4. **Extract over your shop.** Unzip the front-end zip into the directory that
   contains your shop (it writes into `shop/`), then unzip the admin zip into
   `shop/` (it writes into `shop/admin/`). Upload in **binary** mode if your
   client asks. Because the zips contain no `bootstrap.php`, no config, and no
   content, your data is left exactly as it was.

5. **Confirm health.** Log into the admin dashboard and check the **Health
   check** panel (added in 1.2.0). It verifies PHP version, the GD extension,
   that every required file is present, that config loads, and that `media/` is
   writable. All green means the upgrade landed cleanly. If a required file is
   missing it is named here, so you can re-extract.

6. **Remove the admin folder** when you have finished editing, the same as
   after first install.

If something is wrong, you will see a tidy "temporarily unavailable" page
(added in 1.2.0), not a blank error, and the cause is written to your server's
error log. Restoring the backup from step 1 reverts cleanly.

---

## Version-specific notes

### v1.5.1

Card thumbnail rendering fix. No data migration.

- Thumbnails now use a consistent aspect-ratio that scales with the card, so
  they are framed identically on phones and desktops instead of being cropped
  differently per screen.
- The admin "Card thumbnail height" setting is now "Card thumbnail proportion":
  the stored number (100-600) sets the shape, where 240 is a square, lower is
  wider, higher is taller. Your existing value is kept; if it was not 240 the
  thumbnails may change shape after upgrading.
- If you had selected the "Contain" thumbnail fit, note it now actually applies
  (it was previously ignored).

### v1.2.0

Unified image handling. No data migration. After upgrading:

- A new **Media** tab is the single place to upload and organise images.
- The product and category editors are now **selection-only**: they pick images
  from the media library rather than uploading. Existing image references are
  unchanged and continue to work.
- Removing an image from a product or category now unreferences it; the file
  stays in the library (delete it in the Media tab if you want it gone).

### v1.1.0

Image pipeline changed from pre-generated variants to on-demand resizing.

- No data migration. Existing products and categories work unchanged.
- After upgrading, the old pre-generated variant files
  (`*-thumb-400`, `*-hero-800`, `*-thumb-120`, in JPEG and WebP) are no longer
  used. They are harmless dead weight and can be deleted to reclaim space:

  ```bash
  find /path/to/shop/media -type f \
    \( -name '*-thumb-400.*' -o -name '*-hero-800.*' -o -name '*-thumb-120.*' \) -delete
  ```

- If you had set `thumbnail_widths` in `config.json`, rename it to
  `image_widths`. Otherwise the default `[120, 400, 800]` applies.

### v1.0.0

Initial release. No upgrade path applies.

---

## When a release changes the on-disk format

If a future release bumps the `format_version` in `config.json`, the release
notes will spell out exactly what changes in your JSON files. The flat-file
design keeps migrations small, usually one of:

- A one-time `migrate.php` script you run once to rewrite affected JSON, or
- A field rename or addition you can do by hand on a small catalogue.

Major format changes are deliberately rare. The contracts in
[FORMAT.md](FORMAT.md) and [ARCHITECTURE.md](ARCHITECTURE.md) are designed to be
stable.
