<?php
/**
 * Nano Cart - basic image upload.
 *
 * Single-file upload handler. Validates mime + magic bytes, applies
 * EXIF orientation, downscales to max width, re-encodes via GD (which
 * neutralises any embedded payload), saves to either
 * /media/product-images/<sku>/ or /media/category-images/, and writes
 * one 400px-wide thumbnail. Returns JSON.
 *
 * The full multi-size pipeline (hero-800, thumb-120, WebP companions,
 * EXIF stripping pass, alt-text editor) is built in Cart Session 4.
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/auth.php';
nano_cart_admin_auth_check();

header('Content-Type: application/json');

function nano_cart_upload_fail(string $msg, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nano_cart_upload_fail('POST required.', 405);
}
if (!nano_cart_admin_csrf_verify()) {
    nano_cart_upload_fail('CSRF token invalid.', 403);
}

$target_type = (string)($_POST['target_type'] ?? '');
$target_sku  = strtolower(trim((string)($_POST['target_sku'] ?? '')));
if ($target_type !== 'product' && $target_type !== 'category') {
    nano_cart_upload_fail('target_type must be "product" or "category".');
}
if ($target_type === 'product' && !nano_cart_slug_ok($target_sku)) {
    nano_cart_upload_fail('Invalid target_sku.');
}

if (empty($_FILES['image']) || (int)$_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    nano_cart_upload_fail('No file uploaded or upload error.');
}
$tmp = (string)$_FILES['image']['tmp_name'];
if (!is_uploaded_file($tmp)) {
    nano_cart_upload_fail('Uploaded file missing.');
}
if ((int)$_FILES['image']['size'] > NANO_CART_ADMIN_MAX_UPLOAD_BYTES) {
    nano_cart_upload_fail('File exceeds the 10 MB upload limit.');
}

$orig_name = (string)$_FILES['image']['name'];
$ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
if ($ext === 'jpeg') $ext = 'jpg';
$allowed = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
if (!isset($allowed[$ext])) {
    nano_cart_upload_fail('Allowed types: jpg, png, webp.');
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
if ($mime !== $allowed[$ext] && !($ext === 'jpg' && $mime === 'image/jpeg')) {
    nano_cart_upload_fail('File contents do not match the .' . $ext . ' extension.');
}

if (!extension_loaded('gd')) {
    nano_cart_upload_fail('PHP GD extension required on the server.', 500);
}

// Decode through GD - re-encoding strips any embedded payload.
$img = match ($ext) {
    'jpg'  => @imagecreatefromjpeg($tmp),
    'png'  => @imagecreatefrompng($tmp),
    'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
    default=> false,
};
if (!$img) nano_cart_upload_fail('Could not decode image.');

// Apply EXIF orientation for JPEG (phone photos otherwise display sideways).
if ($ext === 'jpg' && function_exists('exif_read_data')) {
    $exif = @exif_read_data($tmp);
    $o = (int)($exif['Orientation'] ?? 0);
    if ($o > 1) {
        $rotated = match ($o) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
        if ($rotated && $rotated !== $img) {
            imagedestroy($img);
            $img = $rotated;
        }
        if (in_array($o, [2, 5, 7, 4], true)) {
            $flip = ($o === 2 || $o === 5) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL;
            imageflip($img, $flip);
        }
    }
}

// Downscale wider sources to 1600px (per Nano CMS 1.3.1 pipeline fix).
$cfg = nano_cart_load_config();
$cap = max(400, min(4000, (int)($cfg['source_max_width'] ?? 1600)));
$sw = imagesx($img);
$sh = imagesy($img);
if ($sw > $cap) {
    $new_h = (int)round($sh * $cap / $sw);
    $resized = imagecreatetruecolor($cap, $new_h);
    imagecopyresampled($resized, $img, 0, 0, 0, 0, $cap, $new_h, $sw, $sh);
    imagedestroy($img);
    $img = $resized;
}

// Determine destination paths.
$basename = nano_cart_admin_safe_basename($orig_name);
if ($target_type === 'product') {
    $dest_dir = NANO_CART_MEDIA_PATH . '/product-images/' . $target_sku;
    $rel_path = 'product-images/' . $target_sku . '/' . $basename;
} else {
    $dest_dir = NANO_CART_MEDIA_PATH . '/category-images';
    $rel_path = 'category-images/' . $basename;
}
if (!is_dir($dest_dir) && !@mkdir($dest_dir, 0755, true) && !is_dir($dest_dir)) {
    nano_cart_upload_fail('Could not create destination directory.', 500);
}

$dest = $dest_dir . '/' . $basename . '.jpg';
$thumb = $dest_dir . '/' . $basename . '-thumb-400.jpg';

$q_jpeg = max(60, min(95, (int)($cfg['image_quality_jpeg'] ?? 85)));
if (!@imagejpeg($img, $dest, $q_jpeg)) {
    imagedestroy($img);
    nano_cart_upload_fail('Could not write image.', 500);
}

// Generate one 400px-wide thumbnail. Full multi-size pipeline is Session 4.
$tw = 400;
$th = (int)round(imagesy($img) * $tw / imagesx($img));
$thumb_img = imagecreatetruecolor($tw, $th);
imagecopyresampled($thumb_img, $img, 0, 0, 0, 0, $tw, $th, imagesx($img), imagesy($img));
@imagejpeg($thumb_img, $thumb, $q_jpeg);
imagedestroy($thumb_img);
imagedestroy($img);

echo json_encode([
    'ok'        => true,
    'file'      => $basename,
    'rel_path'  => $rel_path,
    'thumb_url' => nano_cart_shop_path() . '/media/' . $rel_path . '-thumb-400.jpg',
    'image_url' => nano_cart_shop_path() . '/media/' . $rel_path . '.jpg',
    'note'      => 'Single-file upload. Full multi-size pipeline (hero-800, thumb-120, WebP companions) is built in Cart Session 4.',
]);

/**
 * Lowercase, alphanumeric + hyphen filename, stripped of extension.
 * Falls back to a date+random base if nothing safe survives.
 */
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
