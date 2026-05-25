<?php
/**
 * Nano Cart - save image references for a product or category.
 *
 * The editors are selection-only: they pick images that already live in the
 * media library (uploaded and organised in media.php) and persist the chosen
 * references here. This endpoint only writes the images[] array (product) or
 * the image field (category) into the JSON. Uploading, folders, moving,
 * renaming, and file deletion all live in the media manager.
 *
 * Action:
 *   update  Persist the images[] payload to a product or category.
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/auth.php';
nano_cart_admin_auth_check();

// JSON API: keep PHP diagnostics out of the response body (error log only).
@ini_set('display_errors', '0');

header('Content-Type: application/json');

function nano_cart_admin_api_fail(string $msg, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function nano_cart_admin_api_ok(array $payload): void
{
    echo json_encode(['ok' => true] + $payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nano_cart_admin_api_fail('POST required.', 405);
}
if (!nano_cart_admin_csrf_verify()) {
    nano_cart_admin_api_fail('Your session expired. Reload the page and log in again.', 403);
}
if ((string)($_POST['action'] ?? 'update') !== 'update') {
    nano_cart_admin_api_fail('Unknown action.');
}

$type   = (string)($_POST['target_type'] ?? '');
$id     = (string)($_POST['target_id'] ?? '');
$images = json_decode((string)($_POST['images'] ?? '[]'), true);
if (!is_array($images)) {
    nano_cart_admin_api_fail('images payload must be a JSON array.');
}

// Sanitise: keep file (owner-relative ref), alt, is_primary. A ref is a
// slug-like basename, optionally inside one slug-like subfolder.
$clean = [];
foreach ($images as $img) {
    if (!is_array($img)) continue;
    $file = trim((string)($img['file'] ?? ''));
    if ($file === '' || str_contains($file, '..')) continue;
    if (!preg_match('#^([a-z0-9]([a-z0-9-]*[a-z0-9])?/)?[a-z0-9]([a-z0-9-]*[a-z0-9])?$#', $file)) continue;
    $clean[] = [
        'file'       => $file,
        'alt'        => trim((string)($img['alt'] ?? '')),
        'is_primary' => !empty($img['is_primary']),
    ];
}

// Exactly one primary.
if (!empty($clean) && !array_filter($clean, static fn($i) => $i['is_primary'])) {
    $clean[0]['is_primary'] = true;
} elseif (!empty($clean)) {
    $seen = false;
    foreach ($clean as $idx => $img) {
        if ($img['is_primary']) {
            if (!$seen) { $seen = true; continue; }
            $clean[$idx]['is_primary'] = false;
        }
    }
}

if ($type === 'product') {
    if (!nano_cart_slug_ok($id)) nano_cart_admin_api_fail('Invalid product SKU.');
    $p = nano_cart_load_product($id);
    if ($p === null) nano_cart_admin_api_fail('Product not found.', 404);
    $p['images'] = $clean;
    if (!nano_cart_admin_save_product($p)) {
        nano_cart_admin_api_fail('Could not save product.', 500);
    }
} elseif ($type === 'category') {
    if (!nano_cart_slug_ok($id)) nano_cart_admin_api_fail('Invalid category slug.');
    $c = nano_cart_load_category($id);
    if ($c === null) nano_cart_admin_api_fail('Category not found.', 404);
    $c['image'] = $clean[0]['file'] ?? null;  // categories store one banner
    if (!nano_cart_admin_save_category($c)) {
        nano_cart_admin_api_fail('Could not save category.', 500);
    }
} else {
    nano_cart_admin_api_fail('target_type must be "product" or "category".');
}

nano_cart_admin_api_ok(['images' => $clean]);
