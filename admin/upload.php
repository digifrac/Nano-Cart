<?php
/**
 * Nano Cart - image manager backend.
 *
 * Multi-purpose JSON API serving the admin/image-manager.js front-end.
 * Actions:
 *   upload      One or more files, each saved as a single sanitised JPEG
 *   update      Persist images[] array to product or category JSON
 *   delete      Remove an image (source file + cached variants + array entry)
 *   subfolders  List existing subfolders for a target
 *
 * Pipeline per upload, per file:
 *   1. Validate mime + magic bytes via finfo.
 *   2. Decode via GD (auto-detect by extension).
 *   3. Apply EXIF orientation (JPEG only).
 *   4. Re-encode the cleaned canvas at original (or capped) size as JPEG.
 *   5. Return the saved path and dimensions.
 *
 * Width variants are not produced here. image.php builds and caches them
 * on demand from this single source file (see core.php / .htaccess).
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/auth.php';
nano_cart_admin_auth_check();

header('Content-Type: application/json');

const NANO_CART_ADMIN_UPLOAD_MIME = [
    'jpg'  => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
];

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

/* ----------------------------------------------------------------------- */
/* Target resolution (product / category)                                    */
/* ----------------------------------------------------------------------- */

/**
 * Resolve target_type + target_id to the directory where files belong.
 * Validates both inputs. Returns ['dir' => fs path, 'rel_root' => path
 * relative to /media/].
 */
function nano_cart_admin_resolve_target(string $type, string $id): array
{
    if ($type === 'product') {
        if (!nano_cart_slug_ok($id)) {
            nano_cart_admin_api_fail('Invalid product SKU.');
        }
        return [
            'dir'      => NANO_CART_MEDIA_PATH . '/product-images/' . $id,
            'rel_root' => 'product-images/' . $id,
        ];
    }
    if ($type === 'category') {
        return [
            'dir'      => NANO_CART_MEDIA_PATH . '/category-images',
            'rel_root' => 'category-images',
        ];
    }
    nano_cart_admin_api_fail('target_type must be "product" or "category".');
}

/**
 * Validate an optional subfolder name (one level deep, slug-like).
 * Returns the cleaned subfolder or '' for "no subfolder".
 */
function nano_cart_admin_subfolder_ok(string $sf): string
{
    $sf = trim($sf, "/ \t\n\r\0\x0B");
    if ($sf === '') return '';
    if (!preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $sf)) {
        nano_cart_admin_api_fail('Subfolder name must be lowercase alphanumeric and hyphens, 2+ characters.');
    }
    if (str_contains($sf, '/')) {
        nano_cart_admin_api_fail('Subfolders are limited to one level deep.');
    }
    return $sf;
}

/* ----------------------------------------------------------------------- */
/* Action dispatch                                                           */
/* ----------------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nano_cart_admin_api_fail('POST required.', 405);
}
if (!nano_cart_admin_csrf_verify()) {
    nano_cart_admin_api_fail('CSRF token invalid.', 403);
}

$action = (string)($_POST['action'] ?? 'upload');

switch ($action) {
    case 'upload':     nano_cart_admin_action_upload();     break;
    case 'update':     nano_cart_admin_action_update();     break;
    case 'delete':     nano_cart_admin_action_delete();     break;
    case 'subfolders': nano_cart_admin_action_subfolders(); break;
    default:           nano_cart_admin_api_fail('Unknown action.');
}

/* ----------------------------------------------------------------------- */
/* Upload                                                                    */
/* ----------------------------------------------------------------------- */

function nano_cart_admin_action_upload(): void
{
    if (!extension_loaded('gd')) {
        nano_cart_admin_api_fail('Server is missing the PHP GD extension.', 500);
    }
    $cfg = nano_cart_load_config();
    $target = nano_cart_admin_resolve_target(
        (string)($_POST['target_type'] ?? ''),
        (string)($_POST['target_id'] ?? '')
    );
    $subfolder = nano_cart_admin_subfolder_ok((string)($_POST['subfolder'] ?? ''));
    $dir = $target['dir'] . ($subfolder !== '' ? '/' . $subfolder : '');
    $rel_root = $target['rel_root'] . ($subfolder !== '' ? '/' . $subfolder : '');

    if (empty($_FILES['images'])) {
        nano_cart_admin_api_fail('No files uploaded.');
    }
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        nano_cart_admin_api_fail('Could not create destination directory.', 500);
    }

    $files = nano_cart_admin_normalise_files_array($_FILES['images']);
    $results = [];
    foreach ($files as $idx => $file) {
        $results[] = nano_cart_admin_process_one_upload($file, $dir, $rel_root, $cfg);
    }
    nano_cart_admin_api_ok(['files' => $results]);
}

/**
 * PHP collapses multiple-file uploads into a parallel-arrays shape;
 * unfold it back to a list of single-file entries.
 */
function nano_cart_admin_normalise_files_array(array $entry): array
{
    $out = [];
    if (!is_array($entry['name'] ?? null)) {
        return [$entry];
    }
    $count = count($entry['name']);
    for ($i = 0; $i < $count; $i++) {
        $out[] = [
            'name'     => $entry['name'][$i],
            'type'     => $entry['type'][$i]     ?? '',
            'tmp_name' => $entry['tmp_name'][$i] ?? '',
            'error'    => $entry['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
            'size'     => $entry['size'][$i]     ?? 0,
        ];
    }
    return $out;
}

function nano_cart_admin_process_one_upload(array $file, string $dir, string $rel_root, array $cfg): array
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'name' => $file['name'] ?? '', 'error' => 'Upload error code ' . $err];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'name' => $file['name'] ?? '', 'error' => 'Uploaded file missing.'];
    }
    if ((int)$file['size'] > NANO_CART_ADMIN_MAX_UPLOAD_BYTES) {
        return ['ok' => false, 'name' => $file['name'] ?? '', 'error' => 'File exceeds the 10 MB upload limit.'];
    }

    $orig = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    if (!isset(NANO_CART_ADMIN_UPLOAD_MIME[$ext])) {
        return ['ok' => false, 'name' => $orig, 'error' => 'Allowed types: jpg, png, webp.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if ($mime !== NANO_CART_ADMIN_UPLOAD_MIME[$ext] && !($ext === 'jpg' && $mime === 'image/jpeg')) {
        return ['ok' => false, 'name' => $orig, 'error' => 'File contents do not match the .' . $ext . ' extension.'];
    }

    $img = match ($ext) {
        'jpg'  => @imagecreatefromjpeg($tmp),
        'png'  => @imagecreatefrompng($tmp),
        'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        default=> false,
    };
    if (!$img) return ['ok' => false, 'name' => $orig, 'error' => 'Could not decode image.'];

    // EXIF orientation (JPEG only).
    if ($ext === 'jpg' && function_exists('exif_read_data')) {
        $img = nano_cart_admin_apply_exif_orientation($img, $tmp);
    }

    // Source dimension cap.
    $cap = max(400, min(4000, (int)($cfg['source_max_width'] ?? 1600)));
    if (imagesx($img) > $cap) {
        $img = nano_cart_admin_resize_width($img, $cap);
    }

    // Reserve a safe basename. Collision-resistant via random suffix.
    $base = nano_cart_admin_safe_basename($orig);
    while (is_file($dir . '/' . $base . '.jpg')) {
        $base = $base . '-' . bin2hex(random_bytes(2));
    }

    $q_jpg = max(60, min(95, (int)($cfg['image_quality_jpeg'] ?? 85)));

    // Save the single source file (re-encoded canvas). Width variants are
    // generated on demand by image.php, not here.
    $orig_path = $dir . '/' . $base . '.jpg';
    if (!@imagejpeg($img, $orig_path, $q_jpg)) {
        imagedestroy($img);
        return ['ok' => false, 'name' => $orig, 'error' => 'Could not write image.'];
    }
    @chmod($orig_path, 0644);

    $width  = imagesx($img);
    $height = imagesy($img);
    imagedestroy($img);

    return [
        'ok'       => true,
        'name'     => $orig,
        'file'     => $base,
        'rel_path' => $rel_root . '/' . $base,
        'width'    => $width,
        'height'   => $height,
    ];
}

function nano_cart_admin_apply_exif_orientation($img, string $src)
{
    $exif = @exif_read_data($src);
    if (!$exif || empty($exif['Orientation'])) return $img;
    switch ((int)$exif['Orientation']) {
        case 2: imageflip($img, IMG_FLIP_HORIZONTAL); return $img;
        case 3: return imagerotate($img, 180, 0) ?: $img;
        case 4: imageflip($img, IMG_FLIP_VERTICAL); return $img;
        case 5:
            $r = imagerotate($img, -90, 0);
            if ($r === false) return $img;
            imageflip($r, IMG_FLIP_HORIZONTAL);
            return $r;
        case 6: return imagerotate($img, -90, 0) ?: $img;
        case 7:
            $r = imagerotate($img, 90, 0);
            if ($r === false) return $img;
            imageflip($r, IMG_FLIP_HORIZONTAL);
            return $r;
        case 8: return imagerotate($img, 90, 0) ?: $img;
    }
    return $img;
}

function nano_cart_admin_resize_width($img, int $width)
{
    $sw = imagesx($img);
    $sh = imagesy($img);
    if ($sw <= $width) return $img;
    $new_h = max(1, (int)round($sh * $width / $sw));
    $resized = imagecreatetruecolor($width, $new_h);
    imagealphablending($resized, false);
    imagesavealpha($resized, true);
    imagecopyresampled($resized, $img, 0, 0, 0, 0, $width, $new_h, $sw, $sh);
    return $resized;
}

function nano_cart_admin_safe_basename(string $orig): string
{
    $base = strtolower(pathinfo($orig, PATHINFO_FILENAME));
    $base = preg_replace('/[^a-z0-9-]/', '-', $base);
    $base = trim((string)preg_replace('/-+/', '-', $base), '-');
    if ($base === '' || strlen($base) > 64) {
        $base = date('Y-m-d') . '-' . bin2hex(random_bytes(3));
    }
    return $base;
}

/* ----------------------------------------------------------------------- */
/* Update images[] on a product or category JSON                            */
/* ----------------------------------------------------------------------- */

function nano_cart_admin_action_update(): void
{
    $type = (string)($_POST['target_type'] ?? '');
    $id   = (string)($_POST['target_id'] ?? '');
    $images = json_decode((string)($_POST['images'] ?? '[]'), true);
    if (!is_array($images)) {
        nano_cart_admin_api_fail('images payload must be a JSON array.');
    }

    // Sanitise: only keep file (string), alt (string), is_primary (bool).
    // First entry (by order) is the primary if none is flagged.
    $clean = [];
    foreach ($images as $img) {
        if (!is_array($img)) continue;
        $file = trim((string)($img['file'] ?? ''));
        if ($file === '') continue;
        if (str_contains($file, '..')) continue;
        if (!preg_match('#^([a-z0-9-]+/)?[a-z0-9][a-z0-9-]*[a-z0-9]$#', $file)) continue;
        $clean[] = [
            'file'       => $file,
            'alt'        => trim((string)($img['alt'] ?? '')),
            'is_primary' => !empty($img['is_primary']),
        ];
    }
    if (!empty($clean) && !array_filter($clean, static fn($i) => $i['is_primary'])) {
        $clean[0]['is_primary'] = true;
    } elseif (!empty($clean)) {
        $first = true;
        foreach ($clean as $idx => $img) {
            if ($img['is_primary']) {
                if ($first) { $first = false; continue; }
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
        // Categories have one banner image (the `image` field), not an array.
        $c['image'] = $clean[0]['file'] ?? null;
        if (!nano_cart_admin_save_category($c)) {
            nano_cart_admin_api_fail('Could not save category.', 500);
        }
    } else {
        nano_cart_admin_api_fail('target_type must be "product" or "category".');
    }
    nano_cart_admin_api_ok(['images' => $clean]);
}

/* ----------------------------------------------------------------------- */
/* Delete an image and its variant files                                     */
/* ----------------------------------------------------------------------- */

function nano_cart_admin_action_delete(): void
{
    $type = (string)($_POST['target_type'] ?? '');
    $id   = (string)($_POST['target_id'] ?? '');
    $file = trim((string)($_POST['file'] ?? ''));

    if ($file === '' || str_contains($file, '..')) {
        nano_cart_admin_api_fail('Invalid file path.');
    }
    if (!preg_match('#^([a-z0-9-]+/)?[a-z0-9][a-z0-9-]*[a-z0-9]$#', $file)) {
        nano_cart_admin_api_fail('Invalid file path.');
    }
    $target = nano_cart_admin_resolve_target($type, $id);
    $base = $target['dir'] . '/' . $file;
    $expected_root = realpath($target['dir']);
    if ($expected_root === false) nano_cart_admin_api_fail('Target directory missing.', 404);

    $removed = 0;
    // The source file, plus legacy source extensions and any pre-generated
    // variant files left behind by the old (pre-on-demand) pipeline.
    $candidates = [$base . '.jpg', $base . '.png', $base . '.webp'];
    foreach (['thumb-400', 'hero-800', 'thumb-120'] as $legacy) {
        $candidates[] = $base . '-' . $legacy . '.jpg';
        $candidates[] = $base . '-' . $legacy . '.webp';
    }
    foreach ($candidates as $path) {
        $real = is_file($path) ? realpath($path) : false;
        if ($real === false) continue;
        if (!str_starts_with($real, $expected_root)) continue;
        if (@unlink($real)) $removed++;
    }

    // Cached on-demand variants under /media/img/, one per whitelisted width.
    $cache_root = realpath(NANO_CART_MEDIA_PATH . '/img');
    if ($cache_root !== false) {
        $cache_base = NANO_CART_MEDIA_PATH . '/img/' . $target['rel_root'] . '/' . $file;
        foreach (nano_cart_image_widths() as $w) {
            foreach (['jpg', 'webp'] as $ext) {
                $cp = $cache_base . '-' . $w . '.' . $ext;
                $real = is_file($cp) ? realpath($cp) : false;
                if ($real === false || !str_starts_with($real, $cache_root)) continue;
                if (@unlink($real)) $removed++;
            }
        }
    }

    // Remove from product/category JSON's images array.
    if ($type === 'product') {
        $p = nano_cart_load_product($id);
        if ($p !== null) {
            $remaining = array_values(array_filter(
                $p['images'] ?? [],
                static fn($img) => is_array($img) && ($img['file'] ?? '') !== $file
            ));
            // If the deleted image was primary, promote the first remaining.
            $had_primary = false;
            foreach ($remaining as $img) {
                if (!empty($img['is_primary'])) { $had_primary = true; break; }
            }
            if (!$had_primary && !empty($remaining)) {
                $remaining[0]['is_primary'] = true;
            }
            $p['images'] = $remaining;
            nano_cart_admin_save_product($p);
        }
    } elseif ($type === 'category') {
        $c = nano_cart_load_category($id);
        if ($c !== null && (string)($c['image'] ?? '') === $file) {
            $c['image'] = null;
            nano_cart_admin_save_category($c);
        }
    }

    nano_cart_admin_api_ok(['removed_files' => $removed]);
}

/* ----------------------------------------------------------------------- */
/* List existing subfolders                                                  */
/* ----------------------------------------------------------------------- */

function nano_cart_admin_action_subfolders(): void
{
    $target = nano_cart_admin_resolve_target(
        (string)($_POST['target_type'] ?? ''),
        (string)($_POST['target_id'] ?? '')
    );
    $folders = [];
    if (is_dir($target['dir'])) {
        foreach (scandir($target['dir']) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (!is_dir($target['dir'] . '/' . $entry)) continue;
            if (!preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $entry)) continue;
            $folders[] = $entry;
        }
    }
    sort($folders);
    nano_cart_admin_api_ok(['subfolders' => $folders]);
}
