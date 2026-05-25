<?php
/**
 * Nano Cart - media manager (self-contained).
 *
 * One file: JSON API + HTML + CSS + JS. Deliberate - separate admin assets
 * were repeatedly served stale from the browser cache. A single PHP file is
 * never cached, so what you upload is what runs.
 *
 * A simple single-pane file browser over /media. You start in the "media"
 * home folder; a breadcrumb shows where you are; you create and delete your
 * own folders, upload, rename, delete, and drag a thumbnail onto a folder to
 * move it. Folders are free-form (none are created automatically). When a
 * file under category-images/ or product-images/<sku>/ is moved, renamed, or
 * deleted, the product or category that references it is updated to match.
 *
 * GET  -> renders the manager page.
 * POST -> JSON API: list, upload, mkdir, deletefolder, rename, move, delete.
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/auth.php';
nano_cart_admin_auth_check();

// JSON API: never let a PHP warning/notice leak into a response body.
@ini_set('display_errors', '0');

// Declared before the dispatch: const is not hoisted like functions.
const NANO_CART_MEDIA_MIME = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    // Self-contained error reporting: any fatal here is returned as JSON with
    // the real cause, so the client never gets a blank 500.
    register_shutdown_function(static function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !headers_sent()) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e['message']]);
        }
    });
    if (!nano_cart_admin_csrf_verify()) {
        nano_cart_media_fail('Your session expired. Reload the page and log in again.', 403);
    }
    try {
        switch ((string)($_POST['action'] ?? '')) {
            case 'list':         nano_cart_media_action_list();         break;
            case 'upload':       nano_cart_media_action_upload();       break;
            case 'copyinto':     nano_cart_media_action_copyinto();     break;
            case 'mkdir':        nano_cart_media_action_mkdir();        break;
            case 'deletefolder': nano_cart_media_action_deletefolder(); break;
            case 'rename':       nano_cart_media_action_rename();       break;
            case 'move':         nano_cart_media_action_move();         break;
            case 'delete':       nano_cart_media_action_delete();       break;
            default:             nano_cart_media_fail('Unknown action.');
        }
    } catch (\Throwable $ex) {
        nano_cart_media_fail('Server error: ' . $ex->getMessage(), 500);
    }
    exit;
}

/* ----------------------------------------------------------------------- */
/* Responses                                                                */
/* ----------------------------------------------------------------------- */

function nano_cart_media_fail(string $msg, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function nano_cart_media_ok(array $payload = []): void
{
    echo json_encode(['ok' => true] + $payload);
    exit;
}

/* ----------------------------------------------------------------------- */
/* Path model (free-form folders under /media; the img/ cache is hidden)    */
/* ----------------------------------------------------------------------- */

function nano_cart_media_seg_ok(string $s): bool
{
    return (bool)preg_match('/^(?:[a-z0-9]|[a-z0-9][a-z0-9-]*[a-z0-9])$/', $s);
}

/**
 * A media-relative directory. '' is the media home, which can hold images
 * directly. Folders are free-form; the only off-limits area is the img/
 * variant cache. Rejects traversal and non-slug segments.
 */
function nano_cart_media_dir_ok(string $rel): bool
{
    $rel = trim($rel, '/');
    if ($rel === '') return true;
    if (str_contains($rel, '..')) return false;
    $parts = explode('/', $rel);
    if ($parts[0] === 'img') return false;
    foreach ($parts as $p) { if (!nano_cart_media_seg_ok($p)) return false; }
    $n = count($parts);
    // One subfolder deep, relative to the owner. category-images sits at
    // depth 1 (so its subfolder is depth 2); a product's folder sits at depth
    // 2 (product-images/<sku>), so its subfolder is depth 3.
    if ($n <= 2) return true;
    if ($n === 3 && $parts[0] === 'product-images') return true;
    return false;
}

/** A media-relative source path (no extension). May sit at the media home. */
function nano_cart_media_file_ok(string $rel): bool
{
    $rel = trim($rel, '/');
    if ($rel === '' || str_contains($rel, '..')) return false;
    $parts = explode('/', $rel);
    if ($parts[0] === 'img') return false;
    foreach ($parts as $p) { if (!nano_cart_media_seg_ok($p)) return false; }
    // The containing folder must be a valid folder (this enforces depth).
    if (count($parts) >= 2 && !nano_cart_media_dir_ok(implode('/', array_slice($parts, 0, -1)))) return false;
    return true;
}

function nano_cart_media_fs(string $relative): string
{
    return rtrim(NANO_CART_MEDIA_PATH . '/' . $relative, '/');
}
function nano_cart_media_contained(string $abs): bool
{
    $root = realpath(NANO_CART_MEDIA_PATH);
    $real = realpath($abs);
    if ($root === false || $real === false) return false;
    return $real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR);
}

/**
 * For a source path (no extension), work out which product/category "owns"
 * it and the reference string they store, so references can be kept in sync.
 * Returns [owner|null, ref|null].
 */
function nano_cart_media_owner_ref(string $rel_no_ext): array
{
    $parts = explode('/', trim($rel_no_ext, '/'));
    if ($parts[0] === 'category-images' && count($parts) >= 2) {
        return ['category-images', implode('/', array_slice($parts, 1))];
    }
    if ($parts[0] === 'product-images' && count($parts) >= 3) {
        return ['product-images/' . $parts[1], implode('/', array_slice($parts, 2))];
    }
    return [null, null];
}

/* ----------------------------------------------------------------------- */
/* Cached-variant purge + reference rewrite                                 */
/* ----------------------------------------------------------------------- */

function nano_cart_media_purge_cache(string $rel_no_ext): void
{
    $cache_root = realpath(NANO_CART_MEDIA_PATH . '/img');
    if ($cache_root === false) return;
    // Match every cached variant for this source regardless of width, so a
    // change to image_widths never leaves stale files behind. The regex pins
    // "<base>-<digits>.<jpg|webp>" so a sibling like "<base>-2" is not caught.
    $dir  = NANO_CART_MEDIA_PATH . '/img' . (str_contains($rel_no_ext, '/') ? '/' . dirname($rel_no_ext) : '');
    $base = basename($rel_no_ext);
    foreach (glob($dir . '/' . $base . '-*') ?: [] as $cp) {
        if (!preg_match('/^' . preg_quote($base, '/') . '-\d+\.(?:jpg|webp)$/', basename($cp))) continue;
        $real = is_file($cp) ? realpath($cp) : false;
        if ($real === false || !str_starts_with($real, $cache_root . DIRECTORY_SEPARATOR)) continue;
        @unlink($real);
    }
}

function nano_cart_media_usage(?string $owner, ?string $ref): array
{
    if ($owner === null || $ref === null) return [];
    $labels = [];
    if ($owner === 'category-images') {
        foreach (nano_cart_load_categories() as $c) {
            if ((string)($c['image'] ?? '') === $ref) {
                $labels[] = 'Category: ' . (string)($c['name'] ?? $c['slug'] ?? '');
            }
        }
        return $labels;
    }
    $sku = substr($owner, strlen('product-images/'));
    $p = nano_cart_load_product($sku);
    if ($p !== null) {
        foreach (($p['images'] ?? []) as $img) {
            if (is_array($img) && (string)($img['file'] ?? '') === $ref) {
                $labels[] = 'Product: ' . (string)($p['title'] ?? $sku);
                break;
            }
        }
    }
    return $labels;
}

function nano_cart_media_rewrite_refs(?string $owner, ?string $old_ref, ?string $new_ref): int
{
    if ($owner === null || $old_ref === null) return 0;
    $changed = 0;
    if ($owner === 'category-images') {
        foreach (nano_cart_load_categories() as $c) {
            if ((string)($c['image'] ?? '') !== $old_ref) continue;
            $c['image'] = $new_ref;
            if (nano_cart_admin_save_category($c)) $changed++;
        }
        return $changed;
    }
    $sku = substr($owner, strlen('product-images/'));
    $p = nano_cart_load_product($sku);
    if ($p === null || !is_array($p['images'] ?? null)) return 0;
    $touched = false;
    $images = [];
    foreach ($p['images'] as $img) {
        if (!is_array($img)) continue;
        if ((string)($img['file'] ?? '') === $old_ref) {
            $touched = true;
            if ($new_ref === null) continue;
            $img['file'] = $new_ref;
        }
        $images[] = $img;
    }
    if (!$touched) return 0;
    if (!empty($images) && !array_filter($images, static fn($i) => !empty($i['is_primary']))) {
        $images[0]['is_primary'] = true;
    }
    $p['images'] = array_values($images);
    if (nano_cart_admin_save_product($p)) $changed++;
    return $changed;
}

/* ----------------------------------------------------------------------- */
/* Upload pipeline (sanitise + single source JPEG)                          */
/* ----------------------------------------------------------------------- */

function nano_cart_media_normalise_files(array $entry): array
{
    if (!is_array($entry['name'] ?? null)) return [$entry];
    $out = [];
    for ($i = 0, $n = count($entry['name']); $i < $n; $i++) {
        $out[] = [
            'name'     => $entry['name'][$i],
            'tmp_name' => $entry['tmp_name'][$i] ?? '',
            'error'    => $entry['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
            'size'     => $entry['size'][$i]     ?? 0,
        ];
    }
    return $out;
}

function nano_cart_media_safe_basename(string $orig): string
{
    $base = strtolower(pathinfo($orig, PATHINFO_FILENAME));
    $base = preg_replace('/[^a-z0-9-]/', '-', $base);
    $base = trim((string)preg_replace('/-+/', '-', $base), '-');
    if ($base === '' || strlen($base) > 64) {
        $base = date('Y-m-d') . '-' . bin2hex(random_bytes(3));
    }
    return $base;
}

function nano_cart_media_apply_exif($img, string $src)
{
    $exif = @exif_read_data($src);
    if (!$exif || empty($exif['Orientation'])) return $img;
    switch ((int)$exif['Orientation']) {
        case 2: imageflip($img, IMG_FLIP_HORIZONTAL); return $img;
        case 3: return imagerotate($img, 180, 0) ?: $img;
        case 4: imageflip($img, IMG_FLIP_VERTICAL); return $img;
        case 5: $r = imagerotate($img, -90, 0); if ($r === false) return $img; imageflip($r, IMG_FLIP_HORIZONTAL); return $r;
        case 6: return imagerotate($img, -90, 0) ?: $img;
        case 7: $r = imagerotate($img, 90, 0); if ($r === false) return $img; imageflip($r, IMG_FLIP_HORIZONTAL); return $r;
        case 8: return imagerotate($img, 90, 0) ?: $img;
    }
    return $img;
}

function nano_cart_media_resize_width($img, int $width)
{
    $sw = imagesx($img); $sh = imagesy($img);
    if ($sw <= $width) return $img;
    $h = max(1, (int)round($sh * $width / $sw));
    $out = imagecreatetruecolor($width, $h);
    imagealphablending($out, false); imagesavealpha($out, true);
    imagecopyresampled($out, $img, 0, 0, 0, 0, $width, $h, $sw, $sh);
    return $out;
}

function nano_cart_media_save_one(array $file, string $dir, array $cfg): array
{
    $orig = (string)($file['name'] ?? '');
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'name' => $orig, 'error' => 'Upload failed.'];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'name' => $orig, 'error' => 'Uploaded file missing.'];
    }
    if ((int)($file['size'] ?? 0) > NANO_CART_ADMIN_MAX_UPLOAD_BYTES) {
        return ['ok' => false, 'name' => $orig, 'error' => 'File exceeds the 10 MB limit.'];
    }
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    if (!isset(NANO_CART_MEDIA_MIME[$ext])) {
        return ['ok' => false, 'name' => $orig, 'error' => 'Allowed types: jpg, png, webp.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if ($mime !== NANO_CART_MEDIA_MIME[$ext] && !($ext === 'jpg' && $mime === 'image/jpeg')) {
        return ['ok' => false, 'name' => $orig, 'error' => 'Contents do not match the .' . $ext . ' extension.'];
    }
    $img = match ($ext) {
        'jpg'  => @imagecreatefromjpeg($tmp),
        'png'  => @imagecreatefrompng($tmp),
        'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        default => false,
    };
    if (!$img) return ['ok' => false, 'name' => $orig, 'error' => 'Could not decode image.'];

    if ($ext === 'jpg' && function_exists('exif_read_data')) {
        $img = nano_cart_media_apply_exif($img, $tmp);
    }
    $cap = max(400, min(4000, (int)($cfg['source_max_width'] ?? 1600)));
    if (imagesx($img) > $cap) $img = nano_cart_media_resize_width($img, $cap);

    $base = nano_cart_media_safe_basename($orig);
    $try = $base;
    while (is_file($dir . '/' . $try . '.jpg')) { $try = $base . '-' . bin2hex(random_bytes(2)); }
    $base = $try;
    $q = max(60, min(95, (int)($cfg['image_quality_jpeg'] ?? 85)));
    if (!@imagejpeg($img, $dir . '/' . $base . '.jpg', $q)) {
        imagedestroy($img);
        return ['ok' => false, 'name' => $orig, 'error' => 'Could not write image (check folder permissions).'];
    }
    @chmod($dir . '/' . $base . '.jpg', 0644);
    imagedestroy($img);
    return ['ok' => true, 'name' => $orig, 'file' => $base];
}

/* ----------------------------------------------------------------------- */
/* Scanning                                                                 */
/* ----------------------------------------------------------------------- */

/** Image source files (.jpg) directly inside a media-relative folder. */
function nano_cart_media_scan_files(string $dir): array
{
    $abs = nano_cart_media_fs($dir);
    $files = [];
    if (!is_dir($abs)) return $files;
    foreach (scandir($abs) ?: [] as $entry) {
        if (!str_ends_with($entry, '.jpg')) continue;
        $b = substr($entry, 0, -4);
        if (preg_match('/-(?:thumb-400|hero-800|thumb-120)$/', $b)) continue;
        if (!nano_cart_media_seg_ok($b)) continue;
        $rel = ($dir !== '' ? $dir . '/' : '') . $b;
        [$owner, $ref] = nano_cart_media_owner_ref($rel);
        $files[] = [
            'name'    => $b,
            'path'    => $rel,
            'thumb'   => nano_cart_image_url($rel, 'gallery-thumb', 'jpg'),
            'used_by' => nano_cart_media_usage($owner, $ref),
        ];
    }
    usort($files, static fn($a, $b) => strcmp($a['name'], $b['name']));
    return $files;
}

/** Immediate subfolders of a media-relative folder (img cache hidden). */
function nano_cart_media_subfolders(string $dir): array
{
    $abs = nano_cart_media_fs($dir);
    $out = [];
    if (!is_dir($abs)) return $out;
    foreach (scandir($abs) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        if ($dir === '' && $e === 'img') continue; // hide the variant cache
        if (!is_dir($abs . '/' . $e) || !nano_cart_media_seg_ok($e)) continue;
        $out[] = ['name' => $e, 'path' => ($dir !== '' ? $dir . '/' : '') . $e];
    }
    usort($out, static fn($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

/* ----------------------------------------------------------------------- */
/* Actions                                                                  */
/* ----------------------------------------------------------------------- */

function nano_cart_media_action_list(): void
{
    $dir = trim((string)($_POST['dir'] ?? ''), '/');
    if (!nano_cart_media_dir_ok($dir)) nano_cart_media_fail('Invalid folder.');

    if ($dir === '') {
        // Keep the two structural folders present so the shop and the manager
        // always have them, even if everything else is cleared.
        foreach (['category-images', 'product-images'] as $d) {
            $p = nano_cart_media_fs($d);
            if (!is_dir($p)) @mkdir($p, 0755, true);
        }
    } else {
        $abs = nano_cart_media_fs($dir);
        if (!is_dir($abs) || !nano_cart_media_contained($abs)) nano_cart_media_fail('Folder not found.', 404);
    }

    $crumbs = []; $acc = '';
    foreach ($dir === '' ? [] : explode('/', $dir) as $seg) {
        $acc = $acc === '' ? $seg : $acc . '/' . $seg;
        $crumbs[] = ['name' => $seg, 'path' => $acc];
    }
    $depth = $dir === '' ? 0 : count(explode('/', $dir));

    $folders = [];
    foreach (nano_cart_media_subfolders($dir) as $sf) {
        // The two structural folders are permanent; everything else can go.
        $sf['deletable'] = !in_array($sf['path'], ['category-images', 'product-images'], true);
        $folders[] = $sf;
    }

    nano_cart_media_ok([
        'dir'        => $dir,
        'parent'     => $dir === '' ? null : (str_contains($dir, '/') ? substr($dir, 0, strrpos($dir, '/')) : ''),
        'crumbs'     => $crumbs,
        'folders'    => $folders,
        'files'      => nano_cart_media_scan_files($dir),
        'can_upload' => true,
        'can_mkdir'  => nano_cart_media_dir_ok(($dir === '' ? '' : $dir . '/') . 'a'),
        'note'       => $dir === '' ? 'media is the home folder. Add images here, or open a folder.' : '',
    ]);
}

function nano_cart_media_action_upload(): void
{
    if (!extension_loaded('gd')) nano_cart_media_fail('Server is missing the PHP GD extension.', 500);
    @ini_set('memory_limit', '256M');
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        nano_cart_media_fail('That upload was larger than the server allows (post_max_size).', 413);
    }
    $dir = trim((string)($_POST['dir'] ?? ''), '/');
    if (!nano_cart_media_dir_ok($dir)) nano_cart_media_fail('Invalid folder.');
    if (empty($_FILES['files'])) nano_cart_media_fail('No files received.');
    $abs = nano_cart_media_fs($dir);
    if (!is_dir($abs) && !@mkdir($abs, 0755, true) && !is_dir($abs)) {
        nano_cart_media_fail('Could not create the destination folder.', 500);
    }
    $cfg = nano_cart_load_config();
    $results = [];
    foreach (nano_cart_media_normalise_files($_FILES['files']) as $f) {
        $results[] = nano_cart_media_save_one($f, $abs, $cfg);
    }
    nano_cart_media_ok(['files' => $results, 'dir' => $dir]);
}

/**
 * Copy a library image into a product/category folder so the editor can
 * reference it. If the source already lives under the owner, no copy is made.
 * Returns the reference (path relative to the owner root) to store in JSON.
 */
function nano_cart_media_action_copyinto(): void
{
    $src   = trim((string)($_POST['src'] ?? ''), '/');     // full media path, no extension
    $owner = trim((string)($_POST['owner'] ?? ''), '/');   // category-images | product-images/<sku>
    if (!nano_cart_media_file_ok($src)) nano_cart_media_fail('Invalid image.');
    if (!nano_cart_media_dir_ok($owner)) nano_cart_media_fail('Invalid destination.');
    $src_abs = nano_cart_media_fs($src . '.jpg');
    if (!is_file($src_abs) || !nano_cart_media_contained($src_abs)) nano_cart_media_fail('Image not found.', 404);

    // Already under the owner: just return the owner-relative reference.
    if ($src === $owner || str_starts_with($src, $owner . '/')) {
        nano_cart_media_ok(['file' => substr($src, strlen($owner) + 1)]);
    }

    $dest_dir = nano_cart_media_fs($owner);
    if (!is_dir($dest_dir) && !@mkdir($dest_dir, 0755, true) && !is_dir($dest_dir)) {
        nano_cart_media_fail('Could not create the destination folder.', 500);
    }
    $base = basename($src);
    $try = $base;
    while (is_file($dest_dir . '/' . $try . '.jpg')) { $try = $base . '-' . bin2hex(random_bytes(2)); }
    if (!@copy($src_abs, $dest_dir . '/' . $try . '.jpg')) {
        nano_cart_media_fail('Could not copy the image into the folder.', 500);
    }
    @chmod($dest_dir . '/' . $try . '.jpg', 0644);
    nano_cart_media_ok(['file' => $try]);
}

function nano_cart_media_action_mkdir(): void
{
    $parent = trim((string)($_POST['dir'] ?? ''), '/');
    $name = strtolower(trim((string)($_POST['name'] ?? '')));
    if (!nano_cart_media_dir_ok($parent)) nano_cart_media_fail('Invalid parent folder.');
    if (!nano_cart_media_seg_ok($name)) {
        nano_cart_media_fail('Folder name must be lowercase letters, numbers and hyphens.');
    }
    $rel = ($parent !== '' ? $parent . '/' : '') . $name;
    if (!nano_cart_media_dir_ok($rel)) {
        nano_cart_media_fail('Only one subfolder deep is allowed inside this folder.');
    }
    $dir = nano_cart_media_fs($rel);
    if (is_dir($dir)) nano_cart_media_fail('A folder named "' . $name . '" already exists here.');
    if (!@mkdir($dir, 0755, true) && !is_dir($dir)) nano_cart_media_fail('Could not create the folder.', 500);
    nano_cart_media_ok(['created' => $rel]);
}

/** Delete a folder and everything in it, cleaning up references + cache. */
function nano_cart_media_action_deletefolder(): void
{
    $path = trim((string)($_POST['path'] ?? ''), '/');
    if ($path === '' || !nano_cart_media_dir_ok($path)) nano_cart_media_fail('Invalid folder.');
    if (in_array($path, ['category-images', 'product-images'], true)) {
        nano_cart_media_fail('category-images and product-images are part of the shop and cannot be deleted.');
    }
    $abs = nano_cart_media_fs($path);
    if (!is_dir($abs) || !nano_cart_media_contained($abs)) nano_cart_media_fail('Folder not found.', 404);

    // Drop references to every source file inside the folder, then delete.
    $sources = [];
    nano_cart_media_collect_sources($abs, $path, $sources);
    $updated = 0;
    foreach ($sources as $rel) {
        [$owner, $ref] = nano_cart_media_owner_ref($rel);
        $updated += nano_cart_media_rewrite_refs($owner, $ref, null);
        nano_cart_media_purge_cache($rel);
    }
    nano_cart_admin_rmtree($abs);
    $cache = NANO_CART_MEDIA_PATH . '/img/' . $path;
    if (is_dir($cache)) nano_cart_admin_rmtree($cache);
    nano_cart_media_ok(['removed' => $path, 'refs_updated' => $updated]);
}

function nano_cart_media_collect_sources(string $absDir, string $relDir, array &$out): void
{
    foreach (scandir($absDir) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        $abs = $absDir . '/' . $e;
        $rel = ($relDir !== '' ? $relDir . '/' : '') . $e;
        if (is_dir($abs)) {
            nano_cart_media_collect_sources($abs, $rel, $out);
        } elseif (str_ends_with($e, '.jpg')) {
            $b = substr($rel, 0, -4);
            if (!preg_match('/-(?:thumb-400|hero-800|thumb-120)$/', $b)) $out[] = $b;
        }
    }
}

function nano_cart_media_action_rename(): void
{
    $path = trim((string)($_POST['path'] ?? ''), '/');
    if (!nano_cart_media_file_ok($path)) nano_cart_media_fail('Invalid file.');
    $newname = strtolower(trim((string)($_POST['newname'] ?? '')));
    if (!nano_cart_media_seg_ok($newname)) nano_cart_media_fail('Name must be lowercase letters, numbers and hyphens.');
    $base = strrpos($path, '/') !== false ? substr($path, strrpos($path, '/') + 1) : $path;
    $parent = strrpos($path, '/') !== false ? substr($path, 0, strrpos($path, '/')) : '';
    if ($newname === $base) nano_cart_media_ok(['unchanged' => true]);
    $src = nano_cart_media_fs($path . '.jpg');
    if (!is_file($src) || !nano_cart_media_contained($src)) nano_cart_media_fail('File not found.', 404);
    $new_rel = ($parent !== '' ? $parent . '/' : '') . $newname;
    if (is_file(nano_cart_media_fs($new_rel . '.jpg'))) nano_cart_media_fail('A file named "' . $newname . '" already exists here.');
    if (!@rename($src, nano_cart_media_fs($new_rel . '.jpg'))) nano_cart_media_fail('Could not rename the file.', 500);
    nano_cart_media_purge_cache($path);
    [$o1, $r1] = nano_cart_media_owner_ref($path);
    [$o2, $r2] = nano_cart_media_owner_ref($new_rel);
    $updated = ($o1 === $o2) ? nano_cart_media_rewrite_refs($o1, $r1, $r2) : nano_cart_media_rewrite_refs($o1, $r1, null);
    nano_cart_media_ok(['refs_updated' => $updated]);
}

function nano_cart_media_action_move(): void
{
    $path = trim((string)($_POST['path'] ?? ''), '/');
    if (!nano_cart_media_file_ok($path)) nano_cart_media_fail('Invalid file.');
    $to = trim((string)($_POST['to'] ?? ''), '/');
    if (!nano_cart_media_dir_ok($to)) nano_cart_media_fail('Invalid destination folder.');
    $slash  = strrpos($path, '/');
    $base   = $slash === false ? $path : substr($path, $slash + 1);
    $parent = $slash === false ? '' : substr($path, 0, $slash);
    if ($to === $parent) nano_cart_media_ok(['unchanged' => true]);

    $new_rel = ($to !== '' ? $to . '/' : '') . $base;
    [$o1, $r1] = nano_cart_media_owner_ref($path);
    [$o2, $r2] = nano_cart_media_owner_ref($new_rel);
    // Moving an in-use image to a different product/category would strip it
    // from one without attaching it to the other (silent orphan). Refuse it.
    if ($o1 !== $o2 && $o1 !== null) {
        $used = nano_cart_media_usage($o1, $r1);
        if (!empty($used)) {
            nano_cart_media_fail('This image is in use by ' . implode(', ', $used)
                . '. Remove it there first, or move it within the same area.');
        }
    }

    $src = nano_cart_media_fs($path . '.jpg');
    if (!is_file($src) || !nano_cart_media_contained($src)) nano_cart_media_fail('File not found.', 404);
    $dest_abs = nano_cart_media_fs($to);
    if (!is_dir($dest_abs) && !@mkdir($dest_abs, 0755, true) && !is_dir($dest_abs)) {
        nano_cart_media_fail('Destination folder is missing.', 404);
    }
    if (is_file(nano_cart_media_fs($new_rel . '.jpg'))) nano_cart_media_fail('A file named "' . $base . '" already exists there.');
    if (!@rename($src, nano_cart_media_fs($new_rel . '.jpg'))) nano_cart_media_fail('Could not move the file.', 500);
    nano_cart_media_purge_cache($path);
    $updated = ($o1 === $o2) ? nano_cart_media_rewrite_refs($o1, $r1, $r2) : nano_cart_media_rewrite_refs($o1, $r1, null);
    nano_cart_media_ok(['refs_updated' => $updated]);
}

function nano_cart_media_action_delete(): void
{
    $path = trim((string)($_POST['path'] ?? ''), '/');
    if (!nano_cart_media_file_ok($path)) nano_cart_media_fail('Invalid file.');
    $src = nano_cart_media_fs($path . '.jpg');
    if (!is_file($src) || !nano_cart_media_contained($src)) nano_cart_media_fail('File not found.', 404);
    if (!@unlink($src)) nano_cart_media_fail('Could not delete the file.', 500);
    nano_cart_media_purge_cache($path);
    [$owner, $ref] = nano_cart_media_owner_ref($path);
    $updated = nano_cart_media_rewrite_refs($owner, $ref, null);
    nano_cart_media_ok(['deleted' => $path, 'refs_updated' => $updated]);
}

/* ----------------------------------------------------------------------- */
/* Page (GET) - CSS + JS inlined so nothing can be served stale.            */
/* ----------------------------------------------------------------------- */

$admin_url = nano_cart_shop_path() . '/admin';
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

echo nano_cart_admin_header('Media', 'media');
echo nano_cart_admin_flash_html();
?>
<style>
.ncm{border:1px solid #e5e5e5;border-radius:6px;background:#fff;margin-top:1rem;padding:1rem;display:flex;flex-direction:column;gap:.85rem}
.ncm-where{display:flex;align-items:center;gap:.5rem;font-size:1.1rem;background:#f6f7f9;border:1px solid #e5e5e5;border-radius:6px;padding:.55rem .75rem;flex-wrap:wrap}
.ncm-where b{color:#888;font-weight:600;font-size:.85rem;text-transform:uppercase;letter-spacing:.04em}
.ncm-crumb{display:flex;align-items:center;flex-wrap:wrap;gap:.15rem}
.ncm-cl{background:none;border:0;color:#0066cc;cursor:pointer;padding:.1rem .35rem;border-radius:4px;font:inherit;font-size:1.05rem}
.ncm-cl:hover{background:#e6eefb;text-decoration:underline}
.ncm-crumb> :last-child{color:#1a1a1a;font-weight:700}
.ncm-sep{color:#aaa}
.ncm-toolbar{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
.ncm-drop{border:2px dashed #cfd6df;border-radius:6px;padding:.85rem;text-align:center;color:#555;background:#fafbfc}
.ncm-drop p{margin:.15rem 0}.ncm-drop small{color:#888}
.ncm-drop-on{border-color:#0066cc;background:#eaf3ff}
.ncm-status{min-height:1.1rem;font-size:.9rem;color:#2c7a2c}
.ncm-status-err{color:#b00020}
.ncm-h{font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;color:#999;margin:.25rem 0 0}
.ncm-folders{display:flex;flex-wrap:wrap;gap:.5rem}
.ncm-folder{display:flex;align-items:center;border:1px solid #d9dde3;border-radius:6px;background:#fff;overflow:hidden}
.ncm-fopen{display:flex;align-items:center;gap:.45rem;padding:.55rem .75rem;background:none;border:0;cursor:pointer;font:inherit;color:#1a1a1a}
.ncm-fopen:hover{background:#f0f3f7}
.ncm-fico{color:#c79a4a;display:inline-flex;align-items:center}
.ncm-fdel{border:0;border-left:1px solid #e5e5e5;background:#fafafa;color:#b00020;cursor:pointer;padding:0 .65rem;font-size:1.1rem;align-self:stretch}
.ncm-fdel:hover{background:#fbeaea}
.ncm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.6rem}
.ncm-empty{color:#888;font-style:italic}
.ncm-file{border:1px solid #e5e5e5;border-radius:6px;overflow:hidden;background:#fff;position:relative}
.ncm-file[draggable=true]{cursor:grab}
.ncm-drag{opacity:.45}
.ncm-thumb{aspect-ratio:4/3;background:#eef0f3 repeating-linear-gradient(45deg,#eef0f3 0 8px,#e7e9ec 8px 16px);display:flex;align-items:center;justify-content:center}
.ncm-thumb img{width:100%;height:100%;object-fit:contain;display:block}
.ncm-broken img{display:none}.ncm-broken::after{content:'source missing';color:#b00020;font-size:.78rem}
.ncm-meta{display:flex;align-items:center;justify-content:space-between;gap:.3rem;padding:.35rem .4rem .15rem}
.ncm-fn{font-size:.8rem;word-break:break-all}
.ncm-badge{flex:none;font-size:.65rem;padding:.05rem .35rem;border-radius:10px;cursor:help}
.ncm-used{background:#e3f0e3;color:#2c7a2c}.ncm-unused{background:#f1f1f1;color:#999}
.ncm-fa{display:flex;justify-content:space-between;padding:.15rem .4rem .45rem}
.ncm-fa button{background:none;border:0;color:#0066cc;cursor:pointer;font-size:.78rem;padding:0}
.ncm-fa button:hover{text-decoration:underline}
.ncm-target{outline:2px dashed #0066cc;outline-offset:1px;background:#eaf3ff}
.ncm-toasts{position:fixed;bottom:1rem;right:1rem;display:flex;flex-direction:column;gap:.5rem;z-index:1100;max-width:24rem}
.ncm-toast{background:#1f2430;color:#fff;padding:.65rem .9rem;border-radius:6px;font-size:.9rem;box-shadow:0 6px 18px rgba(0,0,0,.25);opacity:0;transform:translateY(10px);transition:opacity .2s,transform .2s}
.ncm-toast-show{opacity:1;transform:none}
.ncm-toast-success{background:#1f7a1f}
.ncm-toast-error{background:#b00020}
.ncm-mbg{position:fixed;inset:0;background:rgba(20,24,31,.5);display:flex;align-items:center;justify-content:center;z-index:1200;padding:1.5rem}
.ncm-modal{background:#fff;border-radius:8px;max-width:26rem;width:100%;padding:1.25rem;box-shadow:0 12px 40px rgba(0,0,0,.3)}
.ncm-modal p{margin:0 0 1rem;white-space:pre-line}
.ncm-modal input{width:100%;padding:.55rem .65rem;border:1px solid #cfd6df;border-radius:5px;margin-bottom:1rem;font:inherit;box-sizing:border-box}
.ncm-mbtns{display:flex;justify-content:flex-end;gap:.5rem}
</style>

<div id="ncm-root" data-endpoint="<?= $h($admin_url . '/media.php') ?>" data-csrf="<?= $h(nano_cart_admin_csrf_token()) ?>"></div>

<script>
(function () {
  'use strict';
  var root = document.getElementById('ncm-root');
  if (!root) return;
  var ENDPOINT = root.dataset.endpoint, CSRF = root.dataset.csrf;
  var dir = '', dragPath = null, data = null;
  var FOLDER = '<svg class="ncm-fico" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>';

  function el(t, c, h) { var e = document.createElement(t); if (c) e.className = c; if (h != null) e.innerHTML = h; return e; }
  function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

  /* ---- custom toast + dialogs (no browser alert/confirm/prompt) ---- */
  function toast(msg, type) {
    var box = document.querySelector('.ncm-toasts');
    if (!box) { box = el('div', 'ncm-toasts'); document.body.appendChild(box); }
    var t = el('div', 'ncm-toast' + (type ? ' ncm-toast-' + type : ''), esc(msg));
    box.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('ncm-toast-show'); });
    setTimeout(function () {
      t.classList.remove('ncm-toast-show');
      setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 250);
    }, type === 'error' ? 6000 : 3200);
  }
  function dialog(opts) {
    return new Promise(function (resolve) {
      var bg = el('div', 'ncm-mbg'), m = el('div', 'ncm-modal');
      m.appendChild(el('p', null, esc(opts.message)));
      var input = null;
      if (opts.prompt) { input = document.createElement('input'); input.type = 'text'; input.value = opts.value || ''; m.appendChild(input); }
      var btns = el('div', 'ncm-mbtns');
      var cancel = el('button', 'nano-cart-admin-button nano-cart-admin-button-secondary', 'Cancel');
      var ok = el('button', 'nano-cart-admin-button' + (opts.danger ? ' nano-cart-admin-button-danger' : ''), opts.ok || 'OK');
      btns.appendChild(cancel); btns.appendChild(ok); m.appendChild(btns); bg.appendChild(m); document.body.appendChild(bg);
      (input || ok).focus();
      function close(v) { if (bg.parentNode) bg.parentNode.removeChild(bg); resolve(v); }
      cancel.addEventListener('click', function () { close(opts.prompt ? null : false); });
      ok.addEventListener('click', function () { close(opts.prompt ? input.value : true); });
      bg.addEventListener('click', function (e) { if (e.target === bg) close(opts.prompt ? null : false); });
      bg.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); ok.click(); } if (e.key === 'Escape') { cancel.click(); } });
    });
  }
  function confirmDlg(message, opts) { opts = opts || {}; return dialog({ message: message, ok: opts.ok || 'OK', danger: opts.danger }); }
  function promptDlg(message, value) { return dialog({ message: message, prompt: true, value: value || '', ok: 'OK' }); }
  function flash(d, msg) { var x = d && d.refs_updated ? (' ' + d.refs_updated + ' reference' + (d.refs_updated === 1 ? '' : 's') + ' updated.') : ''; toast(msg + x, 'success'); }

  function api(action, params, fd) {
    fd = fd || new FormData();
    fd.append('csrf_token', CSRF); fd.append('action', action);
    Object.keys(params || {}).forEach(function (k) { fd.append(k, params[k]); });
    return fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      return r.text().then(function (t) {
        try { return JSON.parse(t); }
        catch (e) {
          if (/<form|password|login/i.test(t)) return { ok: false, error: 'Session expired. Reload and log in again.' };
          return { ok: false, error: 'Server error (HTTP ' + r.status + '). Check the server error log.' };
        }
      });
    }).catch(function (e) { return { ok: false, error: 'Network error: ' + e.message }; });
  }

  root.innerHTML =
      '<div class="ncm">'
    + '<div class="ncm-where"><b>You are here</b><nav class="ncm-crumb"></nav></div>'
    + '<div class="ncm-toolbar">'
    +   '<button type="button" class="nano-cart-admin-button nano-cart-admin-button-secondary ncm-up">Up one level</button>'
    +   '<button type="button" class="nano-cart-admin-button ncm-new">New folder</button>'
    + '</div>'
    + '<div class="ncm-drop"><p>Drop images here or <button type="button" class="ncm-browse nano-cart-admin-link">browse</button></p>'
    +   '<p><small>JPEG, PNG or WebP. Saved as a single source image; sizes are generated on demand.</small></p>'
    +   '<input type="file" accept="image/jpeg,image/png,image/webp" multiple hidden></div>'
    + '<div class="ncm-status" aria-live="polite"></div>'
    + '<div><p class="ncm-h">Folders</p><div class="ncm-folders"></div></div>'
    + '<div><p class="ncm-h">Images</p><div class="ncm-grid"></div></div>'
    + '</div>';

  var elCrumb = root.querySelector('.ncm-crumb'), elUp = root.querySelector('.ncm-up'),
      elNew = root.querySelector('.ncm-new'), elDrop = root.querySelector('.ncm-drop'),
      elStatus = root.querySelector('.ncm-status'), elFolders = root.querySelector('.ncm-folders'),
      elGrid = root.querySelector('.ncm-grid'), elFile = root.querySelector('input[type=file]');

  elUp.addEventListener('click', function () { if (data && data.parent !== null) load(data.parent); });
  elUp.addEventListener('dragover', function (e) { if (dragPath && data && data.parent !== null) { e.preventDefault(); elUp.classList.add('ncm-target'); } });
  elUp.addEventListener('dragleave', function () { elUp.classList.remove('ncm-target'); });
  elUp.addEventListener('drop', function (e) { e.preventDefault(); elUp.classList.remove('ncm-target'); if (dragPath && data && data.parent !== null) move(dragPath, data.parent); });
  elNew.addEventListener('click', newFolder);
  elDrop.querySelector('.ncm-browse').addEventListener('click', function () { elFile.click(); });
  elFile.addEventListener('change', function () { if (elFile.files.length) upload(elFile.files); elFile.value = ''; });
  elDrop.addEventListener('dragover', function (e) { e.preventDefault(); elDrop.classList.add('ncm-drop-on'); });
  elDrop.addEventListener('dragleave', function () { elDrop.classList.remove('ncm-drop-on'); });
  elDrop.addEventListener('drop', function (e) { e.preventDefault(); elDrop.classList.remove('ncm-drop-on'); if (e.dataTransfer.files.length) upload(e.dataTransfer.files); });

  function status(m, err) { elStatus.textContent = m || ''; elStatus.classList.toggle('ncm-status-err', !!err); }

  function load(d) {
    status('Loading...');
    api('list', { dir: d }).then(function (res) {
      if (!res.ok) { status(''); toast(res.error, 'error'); return; }
      dir = res.dir; data = res; render(res); status(res.note || '');
    });
  }

  function render(d) {
    elCrumb.innerHTML = '';
    function crumb(label, path) {
      var b = el('button', 'ncm-cl', esc(label));
      b.title = 'Open ' + (path === '' ? 'media' : path) + ' (or drop an image here to move it)';
      b.addEventListener('click', function () { load(path); });
      b.addEventListener('dragover', function (e) { if (dragPath) { e.preventDefault(); b.classList.add('ncm-target'); } });
      b.addEventListener('dragleave', function () { b.classList.remove('ncm-target'); });
      b.addEventListener('drop', function (e) { e.preventDefault(); b.classList.remove('ncm-target'); if (dragPath) move(dragPath, path); });
      return b;
    }
    elCrumb.appendChild(crumb('media', ''));
    (d.crumbs || []).forEach(function (c) {
      elCrumb.appendChild(el('span', 'ncm-sep', ' / '));
      elCrumb.appendChild(crumb(c.name, c.path));
    });
    elUp.disabled = (d.parent === null);
    elNew.style.display = d.can_mkdir ? '' : 'none';

    elFolders.innerHTML = '';
    if (!d.folders.length) elFolders.appendChild(el('span', 'ncm-empty', 'No folders here.' + (d.can_mkdir ? ' Use "New folder" to add one.' : '')));
    d.folders.forEach(function (f) {
      var wrap = el('div', 'ncm-folder');
      var open = el('button', 'ncm-fopen', FOLDER + '<span>' + esc(f.name) + '</span>');
      open.addEventListener('click', function () { load(f.path); });
      open.addEventListener('dragover', function (e) { if (dragPath) { e.preventDefault(); wrap.classList.add('ncm-target'); } });
      open.addEventListener('dragleave', function () { wrap.classList.remove('ncm-target'); });
      open.addEventListener('drop', function (e) { e.preventDefault(); wrap.classList.remove('ncm-target'); if (dragPath) move(dragPath, f.path); });
      wrap.appendChild(open);
      if (f.deletable) {
        var del = el('button', 'ncm-fdel', '&times;'); del.title = 'Delete this folder and everything in it';
        del.addEventListener('click', function () { deleteFolder(f); });
        wrap.appendChild(del);
      }
      elFolders.appendChild(wrap);
    });

    elGrid.innerHTML = '';
    if (!d.files.length) { elGrid.appendChild(el('p', 'ncm-empty', 'No images in this folder. Drop some above.')); return; }
    d.files.forEach(function (f) { elGrid.appendChild(fileCard(f)); });
  }

  function fileCard(f) {
    var card = el('div', 'ncm-file'); card.draggable = true;
    var badge = (f.used_by && f.used_by.length)
      ? '<span class="ncm-badge ncm-used" title="' + esc(f.used_by.join('\n')) + '">in use</span>'
      : '<span class="ncm-badge ncm-unused" title="Not referenced yet">unused</span>';
    card.innerHTML = '<div class="ncm-thumb"><img alt="" loading="lazy" src="' + esc(f.thumb) + '"></div>'
      + '<div class="ncm-meta"><span class="ncm-fn">' + esc(f.name) + '</span>' + badge + '</div>'
      + '<div class="ncm-fa"><button type="button" class="ncm-ren">Rename</button><button type="button" class="ncm-del">Delete</button></div>';
    card.querySelector('img').addEventListener('error', function () { card.querySelector('.ncm-thumb').classList.add('ncm-broken'); });
    card.querySelector('.ncm-ren').addEventListener('click', function () { rename(f); });
    card.querySelector('.ncm-del').addEventListener('click', function () { del(f); });
    card.addEventListener('dragstart', function (e) { dragPath = f.path; card.classList.add('ncm-drag'); try { e.dataTransfer.setData('text/plain', f.path); } catch (x) {} e.dataTransfer.effectAllowed = 'move'; });
    card.addEventListener('dragend', function () { dragPath = null; card.classList.remove('ncm-drag'); });
    return card;
  }

  function upload(files) {
    var fd = new FormData();
    Array.prototype.slice.call(files).forEach(function (f) { fd.append('files[]', f); });
    status('Uploading...');
    api('upload', { dir: dir }, fd).then(function (d) {
      status('');
      if (!d.ok) { toast(d.error, 'error'); return; }
      var bad = (d.files || []).filter(function (f) { return !f.ok; });
      if (bad.length) toast(bad.length + ' rejected: ' + bad.map(function (f) { return f.error; }).join('; '), 'error');
      else toast('Uploaded.', 'success');
      load(dir);
    });
  }
  function newFolder() {
    promptDlg('New folder name (lowercase letters, numbers, hyphens):').then(function (n) {
      if (n === null) return;
      n = (n || '').trim().toLowerCase(); if (!n) return;
      api('mkdir', { dir: dir, name: n }).then(function (d) { if (!d.ok) { toast(d.error, 'error'); return; } toast('Folder created.', 'success'); load(dir); });
    });
  }
  function deleteFolder(f) {
    confirmDlg('Delete the folder "' + f.name + '" and everything inside it? Any product or category using those images will have the reference removed.', { danger: true, ok: 'Delete folder' })
      .then(function (yes) {
        if (!yes) return;
        api('deletefolder', { path: f.path }).then(function (d) { if (!d.ok) { toast(d.error, 'error'); return; } flash(d, 'Folder deleted.'); load(dir); });
      });
  }
  function rename(f) {
    promptDlg('Rename "' + f.name + '" to:', f.name).then(function (n) {
      if (n === null) return;
      n = (n || '').trim().toLowerCase(); if (!n || n === f.name) return;
      api('rename', { path: f.path, newname: n }).then(function (d) { if (!d.ok) { toast(d.error, 'error'); return; } flash(d, 'Renamed.'); load(dir); });
    });
  }
  function del(f) {
    var m = 'Delete "' + f.name + '"? The source and its cached sizes are removed.';
    if (f.used_by && f.used_by.length) m += '\n\nIn use by:\n' + f.used_by.join('\n') + '\n\nThat reference is removed too.';
    confirmDlg(m, { danger: true, ok: 'Delete' }).then(function (yes) {
      if (!yes) return;
      api('delete', { path: f.path }).then(function (d) { if (!d.ok) { toast(d.error, 'error'); return; } flash(d, 'Deleted.'); load(dir); });
    });
  }
  function move(path, to) { api('move', { path: path, to: to }).then(function (d) { if (!d.ok) { toast(d.error, 'error'); return; } flash(d, 'Moved.'); load(dir); }); }

  load('');
})();
</script>
<?php
echo nano_cart_admin_footer();
