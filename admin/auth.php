<?php
/**
 * Nano Cart - admin shared helpers.
 *
 * Required by every admin page. Provides session / auth gate, CSRF
 * tokens, exponential-backoff rate limiting, and read / write helpers
 * for products, categories, and config.
 *
 * Loaded after the project bootstrap (which defines NANO_CART_*_PATH
 * constants and loads frontend core.php).
 */

if (!defined('NANO_CART_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

const NANO_CART_ADMIN_SESSION_NAME = 'nano_cart_admin';
const NANO_CART_ADMIN_IDLE_TIMEOUT = 3600;
const NANO_CART_ADMIN_MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

/* ----------------------------------------------------------------------- */
/* Session                                                                   */
/* ----------------------------------------------------------------------- */

function nano_cart_admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name(NANO_CART_ADMIN_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    // Idle timeout
    $now = time();
    if (isset($_SESSION['nano_cart_admin_last_seen'])
        && ($now - (int)$_SESSION['nano_cart_admin_last_seen']) > NANO_CART_ADMIN_IDLE_TIMEOUT) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }
    $_SESSION['nano_cart_admin_last_seen'] = $now;
}

function nano_cart_admin_is_logged_in(): bool
{
    nano_cart_admin_session_start();
    return !empty($_SESSION['nano_cart_admin_authed']);
}

function nano_cart_admin_login_success(): void
{
    nano_cart_admin_session_start();
    session_regenerate_id(true);
    $_SESSION['nano_cart_admin_authed'] = true;
    $_SESSION['nano_cart_admin_last_seen'] = time();
}

function nano_cart_admin_logout(): void
{
    nano_cart_admin_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '',
            (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? false));
    }
    session_destroy();
}

/**
 * Gate function. Call at the top of every admin page (except login and
 * setup) to redirect unauthenticated visitors to login.
 */
function nano_cart_admin_auth_check(): void
{
    if (nano_cart_admin_is_logged_in()) return;
    $shop_path = nano_cart_shop_path();
    header('Location: ' . $shop_path . '/admin/login.php', true, 302);
    exit;
}

/* ----------------------------------------------------------------------- */
/* CSRF                                                                      */
/* ----------------------------------------------------------------------- */

function nano_cart_admin_csrf_token(): string
{
    nano_cart_admin_session_start();
    if (empty($_SESSION['nano_cart_admin_csrf'])) {
        $_SESSION['nano_cart_admin_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['nano_cart_admin_csrf'];
}

function nano_cart_admin_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(nano_cart_admin_csrf_token()) . '">';
}

function nano_cart_admin_csrf_verify(): bool
{
    nano_cart_admin_session_start();
    $sent = (string)($_POST['csrf_token'] ?? '');
    $stored = (string)($_SESSION['nano_cart_admin_csrf'] ?? '');
    if ($sent === '' || $stored === '') return false;
    return hash_equals($stored, $sent);
}

function nano_cart_admin_csrf_require(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (nano_cart_admin_csrf_verify()) return;
    http_response_code(403);
    echo 'CSRF token invalid. Refresh and try again.';
    exit;
}

/* ----------------------------------------------------------------------- */
/* Rate limiting (exponential backoff, never hard lockout)                  */
/* ----------------------------------------------------------------------- */

function nano_cart_admin_client_ip(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    // Proxy headers are attacker-spoofable on a direct-connected host, which
    // would let a brute-forcer rotate the rate-limit bucket on every request.
    // Only honour them when the operator has set "trust_proxy": true (i.e. the
    // shop genuinely sits behind Cloudflare or a known reverse proxy).
    $trust = false;
    try { $trust = !empty(nano_cart_load_config()['trust_proxy']); } catch (\Throwable $e) { $trust = false; }
    if ($trust) {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
    }
    return $remote;
}

function nano_cart_admin_rate_limit_load(): array
{
    if (!defined('NANO_CART_RATE_LIMIT_PATH')) return [];
    if (!is_file(NANO_CART_RATE_LIMIT_PATH)) return [];
    $parsed = json_decode((string)file_get_contents(NANO_CART_RATE_LIMIT_PATH), true);
    return is_array($parsed) ? $parsed : [];
}

function nano_cart_admin_rate_limit_save(array $state): bool
{
    if (!defined('NANO_CART_RATE_LIMIT_PATH')) return false;
    $dir = dirname(NANO_CART_RATE_LIMIT_PATH);
    if (!is_dir($dir)) return false;
    return (bool)@file_put_contents(NANO_CART_RATE_LIMIT_PATH,
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * Purge records with no activity in the last 24 hours. Called whenever
 * the state file is read.
 */
function nano_cart_admin_rate_limit_purge(array $state): array
{
    $cutoff = time() - 86400;
    $out = [];
    foreach ($state as $ip => $rec) {
        if (!is_array($rec)) continue;
        $last = strtotime((string)($rec['last_failure'] ?? '')) ?: 0;
        if ($last < $cutoff) continue;
        $out[$ip] = $rec;
    }
    return $out;
}

/**
 * Delay seconds the next response should wait before reporting back to
 * the client at this IP. Returns 0 if no penalty due.
 */
function nano_cart_admin_rate_limit_delay(string $ip): int
{
    $state = nano_cart_admin_rate_limit_purge(nano_cart_admin_rate_limit_load());
    $failures = (int)($state[$ip]['failures'] ?? 0);
    if ($failures < 5)  return 0;
    if ($failures < 10) return 2;
    if ($failures < 20) return 4;
    if ($failures < 50) return 8;
    return 16;
}

function nano_cart_admin_rate_limit_record_failure(string $ip): void
{
    $state = nano_cart_admin_rate_limit_purge(nano_cart_admin_rate_limit_load());
    $now = gmdate('Y-m-d\TH:i:s\Z');
    if (!isset($state[$ip])) {
        $state[$ip] = ['failures' => 0, 'first_failure' => $now, 'last_failure' => $now];
    }
    $state[$ip]['failures'] = (int)$state[$ip]['failures'] + 1;
    $state[$ip]['last_failure'] = $now;
    nano_cart_admin_rate_limit_save($state);
}

function nano_cart_admin_rate_limit_reset(string $ip): void
{
    $state = nano_cart_admin_rate_limit_purge(nano_cart_admin_rate_limit_load());
    unset($state[$ip]);
    nano_cart_admin_rate_limit_save($state);
}

/* ----------------------------------------------------------------------- */
/* Atomic config save                                                        */
/* ----------------------------------------------------------------------- */

function nano_cart_admin_save_config(array $cfg): bool
{
    $path = NANO_CART_CONFIG_PATH;
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
    }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0640);
    return true;
}

function nano_cart_admin_password_set(string $plain): bool
{
    $cfg = is_file(NANO_CART_CONFIG_PATH) ? nano_cart_load_config() : [];
    $cfg['password_hash'] = password_hash($plain, PASSWORD_BCRYPT);
    return nano_cart_admin_save_config($cfg);
}

function nano_cart_admin_password_check(string $plain): bool
{
    if (!is_file(NANO_CART_CONFIG_PATH)) return false;
    try {
        $cfg = nano_cart_load_config();
    } catch (Throwable $e) {
        return false;
    }
    $hash = (string)($cfg['password_hash'] ?? '');
    if ($hash === '') return false;
    return password_verify($plain, $hash);
}

/* ----------------------------------------------------------------------- */
/* Product / category save and delete                                        */
/* ----------------------------------------------------------------------- */

function nano_cart_admin_atomic_write(string $path, string $contents): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $contents, LOCK_EX) === false) return false;
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0644);
    return true;
}

function nano_cart_admin_product_path(string $sku): string
{
    return NANO_CART_PRODUCTS_PATH . '/' . $sku . '.json';
}

function nano_cart_admin_category_path(string $slug): string
{
    return NANO_CART_CATEGORIES_PATH . '/' . $slug . '.json';
}

/**
 * Save a product. If hero_featured is true, clear it on every other
 * product first (uniqueness enforcement). Then regenerate sitemap.
 */
function nano_cart_admin_save_product(array $product): bool
{
    $sku = (string)($product['sku'] ?? '');
    if (!nano_cart_slug_ok($sku)) return false;

    if (!empty($product['hero_featured'])) {
        nano_cart_admin_clear_other_heroes($sku);
    }

    $now = gmdate('Y-m-d\TH:i:s\Z');
    if (empty($product['created'])) $product['created'] = $now;
    $product['updated'] = $now;
    $product['slug'] = $sku;

    $json = json_encode($product, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    if (!nano_cart_admin_atomic_write(nano_cart_admin_product_path($sku), $json)) return false;

    // Ensure the product's media folder exists so its images can be uploaded
    // and managed in the media manager.
    $image_dir = NANO_CART_MEDIA_PATH . '/product-images/' . $sku;
    if (!is_dir($image_dir)) {
        @mkdir($image_dir, 0755, true);
    }

    nano_cart_admin_regenerate_sitemap();
    return true;
}

function nano_cart_admin_clear_other_heroes(string $current_sku): void
{
    foreach (glob(NANO_CART_PRODUCTS_PATH . '/*.json') ?: [] as $path) {
        $p = json_decode((string)file_get_contents($path), true);
        if (!is_array($p)) continue;
        if (($p['sku'] ?? '') === $current_sku) continue;
        if (empty($p['hero_featured'])) continue;
        $p['hero_featured'] = false;
        $p['updated'] = gmdate('Y-m-d\TH:i:s\Z');
        nano_cart_admin_atomic_write(
            $path,
            json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }
}

function nano_cart_admin_delete_product(string $sku): bool
{
    if (!nano_cart_slug_ok($sku)) return false;
    $path = nano_cart_admin_product_path($sku);
    $real = is_file($path) ? realpath($path) : false;
    $expected_dir = realpath(NANO_CART_PRODUCTS_PATH);
    if ($real === false || $expected_dir === false || dirname($real) !== $expected_dir) {
        return false;
    }
    @unlink($real);

    $image_dir = NANO_CART_MEDIA_PATH . '/product-images/' . $sku;
    if (is_dir($image_dir)) {
        nano_cart_admin_rmtree($image_dir);
    }
    // Cached on-demand variants mirror the source tree under /media/img/.
    $cache_dir = NANO_CART_MEDIA_PATH . '/img/product-images/' . $sku;
    if (is_dir($cache_dir)) {
        nano_cart_admin_rmtree($cache_dir);
    }
    nano_cart_admin_regenerate_sitemap();
    return true;
}

function nano_cart_admin_save_category(array $category): bool
{
    $slug = (string)($category['slug'] ?? '');
    if (!nano_cart_slug_ok($slug)) return false;
    $reserved = ['admin', 'sitemap', 'assets', 'lib', 'products', 'categories', 'media'];
    if (in_array($slug, $reserved, true)) return false;

    $json = json_encode($category, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    if (!nano_cart_admin_atomic_write(nano_cart_admin_category_path($slug), $json)) return false;
    nano_cart_admin_regenerate_sitemap();
    return true;
}

function nano_cart_admin_delete_category(string $slug): bool
{
    if (!nano_cart_slug_ok($slug)) return false;
    $path = nano_cart_admin_category_path($slug);
    $real = is_file($path) ? realpath($path) : false;
    $expected_dir = realpath(NANO_CART_CATEGORIES_PATH);
    if ($real === false || $expected_dir === false || dirname($real) !== $expected_dir) {
        return false;
    }
    @unlink($real);

    // Banner source file under category-images/, plus any cached on-demand
    // variants under img/category-images/.
    foreach ([
        NANO_CART_MEDIA_PATH . '/category-images',
        NANO_CART_MEDIA_PATH . '/img/category-images',
    ] as $banner_dir) {
        if (!is_dir($banner_dir)) continue;
        foreach (glob($banner_dir . '/' . $slug . '*') ?: [] as $f) {
            if (is_file($f)) @unlink($f);
        }
    }
    nano_cart_admin_regenerate_sitemap();
    return true;
}

function nano_cart_admin_products_using_category(string $slug): array
{
    $out = [];
    foreach (nano_cart_load_products(['category' => $slug, 'status' => 'published']) as $p) {
        $out[] = $p['sku'];
    }
    // Also include drafts.
    foreach (nano_cart_load_products(['category' => $slug, 'status' => 'draft']) as $p) {
        $out[] = $p['sku'];
    }
    return $out;
}

function nano_cart_admin_rmtree(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            nano_cart_admin_rmtree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/* ----------------------------------------------------------------------- */
/* Sitemap regeneration after any save                                       */
/* ----------------------------------------------------------------------- */

function nano_cart_admin_regenerate_sitemap(): void
{
    static $loaded = false;
    if (!$loaded) {
        require_once dirname(__DIR__) . '/generators.php';
        $loaded = true;
    }
    @nano_cart_generate_sitemap();
}

/* ----------------------------------------------------------------------- */
/* Health checks (surfaced on the dashboard after an upgrade)                */
/* ----------------------------------------------------------------------- */

/**
 * Verify the installation is intact: PHP version, GD, every required
 * front-end file present, config loadable, media writable. Run on the
 * dashboard so a half-finished upgrade (a file that did not upload) is
 * caught here instead of via a dead public site.
 *
 * @return list<array{label:string, ok:bool, detail:string}>
 */
function nano_cart_admin_health_checks(): array
{
    $root = dirname(__DIR__); // shop root
    $checks = [];

    $php_ok = version_compare(PHP_VERSION, '8.0', '>=');
    $checks[] = ['label' => 'PHP version', 'ok' => $php_ok,
        'detail' => $php_ok ? PHP_VERSION : PHP_VERSION . ' - 8.0 or newer required'];

    $gd = extension_loaded('gd');
    $checks[] = ['label' => 'GD image extension', 'ok' => $gd,
        'detail' => $gd ? 'available' : 'missing - image resizing will not work'];

    $required = [
        'core.php', 'index.php', 'category.php', 'product.php', 'template.php',
        'nano-preflight.php', 'generators.php', 'licence.php', 'image.php',
        'lib/Parsedown.php', '.htaccess',
    ];
    $missing = [];
    foreach ($required as $f) {
        if (!is_file($root . '/' . $f)) $missing[] = $f;
    }
    $checks[] = ['label' => 'Required shop files', 'ok' => empty($missing),
        'detail' => empty($missing) ? 'all present' : 'missing: ' . implode(', ', $missing)];

    $cfg_ok = defined('NANO_CART_CONFIG_PATH') && is_file(NANO_CART_CONFIG_PATH);
    if ($cfg_ok) {
        $cfg_ok = is_array(json_decode((string)@file_get_contents(NANO_CART_CONFIG_PATH), true));
    }
    $checks[] = ['label' => 'Configuration', 'ok' => $cfg_ok,
        'detail' => $cfg_ok ? 'config.json loaded' : 'config.json missing or invalid'];

    $media = defined('NANO_CART_MEDIA_PATH') ? NANO_CART_MEDIA_PATH : $root . '/media';
    $media_target = is_dir($media) ? $media : dirname($media);
    $media_ok = is_writable($media_target);
    $checks[] = ['label' => 'Media folder writable', 'ok' => $media_ok,
        'detail' => $media_ok ? 'writable' : 'not writable - uploads and the image cache will fail'];

    return $checks;
}

/* ----------------------------------------------------------------------- */
/* Layout helpers                                                            */
/* ----------------------------------------------------------------------- */

function nano_cart_admin_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Inline monoline SVG icon for a nav item, keyed by nav slug. Kept inline
 * (no sprite or image file) so the admin stays a self-contained upload with
 * no extra asset requests. Unknown keys fall back to a neutral dot.
 */
function nano_cart_admin_nav_icon(string $key): string
{
    $paths = [
        'dashboard'  => '<rect x="3.5" y="3.5" width="7" height="7"/><rect x="13.5" y="3.5" width="7" height="7"/><rect x="3.5" y="13.5" width="7" height="7"/><rect x="13.5" y="13.5" width="7" height="7"/>',
        'posts'      => '<path d="M6 3.5h9l4 4V20.5H6z"/><path d="M15 3.5V8h4"/><path d="M9 12.5h7M9 16h7"/>',
        'products'   => '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M4 7.5l8 4.5 8-4.5"/><path d="M12 12v9"/>',
        'categories' => '<path d="M3.5 8.5 12 4l8.5 4.5L12 13z"/><path d="M3.5 13.5 12 18l8.5-4.5"/>',
        'media'      => '<rect x="3.5" y="4.5" width="17" height="15"/><circle cx="9" cy="10" r="1.7"/><path d="M20.5 15.5 15 10 4 19.5"/>',
        'settings'   => '<path d="M4 8h9M17 8h3"/><circle cx="15" cy="8" r="2"/><path d="M4 16h3M11 16h9"/><circle cx="9" cy="16" r="2"/>',
        'licence'    => '<path d="M12 3.5 19 6v6c0 4.3-3 7-7 8.5-4-1.5-7-4.2-7-8.5V6z"/><path d="M9 12l2 2 4-4"/>',
        'help'       => '<circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.4a2.4 2.4 0 1 1 3.3 2.3c-.8.4-1.4.9-1.4 1.9"/><path d="M12 16.6h.01"/>',
    ];
    $inner = $paths[$key] ?? '<circle cx="12" cy="12" r="3.2"/>';
    return '<svg class="nano-cart-admin-nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}

function nano_cart_admin_header(string $page_title, string $current_nav = ''): string
{
    $shop = nano_cart_shop_path();
    $admin = $shop . '/admin';
    $items = [
        'dashboard'  => ['Dashboard',  $admin . '/'],
        'products'   => ['Products',   $admin . '/products.php'],
        'categories' => ['Categories', $admin . '/categories.php'],
        'media'      => ['Media',      $admin . '/media.php'],
        'settings'   => ['Settings',   $admin . '/settings.php'],
        'licence'    => ['Licence',    $admin . '/licence.php'],
        'help'       => ['Help',       $admin . '/help.php'],
    ];
    $nav = '';
    foreach ($items as $key => [$label, $url]) {
        $cls = 'nano-cart-admin-nav-link' . ($key === $current_nav ? ' nano-cart-admin-nav-current' : '');
        $nav .= '<a class="' . $cls . '" href="' . nano_cart_admin_h($url) . '"'
            . ($key === $current_nav ? ' aria-current="page"' : '') . '>'
            . nano_cart_admin_nav_icon($key)
            . '<span class="nano-cart-admin-nav-label">' . nano_cart_admin_h($label) . '</span></a>';
    }
    $title = nano_cart_admin_h($page_title . ' - ' . nano_cart_site_name() . ' admin');
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . $title . '</title>'
        . '<link rel="stylesheet" href="' . nano_cart_admin_h($admin . '/assets/admin.css?v=' . NANO_CART_VERSION) . '">'
        . '</head><body class="nano-cart-admin">'
        . '<header class="nano-cart-admin-header">'
        . '<a class="nano-cart-admin-brand" href="' . nano_cart_admin_h($admin . '/') . '"><span class="nano-cart-admin-brand-mark" aria-hidden="true"></span>Nano <span class="nano-cart-admin-brand-tag">Cart</span></a>'
        . '<input type="checkbox" id="nano-cart-admin-navtoggle" class="nano-cart-admin-navtoggle" aria-label="Toggle menu">'
        . '<label class="nano-cart-admin-navtoggle-btn" for="nano-cart-admin-navtoggle" aria-hidden="true"><span class="nano-cart-admin-navtoggle-bars"></span></label>'
        . '<label class="nano-cart-admin-navbackdrop" for="nano-cart-admin-navtoggle" aria-hidden="true"></label>'
        . '<nav class="nano-cart-admin-nav">' . $nav
        . '<a class="nano-cart-admin-logout" href="' . nano_cart_admin_h($admin . '/logout.php') . '">Log out</a>'
        . '</nav>'
        . '</header>'
        . '<main class="nano-cart-admin-main">'
        . '<h1 class="nano-cart-admin-page-title">' . nano_cart_admin_h($page_title) . '</h1>';
}

function nano_cart_admin_footer(): string
{
    $version = defined('NANO_CART_VERSION') ? NANO_CART_VERSION : '';
    $vstring = $version !== '' ? ' v' . nano_cart_admin_h($version) : '';
    return '</main>'
        . '<footer class="nano-cart-admin-footer">'
        . '<p>Nano Cart' . $vstring . ' admin. Remove this folder when done editing.</p>'
        . '<p><a href="https://digitalfracture.co.uk/nano-cart.html" target="_blank" rel="noopener">Buy a licence to remove the footer attribution</a> &middot; '
        . '<a href="https://github.com/digifrac/Nano-Cart" target="_blank" rel="noopener">GitHub</a> &middot; '
        . '<a href="https://buymeacoffee.com/digitalfracture" target="_blank" rel="noopener">Buy me a coffee</a></p>'
        . '</footer>'
        . '<script src="' . nano_cart_admin_h(nano_cart_shop_path() . '/admin/assets/admin.js?v=' . NANO_CART_VERSION) . '" defer></script>'
        . '</body></html>';
}

function nano_cart_admin_flash_get(): ?array
{
    nano_cart_admin_session_start();
    if (empty($_SESSION['nano_cart_admin_flash'])) return null;
    $flash = $_SESSION['nano_cart_admin_flash'];
    unset($_SESSION['nano_cart_admin_flash']);
    return is_array($flash) ? $flash : null;
}

function nano_cart_admin_flash_set(string $type, string $message): void
{
    nano_cart_admin_session_start();
    $_SESSION['nano_cart_admin_flash'] = ['type' => $type, 'message' => $message];
}

function nano_cart_admin_flash_html(): string
{
    $f = nano_cart_admin_flash_get();
    if ($f === null) return '';
    $type = $f['type'] === 'error' ? 'error' : 'success';
    return '<div class="nano-cart-admin-flash nano-cart-admin-flash-' . $type . '">'
        . nano_cart_admin_h((string)$f['message']) . '</div>';
}

function nano_cart_admin_redirect(string $url): void
{
    header('Location: ' . $url, true, 302);
    exit;
}
