<?php
/**
 * Nano Cart - on-demand image resizer.
 *
 * Serves a width/format variant of a stored source image, generating it
 * on first request and caching the result to /media/img/. After the first
 * hit the web server serves the cached file directly (see .htaccess), so
 * this script only runs on a cache miss.
 *
 * Routed by .htaccess from /shop/media/img/<path>-<width>.<fmt> to:
 *   image.php?path=<media-relative path, no extension>&w=<width>&fmt=<jpg|webp>
 *
 * The source is always <path>.jpg under /media/. Widths are validated
 * against nano_cart_image_widths(). The resizer never upscales.
 */

require __DIR__ . '/bootstrap.php';

function nano_cart_image_fail(int $status): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $status === 404 ? 'Not found.' : ($status === 400 ? 'Bad request.' : 'Server error.');
    exit;
}

function nano_cart_image_serve(string $file, string $fmt): void
{
    header('Content-Type: ' . ($fmt === 'webp' ? 'image/webp' : 'image/jpeg'));
    header('Content-Length: ' . (string)filesize($file));
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
    readfile($file);
    exit;
}

/* --- Parse and validate the request ------------------------------------ */

$path  = (string)($_GET['path'] ?? '');
$width = (int)($_GET['w'] ?? 0);
$fmt   = (string)($_GET['fmt'] ?? '');

if ($fmt !== 'jpg' && $fmt !== 'webp') {
    nano_cart_image_fail(400);
}
if (!in_array($width, nano_cart_image_widths(), true)) {
    nano_cart_image_fail(400);
}
if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
    nano_cart_image_fail(400);
}
// Path must sit under one of the two media roots, every segment slug-like.
if (!preg_match('#^(product-images|category-images)(/([a-z0-9]|[a-z0-9][a-z0-9-]*[a-z0-9]))+$#', $path)) {
    nano_cart_image_fail(400);
}

$source      = NANO_CART_MEDIA_PATH . '/' . $path . '.jpg';
$real_source = realpath($source);
$media_root  = realpath(NANO_CART_MEDIA_PATH);
if ($real_source === false || $media_root === false
    || !str_starts_with($real_source, $media_root . DIRECTORY_SEPARATOR)) {
    nano_cart_image_fail(404);
}

$cache = NANO_CART_MEDIA_PATH . '/img/' . $path . '-' . $width . '.' . $fmt;

/* --- Serve from cache when it is at least as new as the source --------- */

if (is_file($cache) && filemtime($cache) >= filemtime($real_source)) {
    nano_cart_image_serve($cache, $fmt);
}

/* --- Generate the variant ---------------------------------------------- */

if (!extension_loaded('gd')) {
    nano_cart_image_fail(500);
}
if ($fmt === 'webp' && !function_exists('imagewebp')) {
    // No WebP support: 404 so the <picture> element falls back to the JPEG.
    nano_cart_image_fail(404);
}

$src = @imagecreatefromjpeg($real_source);
if (!$src) {
    nano_cart_image_fail(500);
}

$sw = imagesx($src);
$sh = imagesy($src);
$target_w = min($width, $sw);                       // never upscale
$target_h = max(1, (int)round($sh * $target_w / $sw));

if ($target_w === $sw) {
    $out = $src;
} else {
    $out = imagecreatetruecolor($target_w, $target_h);
    imagecopyresampled($out, $src, 0, 0, 0, 0, $target_w, $target_h, $sw, $sh);
}

$cfg = nano_cart_load_config();
$cache_dir = dirname($cache);
if (!is_dir($cache_dir)) {
    @mkdir($cache_dir, 0755, true);
}

$tmp = $cache . '.tmp.' . bin2hex(random_bytes(4));
$ok = $fmt === 'webp'
    ? @imagewebp($out, $tmp, max(60, min(95, (int)($cfg['image_quality_webp'] ?? 80))))
    : @imagejpeg($out, $tmp, max(60, min(95, (int)($cfg['image_quality_jpeg'] ?? 85))));

if ($out !== $src) imagedestroy($out);
imagedestroy($src);

if (!$ok || !@rename($tmp, $cache)) {
    @unlink($tmp);
    nano_cart_image_fail(500);
}
@chmod($cache, 0644);

nano_cart_image_serve($cache, $fmt);
