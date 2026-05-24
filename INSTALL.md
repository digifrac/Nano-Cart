# Installing Nano Cart

This guide walks through deploying Nano Cart on a client site. It assumes you are the developer setting it up; the operator who runs the shop afterwards needs only the admin password.

---

## Prerequisites checklist

- A web host with **PHP 8.1 or newer** and **Apache with `mod_rewrite`** (or an equivalent rewrite engine on nginx/Caddy)
- **SFTP access** to the webroot and to one directory above it
- **HTTPS** configured on the domain (essential: the admin uses Secure cookies and the buy button flow assumes secure context)
- **GD extension** for PHP (built into most PHP installs). Imagick works as a fallback.
- **libsodium** (built into PHP 8.1+) for licence verification

Quick check from a shell on the host:

```bash
php -r "echo PHP_VERSION . PHP_EOL;"
php -r "echo extension_loaded('gd') ? 'GD yes' : 'GD no'; echo PHP_EOL;"
php -r "echo extension_loaded('sodium') ? 'sodium yes' : 'sodium no'; echo PHP_EOL;"
```

If any of those fail, contact the host before continuing.

---

## Step 1: download the release

Grab the latest release zip from [github.com/digifrac/Nano-Cart/releases](https://github.com/digifrac/Nano-Cart/releases) (or `git clone` the repo at the latest tag).

The zip contains:

```
/
  bootstrap.example.php
  core.php
  index.php
  category.php
  product.php
  template.php
  generators.php
  licence.php
  nano-preflight.php
  .htaccess
  assets/
  lib/
  admin/
  seed-data/         (optional, can be deleted before deploy)
  FORMAT.md, ARCHITECTURE.md, README.md, INSTALL.md, etc.
```

---

## Step 2: upload the shop directory

Upload the repo contents (except `admin/`, `seed-data/`, and the .md files if you prefer a clean deploy) into the client webroot at `/shop/`. The resulting layout on the host:

```
/var/www/example.com/public_html/
  shop/
    core.php
    index.php
    category.php
    product.php
    template.php
    generators.php
    licence.php
    nano-preflight.php
    .htaccess
    assets/
    lib/
    products/        (empty for now)
    categories/      (empty for now)
    media/           (empty for now)
```

The empty `products/`, `categories/`, and `media/` directories will be created automatically when the admin saves its first content. If your host won't let PHP create directories, create the three folders by hand and `chmod 755` them.

---

## Step 3: create the outside-webroot config directory

Nano Cart's `config.json` (which holds the admin password hash and licence key) must NOT be web-accessible. Create a directory one level above the webroot:

```
/var/www/example.com/
  shop-config/        (chmod 750, owned by the PHP user)
  public_html/
    shop/             (the webroot upload from Step 2)
```

The PHP process needs read AND write access to `/shop-config/` because the setup wizard writes `config.json` there and the rate-limit tracker writes `rate-limit.json`.

---

## Step 4: upload the admin folder

Upload the `admin/` directory from the release zip into `/shop/admin/`. This is temporary: you will remove it after setup is done.

```
/shop/
  admin/
    auth.php
    setup.php
    login.php
    index.php
    products.php
    product-edit.php
    ...
```

---

## Step 5: run the web installer

Visit `https://example.com/shop/install.php` in a browser.

The installer detects that no `bootstrap.php` exists yet and shows a form with one field: the absolute path for the config directory you created in Step 3. The default suggestion is a sibling of `/shop/` (e.g. `/var/www/example.com/nano-shop-config`).

Submit. The installer:

- Creates the config directory if it does not already exist (`chmod 0750`)
- Writes `bootstrap.php` in `/shop/` with the right path constants
- Hands off to the setup wizard

If PHP cannot create the directory (some shared hosts block writes above the webroot), the installer shows a clear error with the exact shell commands to run manually. Then you reload the installer and it picks up the directory you created.

**After a successful install:**

- The installer shows a prominent "delete me" warning. Delete `/shop/install.php` via SFTP. It served its purpose.
- The installer refuses to run again while `bootstrap.php` exists, so a forgotten `install.php` cannot reconfigure a live shop, but it is still a small fingerprinting risk worth removing.

**Manual fallback (if you do not want to use the installer):**

```bash
cd /var/www/example.com/public_html/shop/
cp bootstrap.example.php bootstrap.php
# edit bootstrap.php, set NANO_CART_CONFIG_PATH and NANO_CART_RATE_LIMIT_PATH
```

The four `NANO_CART_*_PATH` constants point at the four directories Nano Cart needs to find. The two outside-webroot ones (`CONFIG_PATH`, `RATE_LIMIT_PATH`) you set to your `/nano-shop-config/` folder. The three in-webroot ones default to `__DIR__ . '/products'` etc. and need no edit if the layout matches Step 2.

`bootstrap.php` is gitignored by default. Do NOT commit it to a public repo.

---

## Step 6: complete the setup wizard

The installer (or your manual install) finishes by sending you to `https://example.com/shop/admin/setup.php`.

You will see the use-case advisory panel ("Nano Cart works best when..."). Read it, then fill in the form:

- **Site name**: appears in titles and footers
- **Site URL**: with scheme, no trailing slash (e.g. `https://example.com`)
- **Currency**: 3-letter ISO code, e.g. `GBP`, `USD`, `EUR`
- **Shop mode**: `checkout` (each product has a buy-button URL) or `catalogue` (enquiry action)
- **Enquiry action**: only required in catalogue mode (`mailto:hello@example.com` or `https://example.com/contact`)
- **Admin password**: minimum 10 characters. The bcrypt hash is written to `config.json`; the plaintext password is never stored.

Submit. You are logged in and redirected to the dashboard.

---

## Step 7: add a category and a product

From the dashboard:

1. Click **Categories**, then **Add category**. Fill in slug, name, optional banner image and description.
2. Click **Products**, then **Add product**. Fill in SKU, title, descriptions, price (free-form text like `£25.00`), the checkout URL, and the category you just created.
3. Save. The image manager appears on the product edit page once the product exists.
4. Drop one or more images into the upload zone. Three size variants (JPEG + WebP) are generated automatically.
5. Set alt text for each image (required for SEO and accessibility).

Visit `https://example.com/shop/` to see the homepage, your category, and your product. The sitemap is at `https://example.com/shop/sitemap.xml`.

---

## Step 8: remove the admin folder

Once the operator's first edits are in place, **delete the entire `admin/` directory via SFTP**. The recommended posture is "admin not on server unless actively editing": an admin folder that does not exist cannot be brute-forced or fingerprinted.

The next time the operator wants to make changes, re-upload `admin/` from the release zip, edit, and remove again.

---

## Step 9: verify

- Visit each page (homepage, category page, product page) in the browser
- View page source: confirm JSON-LD Product schema on product pages, JSON-LD BreadcrumbList on category and product pages
- Click the buy button: confirm it opens the external checkout URL in a new tab
- Run Lighthouse on a product page: target SEO score 100
- Test on a mobile viewport: layout should be single-column, sticky buy button visible on scroll
- Confirm canonical URLs in page source match the expected URLs

---

## Step 10 (optional): apply a licence

If you have a per-domain licence for this shop, upload the admin temporarily, log in, go to **Licence**, paste the key, click **Verify and save**. The "Powered by Nano Cart" footer attribution disappears on public pages.

Buy a licence at [digitalfracture.co.uk/licensing/nano-cart](https://digitalfracture.co.uk/licensing/nano-cart).

---

## Troubleshooting

### "Nano Cart is not yet configured" page

The frontend can't find `config.json`. Check that:
- `bootstrap.php` exists in `/shop/` and the `NANO_CART_CONFIG_PATH` constant points at a real file
- The PHP user has read access to that path
- You actually ran the setup wizard (Step 6)

### 404 on every URL except `index.php`

`mod_rewrite` is not enabled or `.htaccess` is being ignored. Two common causes:
- Apache `AllowOverride` set to `None` for this directory. Set it to `All` in the vhost config.
- nginx hosting: translate the `.htaccess` rewrite rules into nginx config (the three patterns in `.htaccess` are easy to port).

### "PHP GD extension required" on image upload

Install the GD extension on the host. On Debian/Ubuntu: `sudo apt install php-gd && sudo systemctl restart apache2` (or php-fpm). On shared hosting, ask the host to enable GD. Imagick is supported as a fallback if GD is not available.

### Sideways phone photos

EXIF orientation handling requires the `exif` extension. Most PHP builds have it; if not, install it (`php-exif` on Debian/Ubuntu). Without it, portrait phone uploads may display rotated 90 degrees.

### File permission errors when admin tries to save

The PHP user needs write access to `/shop/products/`, `/shop/categories/`, `/shop/media/`, and the outside-webroot `/shop-config/`. Typical setting: `chown www-data:www-data` (or your host's PHP user) and `chmod 755` on directories, `chmod 644` on files.

### HTTPS not configured

Get a Let's Encrypt cert via Certbot, or have your host enable HTTPS. The admin will work over HTTP locally but Secure-flagged session cookies are silently dropped by browsers on non-HTTPS in production. The buy-button flow also assumes HTTPS for security and conversion.

---

## After install

- The operator can manage products, categories, and settings whenever the admin is uploaded
- The frontend keeps serving the static JSON-driven shop with no admin present
- For backups, see the rsync section in [README.md](README.md)
- For version upgrades when v1.0.1, v1.1, etc. land, see [UPGRADE.md](UPGRADE.md)
