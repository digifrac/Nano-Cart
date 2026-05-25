<?php
/**
 * Nano Cart - seed image generator.
 *
 * Generates one placeholder source image per entry for the minimal
 * seed-data/ directory. Run once from the command line:
 *
 *   php seed-data/generate-seed-images.php
 *
 * Sized variants are no longer pre-generated: image.php builds them on
 * demand at request time. This script only writes the source JPEGs.
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

// Source dimensions. The resizer caps to config.source_max_width (1600 by
// default) anyway, so 1600x1200 is a representative source size.
[$w, $h] = [1600, 1200];

$written = 0;
foreach ($jobs as $job) {
    $dir = $seed_root . '/' . dirname($job['path']);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        fwrite(STDERR, "Could not create $dir\n");
        exit(1);
    }
    $img = imagecreatetruecolor($w, $h);
    $bg  = imagecolorallocate($img, $job['color'][0], $job['color'][1], $job['color'][2]);
    $fg  = imagecolorallocate($img, 255, 255, 255);
    imagefilledrectangle($img, 0, 0, $w, $h, $bg);
    // Draw label centered (built-in font 5 is the widest available).
    $lines = explode("\n", $job['label']);
    $font  = 5;
    $line_h = imagefontheight($font);
    $total_h = $line_h * count($lines);
    $y = (int)(($h - $total_h) / 2);
    foreach ($lines as $line) {
        $tw = imagefontwidth($font) * strlen($line);
        $x = (int)(($w - $tw) / 2);
        imagestring($img, $font, $x, $y, $line, $fg);
        $y += $line_h;
    }
    imagejpeg($img, $seed_root . '/' . $job['path'] . '.jpg', 85);
    imagedestroy($img);
    $written++;
    echo "  generated " . $job['path'] . ".jpg\n";
}

echo "\nDone. $written source files written.\n";
