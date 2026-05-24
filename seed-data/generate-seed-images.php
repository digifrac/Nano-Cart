<?php
/**
 * Nano Cart - seed image generator.
 *
 * Generates ~35 placeholder image files for the minimal seed-data/
 * directory. Run once from the command line:
 *
 *   php seed-data/generate-seed-images.php
 *
 * Idempotent: re-running overwrites existing files. Safe to delete this
 * script and the generated media/ folder before deploying a real shop.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the command line, not the web.\n");
    exit(1);
}
if (!extension_loaded('gd')) {
    fwrite(STDERR, "PHP GD extension required.\n");
    exit(1);
}
$can_webp = function_exists('imagewebp');
if (!$can_webp) {
    fwrite(STDERR, "Note: WebP support not available in this PHP build. JPEG variants only.\n");
}

$seed_root = __DIR__;
$jobs = [
    [
        'path'  => 'media/category-images/pottery',
        'label' => 'POTTERY',
        'color' => [193, 154, 107],
    ],
    [
        'path'  => 'media/product-images/sku-001/main',
        'label' => "SKU-001\nMAIN",
        'color' => [91, 141, 239],
    ],
    [
        'path'  => 'media/product-images/sku-001/alt-1',
        'label' => "SKU-001\nALT-1",
        'color' => [70, 115, 196],
    ],
    [
        'path'  => 'media/product-images/sku-002/main',
        'label' => "SKU-002\nMAIN",
        'color' => [224, 122, 63],
    ],
    [
        'path'  => 'media/product-images/sku-002/alt-1',
        'label' => "SKU-002\nALT-1",
        'color' => [184, 90, 38],
    ],
];

$sizes = [
    'original' => [1600, 1200],
    'hero-800' => [800,  600],
    'thumb-400'=> [400,  300],
    'thumb-120'=> [120,  90],
];

$written = 0;
foreach ($jobs as $job) {
    $dir = $seed_root . '/' . dirname($job['path']);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        fwrite(STDERR, "Could not create $dir\n");
        exit(1);
    }
    foreach ($sizes as $variant => [$w, $h]) {
        $img = imagecreatetruecolor($w, $h);
        $bg  = imagecolorallocate($img, $job['color'][0], $job['color'][1], $job['color'][2]);
        $fg  = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);
        // Draw label centered (built-in font 5 is widest available).
        $lines = explode("\n", $job['label']);
        $font  = $w >= 400 ? 5 : ($w >= 200 ? 4 : 3);
        $line_h = imagefontheight($font);
        $total_h = $line_h * count($lines);
        $y = (int)(($h - $total_h) / 2);
        foreach ($lines as $line) {
            $tw = imagefontwidth($font) * strlen($line);
            $x = (int)(($w - $tw) / 2);
            imagestring($img, $font, $x, $y, $line, $fg);
            $y += $line_h;
        }

        $suffix = ($variant === 'original') ? '' : '-' . $variant;
        $base = $seed_root . '/' . $job['path'] . $suffix;
        imagejpeg($img, $base . '.jpg', 85);
        $written++;
        // Original is kept as JPEG only per FORMAT.md; WebP is for sized variants only.
        if ($can_webp && $variant !== 'original') {
            imagewebp($img, $base . '.webp', 85);
            $written++;
        }
        imagedestroy($img);
    }
    echo "  generated " . $job['path'] . " (all variants)\n";
}

echo "\nDone. $written files written.\n";
