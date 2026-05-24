# Upgrading Nano Cart

v1.0.0 is the first release, so there is no upgrade path yet. This document records the upgrade philosophy so it is in place when v1.0.1, v1.1, and later versions land.

---

## Philosophy

Nano Cart is flat-file by design. The data is JSON files on disk, the code is PHP files on disk. Upgrading is mostly:

1. Back up the current `/shop/` and `/shop-config/` directories.
2. Replace the framework files (`core.php`, `index.php`, `category.php`, `product.php`, `template.php`, `generators.php`, `licence.php`, `nano-preflight.php`, `.htaccess`, `assets/`, `lib/`) with the new release.
3. Leave the data directories alone (`products/`, `categories/`, `media/`, `bootstrap.php`).
4. Read the [CHANGELOG.md](CHANGELOG.md) entry for the new version to confirm whether any data-format changes need attention.

The admin folder (`admin/`) follows the same rule: upload the new release's `admin/` when you need to make edits, remove it afterwards.

---

## Before upgrading

**Back up** with rsync, the same approach documented in the [README backup section](README.md#backup). Restore is trivial if anything goes wrong:

```bash
rsync -az --delete /var/www/example.com/shop/ /var/backups/example-shop-$(date +%Y-%m-%d)/
rsync -az --delete /etc/shop-config/        /var/backups/example-config-$(date +%Y-%m-%d)/
```

Test the new release on a staging copy first if possible.

---

## Version-specific notes

Per-version upgrade notes are appended to this file as releases happen, plus mirrored in [CHANGELOG.md](CHANGELOG.md).

### v1.0.0 (current)

Initial release. No upgrade path applies.

---

## When a release changes the on-disk format

If a future release bumps the `format_version` in `config.json`, the release notes will spell out exactly what needs to change in your JSON files. The flat-file design means migrations are usually one of:

- A small `migrate.php` script you run once to rewrite affected JSON
- A field rename or addition you can do by hand on a small catalogue

Major format changes are deliberately rare. The contracts in [FORMAT.md](FORMAT.md) and [ARCHITECTURE.md](ARCHITECTURE.md) are designed to be stable.
