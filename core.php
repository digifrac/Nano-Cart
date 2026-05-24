<?php
/**
 * Nano Cart - frontend core.
 *
 * Library file. Loaded by index.php / category.php / product.php /
 * generators.php after bootstrap.php has defined NANO_CART_BOOTSTRAPPED
 * and the path constants.
 *
 * Independent of admin/ by design; the two layers share only the
 * on-disk format described in FORMAT.md, not PHP code.
 */

if (!defined('NANO_CART_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/lib/Parsedown.php';
require_once __DIR__ . '/licence.php';

/* ----------------------------------------------------------------------- */
/* Configuration                                                            */
/* ----------------------------------------------------------------------- */

function nano_cart_load_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    if (!defined('NANO_CART_CONFIG_PATH') || !is_file(NANO_CART_CONFIG_PATH)) {
        throw new RuntimeException('Nano Cart: config.json not found at NANO_CART_CONFIG_PATH');
    }
    $raw = file_get_contents(NANO_CART_CONFIG_PATH);
    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        throw new RuntimeException('Nano Cart: config.json is not valid JSON');
    }
    $cfg = $parsed;
    return $cfg;
}

function nano_cart_site_url(): string
{
    $cfg = nano_cart_load_config();
    return rtrim((string)($cfg['site_url'] ?? ''), '/');
}

function nano_cart_shop_path(): string
{
    $cfg = nano_cart_load_config();
    $path = (string)($cfg['shop_path'] ?? '/shop');
    if ($path !== '' && $path[0] !== '/') $path = '/' . $path;
    return rtrim($path, '/');
}

function nano_cart_site_name(): string
{
    $cfg = nano_cart_load_config();
    return (string)($cfg['site_name'] ?? '');
}

/* ----------------------------------------------------------------------- */
/* Slug + path safety                                                       */
/* ----------------------------------------------------------------------- */

function nano_cart_slug_ok(string $slug): bool
{
    return (bool)preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $slug);
}

/* ----------------------------------------------------------------------- */
/* Loaders                                                                   */
/* ----------------------------------------------------------------------- */

function nano_cart_load_product(string $slug): ?array
{
    if (!nano_cart_slug_ok($slug)) return null;
    $path = NANO_CART_PRODUCTS_PATH . '/' . $slug . '.json';
    if (!is_file($path)) return null;
    $parsed = json_decode(file_get_contents($path), true);
    return is_array($parsed) ? $parsed : null;
}

/**
 * Load all products matching $filters. Filter keys:
 *   category       (string slug)
 *   featured       (bool)
 *   hero_featured  (bool)
 *   status         (string; default excludes drafts)
 *
 * Returns array sorted by SKU alphabetically for deterministic output.
 */
function nano_cart_load_products(array $filters = []): array
{
    $out = [];
    foreach (glob(NANO_CART_PRODUCTS_PATH . '/*.json') ?: [] as $path) {
        $p = json_decode(file_get_contents($path), true);
        if (!is_array($p)) continue;
        $status = $p['status'] ?? 'published';
        if (!array_key_exists('status', $filters) && $status === 'draft') continue;
        if (array_key_exists('status', $filters) && $status !== $filters['status']) continue;
        if (array_key_exists('category', $filters) && ($p['category'] ?? null) !== $filters['category']) continue;
        if (array_key_exists('featured', $filters) && (bool)($p['featured'] ?? false) !== (bool)$filters['featured']) continue;
        if (array_key_exists('hero_featured', $filters) && (bool)($p['hero_featured'] ?? false) !== (bool)$filters['hero_featured']) continue;
        $out[] = $p;
    }
    usort($out, static fn($a, $b) => strcmp((string)($a['sku'] ?? ''), (string)($b['sku'] ?? '')));
    return $out;
}

function nano_cart_load_category(string $slug): ?array
{
    if (!nano_cart_slug_ok($slug)) return null;
    $path = NANO_CART_CATEGORIES_PATH . '/' . $slug . '.json';
    if (!is_file($path)) return null;
    $parsed = json_decode(file_get_contents($path), true);
    return is_array($parsed) ? $parsed : null;
}

/**
 * All categories, sorted by sort_order (lower first), then alphabetically
 * by name. Unset sort_order sinks to the bottom alphabetically among unset.
 */
function nano_cart_load_categories(): array
{
    $out = [];
    foreach (glob(NANO_CART_CATEGORIES_PATH . '/*.json') ?: [] as $path) {
        $c = json_decode(file_get_contents($path), true);
        if (is_array($c)) $out[] = $c;
    }
    usort($out, static function ($a, $b) {
        $oa = array_key_exists('sort_order', $a) ? (int)$a['sort_order'] : PHP_INT_MAX;
        $ob = array_key_exists('sort_order', $b) ? (int)$b['sort_order'] : PHP_INT_MAX;
        if ($oa !== $ob) return $oa <=> $ob;
        return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });
    return $out;
}

/* ----------------------------------------------------------------------- */
/* Markdown                                                                  */
/* ----------------------------------------------------------------------- */

function nano_cart_render_markdown(string $text): string
{
    static $parser = null;
    if ($parser === null) {
        $parser = new Parsedown();
    }
    return $parser->text($text);
}

function nano_cart_plain_excerpt(string $markdown, int $max = 150): string
{
    $html = nano_cart_render_markdown($markdown);
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    if (mb_strlen($text) <= $max) return $text;
    $cut = mb_substr($text, 0, $max);
    $sp = mb_strrpos($cut, ' ');
    return ($sp !== false ? mb_substr($cut, 0, $sp) : $cut) . '...';
}

/* ----------------------------------------------------------------------- */
/* URLs                                                                      */
/* ----------------------------------------------------------------------- */

/**
 * Canonical URL for a product or category. Pass the loaded array.
 * For homepage, pass null.
 */
function nano_cart_canonical_url(?array $entity = null): string
{
    $base = nano_cart_site_url() . nano_cart_shop_path();
    if ($entity === null) {
        return $base . '/';
    }
    if (isset($entity['sku'], $entity['category'])) {
        return $base . '/' . $entity['category'] . '/' . $entity['sku'] . '/';
    }
    if (isset($entity['slug'])) {
        return $base . '/' . $entity['slug'] . '/';
    }
    return $base . '/';
}

/**
 * Resolve an image path (no extension, no variant suffix) plus a variant
 * and format into a public URL. Variants: hero, thumb, gallery-thumb,
 * original. Formats: jpg, webp.
 *
 * Examples:
 *   nano_cart_image_url('product-images/sku-001/main')
 *     -> /shop/media/product-images/sku-001/main-hero-800.jpg
 *   nano_cart_image_url('category-images/pottery', 'thumb', 'webp')
 *     -> /shop/media/category-images/pottery-thumb-400.webp
 */
function nano_cart_image_url(string $path, string $variant = 'hero', string $format = 'jpg'): string
{
    $suffix = match ($variant) {
        'thumb'         => '-thumb-400',
        'hero'          => '-hero-800',
        'gallery-thumb' => '-thumb-120',
        'original'      => '',
        default         => '-hero-800',
    };
    return nano_cart_shop_path() . '/media/' . $path . $suffix . '.' . $format;
}

function nano_cart_absolute_url(string $url): string
{
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
        return $url;
    }
    return nano_cart_site_url() . $url;
}

/* ----------------------------------------------------------------------- */
/* Images                                                                    */
/* ----------------------------------------------------------------------- */

function nano_cart_primary_image(array $product): ?array
{
    $images = $product['images'] ?? [];
    if (!is_array($images) || empty($images)) return null;
    foreach ($images as $img) {
        if (is_array($img) && !empty($img['is_primary'])) return $img;
    }
    $first = $images[0] ?? null;
    return is_array($first) ? $first : null;
}

/**
 * Pick the dimensions a product image should render at, based on its
 * `image_width` / `image_height` fields (which use presets defined in
 * FORMAT.md). Returns [width|null, height|null] where null means
 * "no explicit dimension, let CSS / layout decide".
 *
 * @return array{0:?int, 1:?int}
 */
function nano_cart_dimensions(array $entity): array
{
    $w_raw = (string)($entity['image_width'] ?? '');
    $h_raw = (string)($entity['image_height'] ?? '');
    $w = ($w_raw === '' || $w_raw === 'full') ? null : (int)$w_raw;
    $h = ($h_raw === '' || $h_raw === 'auto') ? null : (int)$h_raw;
    return [$w, $h];
}

function nano_cart_fit(array $entity): string
{
    $f = (string)($entity['image_fit'] ?? 'contain');
    return ($f === 'cover') ? 'cover' : 'contain';
}

/**
 * Render a <picture> element for a stored image. $base_path is the path
 * inside /media/ without extension or variant suffix. $variant picks the
 * size. Width / height attributes prevent layout shift; pass 0 to omit.
 */
function nano_cart_picture(
    string $base_path,
    string $alt,
    string $variant = 'hero',
    int $width = 0,
    int $height = 0,
    string $class = '',
    bool $lazy = true,
    string $fit = 'contain'
): string {
    $jpg  = nano_cart_image_url($base_path, $variant, 'jpg');
    $webp = nano_cart_image_url($base_path, $variant, 'webp');
    $attrs = '';
    if ($width > 0)  $attrs .= ' width="' . $width . '"';
    if ($height > 0) $attrs .= ' height="' . $height . '"';
    if ($class !== '') $attrs .= ' class="' . htmlspecialchars($class) . '"';
    if ($lazy) $attrs .= ' loading="lazy"';
    $style = '';
    if ($width > 0 || $height > 0 || $fit !== '') {
        $parts = [];
        if ($width > 0)  $parts[] = 'width:' . $width . 'px';
        if ($height > 0) $parts[] = 'height:' . $height . 'px';
        if ($fit !== '') $parts[] = 'object-fit:' . $fit;
        $style = ' style="' . implode(';', $parts) . '"';
    }
    return '<picture>'
        . '<source type="image/webp" srcset="' . htmlspecialchars($webp) . '">'
        . '<img src="' . htmlspecialchars($jpg) . '" alt="' . htmlspecialchars($alt) . '"' . $attrs . $style . '>'
        . '</picture>';
}

/**
 * Structured view of all variants for a product's images. Returns:
 *   [
 *     'primary' => ['file' => ..., 'alt' => ..., 'urls' => [
 *         'hero'         => ['jpg' => ..., 'webp' => ...],
 *         'thumb'        => ['jpg' => ..., 'webp' => ...],
 *         'gallery-thumb'=> ['jpg' => ..., 'webp' => ...],
 *         'original'     => ['jpg' => ...],
 *     ]],
 *     'gallery' => [ ... same shape, one entry per non-primary image ],
 *   ]
 * Returns null entries when no images exist.
 */
function nano_cart_image_set(array $product): array
{
    $images = is_array($product['images'] ?? null) ? $product['images'] : [];
    $sku = (string)($product['sku'] ?? '');
    $build = static function (array $img) use ($sku): array {
        $file = (string)($img['file'] ?? '');
        $base = 'product-images/' . $sku . '/' . $file;
        return [
            'file' => $file,
            'alt'  => (string)($img['alt'] ?? ''),
            'is_primary' => !empty($img['is_primary']),
            'urls' => [
                'original'      => ['jpg' => nano_cart_image_url($base, 'original', 'jpg')],
                'hero'          => ['jpg' => nano_cart_image_url($base, 'hero', 'jpg'),
                                    'webp'=> nano_cart_image_url($base, 'hero', 'webp')],
                'thumb'         => ['jpg' => nano_cart_image_url($base, 'thumb', 'jpg'),
                                    'webp'=> nano_cart_image_url($base, 'thumb', 'webp')],
                'gallery-thumb' => ['jpg' => nano_cart_image_url($base, 'gallery-thumb', 'jpg'),
                                    'webp'=> nano_cart_image_url($base, 'gallery-thumb', 'webp')],
            ],
        ];
    };
    $primary = null;
    $gallery = [];
    foreach ($images as $img) {
        if (!is_array($img)) continue;
        $entry = $build($img);
        if ($entry['is_primary'] && $primary === null) {
            $primary = $entry;
        } else {
            $gallery[] = $entry;
        }
    }
    if ($primary === null && !empty($gallery)) {
        $primary = array_shift($gallery);
        $primary['is_primary'] = true;
    }
    return ['primary' => $primary, 'gallery' => $gallery];
}

/* ----------------------------------------------------------------------- */
/* Price formatting                                                          */
/* ----------------------------------------------------------------------- */

/**
 * Extract a numeric price from a display string. Used for JSON-LD only;
 * the visible price always comes from price_display verbatim.
 * Returns null if no number can be extracted.
 */
function nano_cart_extract_price(string $display): ?float
{
    if (preg_match('/(\d+(?:[.,]\d+)?)/', $display, $m)) {
        return (float)str_replace(',', '.', $m[1]);
    }
    return null;
}

/* ----------------------------------------------------------------------- */
/* Breadcrumbs                                                               */
/* ----------------------------------------------------------------------- */

/**
 * Breadcrumb data: list of ['name' => ..., 'url' => ... or null] items.
 * Pass category and/or product loaded arrays.
 */
function nano_cart_breadcrumb(?array $category = null, ?array $product = null): array
{
    $shop = nano_cart_shop_path();
    $items = [['name' => 'Shop', 'url' => $shop . '/']];
    if ($category !== null) {
        $items[] = ['name' => (string)($category['name'] ?? ''), 'url' => $shop . '/' . $category['slug'] . '/'];
    }
    if ($product !== null) {
        $items[] = ['name' => (string)($product['title'] ?? ''), 'url' => null];
    }
    return $items;
}

function nano_cart_breadcrumb_html(array $crumbs): string
{
    $parts = [];
    $last = count($crumbs) - 1;
    foreach ($crumbs as $i => $crumb) {
        $name = htmlspecialchars((string)$crumb['name']);
        if ($i < $last && !empty($crumb['url'])) {
            $parts[] = '<a class="nano-cart-breadcrumb-item" href="' . htmlspecialchars($crumb['url']) . '">' . $name . '</a>';
        } else {
            $parts[] = '<span class="nano-cart-breadcrumb-item" aria-current="page">' . $name . '</span>';
        }
    }
    return '<nav class="nano-cart-breadcrumb" aria-label="Breadcrumb">'
        . implode('<span class="nano-cart-breadcrumb-separator" aria-hidden="true">&rsaquo;</span>', $parts)
        . '</nav>';
}

/* ----------------------------------------------------------------------- */
/* SEO metadata                                                              */
/* ----------------------------------------------------------------------- */

/**
 * Emit the full <head> metadata block: title, description, canonical,
 * OpenGraph, Twitter Card. Returns HTML string for inclusion in template.
 */
function nano_cart_seo_head(
    string $title,
    string $description,
    string $canonical_url,
    ?string $og_image_url = null,
    string $og_type = 'website'
): string {
    $cfg = nano_cart_load_config();
    $seo = is_array($cfg['seo'] ?? null) ? $cfg['seo'] : [];
    $twitter = (string)($seo['twitter_handle'] ?? '');
    if ($og_image_url === null) {
        $default_og = (string)($seo['og_image'] ?? '');
        if ($default_og !== '') {
            $og_image_url = nano_cart_absolute_url($default_og);
        }
    } else {
        $og_image_url = nano_cart_absolute_url($og_image_url);
    }

    $h  = '<title>' . htmlspecialchars($title) . '</title>' . "\n";
    $h .= '<meta name="description" content="' . htmlspecialchars($description) . '">' . "\n";
    $h .= '<link rel="canonical" href="' . htmlspecialchars($canonical_url) . '">' . "\n";
    $h .= '<meta property="og:title" content="' . htmlspecialchars($title) . '">' . "\n";
    $h .= '<meta property="og:description" content="' . htmlspecialchars($description) . '">' . "\n";
    $h .= '<meta property="og:url" content="' . htmlspecialchars($canonical_url) . '">' . "\n";
    $h .= '<meta property="og:type" content="' . htmlspecialchars($og_type) . '">' . "\n";
    if ($og_image_url) {
        $h .= '<meta property="og:image" content="' . htmlspecialchars($og_image_url) . '">' . "\n";
    }
    $h .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
    if ($twitter !== '') {
        $h .= '<meta name="twitter:site" content="' . htmlspecialchars($twitter) . '">' . "\n";
    }
    return $h;
}

/**
 * JSON-LD Product schema block. Includes BreadcrumbList in the same
 * script tag is NOT recommended; emit them as separate scripts via
 * nano_cart_jsonld_breadcrumb().
 */
function nano_cart_jsonld_product(array $product, array $category): string
{
    $cfg = nano_cart_load_config();
    $seo = is_array($cfg['seo'] ?? null) ? $cfg['seo'] : [];
    $canonical = nano_cart_canonical_url($product);

    $schema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        'name'        => (string)($product['title'] ?? ''),
        'description' => (string)($product['short_description'] ?? ''),
        'sku'         => (string)($product['sku'] ?? ''),
        'url'         => $canonical,
    ];

    $brand = (string)($seo['brand_name'] ?? $cfg['site_name'] ?? '');
    if ($brand !== '') {
        $schema['brand'] = ['@type' => 'Brand', 'name' => $brand];
    }

    $primary = nano_cart_primary_image($product);
    if ($primary !== null) {
        $base = 'product-images/' . $product['sku'] . '/' . $primary['file'];
        $imgs = [];
        foreach (['hero', 'thumb', 'gallery-thumb'] as $v) {
            $imgs[] = nano_cart_absolute_url(nano_cart_image_url($base, $v, 'jpg'));
        }
        $schema['image'] = $imgs;
    }

    $price = nano_cart_extract_price((string)($product['price_display'] ?? ''));
    $mode = (string)($cfg['shop_mode'] ?? 'checkout');
    if ($price !== null && $mode === 'checkout') {
        $schema['offers'] = [
            '@type'         => 'Offer',
            'price'         => number_format($price, 2, '.', ''),
            'priceCurrency' => (string)($cfg['default_currency'] ?? 'GBP'),
            'availability'  => 'https://schema.org/InStock',
            'url'           => $canonical,
        ];
    }

    return '<script type="application/ld+json">'
        . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . '</script>';
}

function nano_cart_jsonld_breadcrumb(array $crumbs): string
{
    $site = nano_cart_site_url();
    $items = [];
    $position = 1;
    foreach ($crumbs as $crumb) {
        $entry = [
            '@type'    => 'ListItem',
            'position' => $position,
            'name'     => (string)$crumb['name'],
        ];
        if (!empty($crumb['url'])) {
            $entry['item'] = $site . $crumb['url'];
        }
        $items[] = $entry;
        $position++;
    }
    $schema = [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $items,
    ];
    return '<script type="application/ld+json">'
        . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . '</script>';
}

/* ----------------------------------------------------------------------- */
/* Buy / enquiry button                                                      */
/* ----------------------------------------------------------------------- */

function nano_cart_buy_button(array $product): string
{
    $cfg = nano_cart_load_config();
    $mode = (string)($cfg['shop_mode'] ?? 'checkout');

    if ($mode === 'catalogue') {
        $action = (string)($cfg['enquiry_action'] ?? '');
        if ($action === '') return '';
        if (str_starts_with($action, 'mailto:')) {
            $sep = str_contains($action, '?') ? '&' : '?';
            $subj = rawurlencode('Enquiry: ' . ($product['title'] ?? '') . ' (' . ($product['sku'] ?? '') . ')');
            $href = $action . $sep . 'subject=' . $subj;
        } else {
            $sep = str_contains($action, '?') ? '&' : '?';
            $href = $action . $sep . 'product=' . rawurlencode((string)($product['sku'] ?? ''));
        }
        return '<a class="nano-cart-buy-button" href="' . htmlspecialchars($href) . '">Enquire</a>';
    }

    $url = (string)($product['checkout_url'] ?? '');
    if ($url === '') return '';
    return '<a class="nano-cart-buy-button" href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">Buy</a>';
}

/* ----------------------------------------------------------------------- */
/* Footer attribution                                                        */
/* ----------------------------------------------------------------------- */

/**
 * Public hook for template.php. Delegates to licence.php which decides
 * whether to show the attribution based on dev-host detection and
 * licence verification. Returns empty string when the footer should
 * be suppressed.
 */
function nano_cart_footer_attribution(): string
{
    return nano_cart_render_licence_footer();
}
