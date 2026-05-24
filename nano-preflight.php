<?php
/**
 * Nano Cart - first-run setup detector.
 *
 * Called at the top of each renderer (index/category/product). If
 * config.json is missing, redirect to the admin setup wizard at
 * /shop/admin/setup.php. If the admin folder isn't uploaded either,
 * render a friendly explanation page rather than a 500.
 *
 * The admin doesn't exist yet (it's built in Session 3); the redirect
 * target is documented now so the path is wired up when admin lands.
 */

if (!defined('NANO_CART_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

function nano_cart_preflight(): void
{
    if (defined('NANO_CART_CONFIG_PATH') && is_file(NANO_CART_CONFIG_PATH)) {
        return;
    }

    $shop_path = '/shop';
    $admin_setup = $shop_path . '/admin/setup.php';
    $admin_setup_fs = __DIR__ . '/admin/setup.php';

    if (is_file($admin_setup_fs)) {
        header('Location: ' . $admin_setup, true, 302);
        exit;
    }

    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<title>Nano Cart not configured</title>'
       . '<style>body{font-family:system-ui,sans-serif;max-width:40em;margin:3em auto;padding:0 1em;color:#222;line-height:1.5}h1{font-size:1.4em}code{background:#f4f4f4;padding:.1em .3em;border-radius:3px}</style>'
       . '</head><body>'
       . '<h1>Nano Cart is not yet configured</h1>'
       . '<p>This shop has no <code>config.json</code> and no <code>admin/</code> directory.</p>'
       . '<p>Upload the admin folder (<code>admin/</code>) via SFTP, then visit <code>' . htmlspecialchars($admin_setup) . '</code> to run the setup wizard.</p>'
       . '</body></html>';
    exit;
}
