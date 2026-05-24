<?php
/**
 * Nano Cart - sitemap generator.
 *
 * Writes sitemap.xml at the shop root. The admin calls
 * nano_cart_generate_sitemap() on every save. The frontend never writes;
 * this file is loaded by admin code only.
 */

if (!defined('NANO_CART_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

function nano_cart_generate_sitemap(?string $output_path = null): bool
{
    $cfg = nano_cart_load_config();
    $base = nano_cart_site_url() . nano_cart_shop_path();
    $categories = nano_cart_load_categories();
    $products = nano_cart_load_products();

    $latest_overall = '';
    $latest_by_cat  = [];
    foreach ($products as $p) {
        $upd = (string)($p['updated'] ?? '');
        if ($upd > $latest_overall) $latest_overall = $upd;
        $c = (string)($p['category'] ?? '');
        if ($c !== '' && $upd > ($latest_by_cat[$c] ?? '')) {
            $latest_by_cat[$c] = $upd;
        }
    }

    $iso_to_date = static function (string $iso): string {
        if ($iso === '') return date('Y-m-d');
        return substr($iso, 0, 10);
    };

    $home_lastmod = $iso_to_date($latest_overall);

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    $xml .= "  <url>\n";
    $xml .= '    <loc>' . htmlspecialchars($base . '/') . '</loc>' . "\n";
    $xml .= '    <lastmod>' . $home_lastmod . '</lastmod>' . "\n";
    $xml .= "    <changefreq>weekly</changefreq>\n";
    $xml .= "    <priority>1.0</priority>\n";
    $xml .= "  </url>\n";

    foreach ($categories as $c) {
        $slug = (string)($c['slug'] ?? '');
        if ($slug === '') continue;
        $lastmod = $iso_to_date($latest_by_cat[$slug] ?? '');
        $xml .= "  <url>\n";
        $xml .= '    <loc>' . htmlspecialchars($base . '/' . $slug . '/') . '</loc>' . "\n";
        $xml .= '    <lastmod>' . $lastmod . '</lastmod>' . "\n";
        $xml .= "    <changefreq>weekly</changefreq>\n";
        $xml .= "    <priority>0.8</priority>\n";
        $xml .= "  </url>\n";
    }

    foreach ($products as $p) {
        $cat = (string)($p['category'] ?? '');
        $sku = (string)($p['sku'] ?? '');
        if ($cat === '' || $sku === '') continue;
        if (($p['status'] ?? 'published') !== 'published') continue;
        $lastmod = $iso_to_date((string)($p['updated'] ?? ''));
        $xml .= "  <url>\n";
        $xml .= '    <loc>' . htmlspecialchars($base . '/' . $cat . '/' . $sku . '/') . '</loc>' . "\n";
        $xml .= '    <lastmod>' . $lastmod . '</lastmod>' . "\n";
        $xml .= "    <changefreq>monthly</changefreq>\n";
        $xml .= "    <priority>0.6</priority>\n";
        $xml .= "  </url>\n";
    }

    $xml .= '</urlset>' . "\n";

    $path = $output_path ?? (__DIR__ . '/sitemap.xml');
    return (bool)@file_put_contents($path, $xml, LOCK_EX);
}
