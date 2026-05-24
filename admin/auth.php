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
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
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

    // Banner image and its variants under category-images/.
    $banner_dir = NANO_CART_MEDIA_PATH . '/category-images';
    if (is_dir($banner_dir)) {
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
/* Layout helpers                                                            */
/* ----------------------------------------------------------------------- */

function nano_cart_admin_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function nano_cart_admin_header(string $page_title, string $current_nav = ''): string
{
    $shop = nano_cart_shop_path();
    $admin = $shop . '/admin';
    $items = [
        'dashboard'  => ['Dashboard',  $admin . '/'],
        'products'   => ['Products',   $admin . '/products.php'],
        'categories' => ['Categories', $admin . '/categories.php'],
        'settings'   => ['Settings',   $admin . '/settings.php'],
        'licence'    => ['Licence',    $admin . '/licence.php'],
    ];
    $nav = '';
    foreach ($items as $key => [$label, $url]) {
        $cls = $key === $current_nav ? 'nano-cart-admin-nav-current' : '';
        $nav .= '<a class="nano-cart-admin-nav-link ' . $cls . '" href="' . nano_cart_admin_h($url) . '">' . nano_cart_admin_h($label) . '</a>';
    }
    $title = nano_cart_admin_h($page_title . ' - ' . nano_cart_site_name() . ' admin');
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . $title . '</title>'
        . '<link rel="stylesheet" href="' . nano_cart_admin_h($admin . '/assets/admin.css') . '">'
        . '</head><body class="nano-cart-admin">'
        . '<header class="nano-cart-admin-header">'
        . '<a class="nano-cart-admin-brand" href="' . nano_cart_admin_h($admin . '/') . '">Nano Cart</a>'
        . '<nav class="nano-cart-admin-nav">' . $nav . '</nav>'
        . '<a class="nano-cart-admin-logout" href="' . nano_cart_admin_h($admin . '/logout.php') . '">Log out</a>'
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
        . '</footer>'
        . '<script src="' . nano_cart_admin_h(nano_cart_shop_path() . '/admin/assets/admin.js') . '" defer></script>'
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
