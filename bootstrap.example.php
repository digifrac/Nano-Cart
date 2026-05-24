<?php
/**
 * Nano Cart - per-site bootstrap.
 *
 * Copy this file to bootstrap.php and edit the paths below for your
 * install. bootstrap.php itself is gitignored (and SHOULD be excluded
 * from any production backup that ends up in a public repo).
 *
 * Loaded by index.php / category.php / product.php / generators.php /
 * nano-preflight.php at the top, before anything else runs.
 */

/**
 * Outside-webroot config directory. Holds config.json (settings,
 * bcrypt password hash, licence key) and rate-limit.json (per-IP login
 * backoff state). MUST NOT be web-accessible.
 *
 * Recommended layout: one level above the webroot.
 *   /var/www/example.com/public_html/shop/    <- webroot, contains this file
 *   /var/www/example.com/shop-config/         <- contains config.json
 */
define('NANO_CART_CONFIG_PATH',     '/path/to/shop-config/config.json');
define('NANO_CART_RATE_LIMIT_PATH', '/path/to/shop-config/rate-limit.json');

/**
 * In-webroot content directories. Defaults assume Nano Cart lives in
 * the /shop/ directory under the webroot and reads its own subfolders.
 * Override only if you've split the layout.
 */
define('NANO_CART_PRODUCTS_PATH',   __DIR__ . '/products');
define('NANO_CART_CATEGORIES_PATH', __DIR__ . '/categories');
define('NANO_CART_MEDIA_PATH',      __DIR__ . '/media');

/* Mark bootstrap complete. Required gate; core.php refuses to load
   without it. */
define('NANO_CART_BOOTSTRAPPED', true);

require __DIR__ . '/core.php';
