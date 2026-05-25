<?php
/**
 * Nano Cart - product page.
 *
 * Receives ?category=<slug>&product=<slug>. Validates that the product's
 * category matches the URL's category so the canonical URL is the only
 * URL the product is served under. Renders breadcrumb + image gallery
 * (main image + thumbnail strip for additional images) + title + price
 * + buy button + long description.
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/nano-preflight.php';
nano_cart_preflight();

$cat_slug     = (string)($_GET['category'] ?? '');
$product_slug = (string)($_GET['product'] ?? '');
$product = nano_cart_load_product($product_slug);
if ($product === null || ($product['category'] ?? '') !== $cat_slug) {
    http_response_code(404);
    require __DIR__ . '/template.php';
    exit;
}
if (($product['status'] ?? 'published') === 'draft') {
    http_response_code(404);
    require __DIR__ . '/template.php';
    exit;
}
$category = nano_cart_load_category($cat_slug);
if ($category === null) {
    http_response_code(404);
    require __DIR__ . '/template.php';
    exit;
}

$cfg       = nano_cart_load_config();
$site_name = nano_cart_site_name();
$crumbs    = nano_cart_breadcrumb($category, $product);

$meta_title = trim(($product['title'] ?? '') . ' - ' . $site_name, ' -');
$description = (string)($product['short_description'] ?? '');
$canonical = nano_cart_canonical_url($product);
$primary = nano_cart_primary_image($product);
$og_image = $primary !== null
    ? nano_cart_image_url('product-images/' . $product['sku'] . '/' . $primary['file'], 'hero', 'jpg')
    : null;

$nano_cart_head = nano_cart_seo_head($meta_title, $description, $canonical, $og_image, 'product');
$nano_cart_jsonld = nano_cart_jsonld_product($product, $category)
    . nano_cart_jsonld_breadcrumb($crumbs);

[$img_w, $img_h] = nano_cart_dimensions($product);
$img_fit = nano_cart_fit($product);

$gallery = array_values(array_filter(
    $product['images'] ?? [],
    static fn($img) => is_array($img) && empty($img['is_primary'])
));

ob_start();
?>
<?= nano_cart_breadcrumb_html($crumbs) ?>
<article class="nano-cart-product">
  <h1 class="nano-cart-product-title"><?= htmlspecialchars($product['title']) ?></h1>

  <div class="nano-cart-product-layout">
    <div class="nano-cart-gallery">
<?php if ($primary !== null): ?>
      <figure class="nano-cart-gallery-main">
<?= nano_cart_picture('product-images/' . $product['sku'] . '/' . $primary['file'], (string)$primary['alt'], 'hero', (int)$img_w, 0, 'nano-cart-gallery-main-img', false, '') ?>
      </figure>
<?php endif; ?>
<?php if (!empty($gallery)): ?>
      <div class="nano-cart-gallery-thumbs" role="list">
<?php foreach ($gallery as $img): ?>
        <figure class="nano-cart-gallery-thumb" role="listitem">
<?= nano_cart_picture('product-images/' . $product['sku'] . '/' . $img['file'], (string)$img['alt'], 'gallery-thumb', 120, 120, 'nano-cart-gallery-thumb-img', true, 'cover') ?>
        </figure>
<?php endforeach; ?>
      </div>
<?php endif; ?>
    </div>

    <div class="nano-cart-product-meta">
<?php if (!empty($product['short_description'])): ?>
      <p class="nano-cart-product-summary"><?= htmlspecialchars((string)$product['short_description']) ?></p>
<?php endif; ?>
      <div class="nano-cart-buy-panel">
<?php if (($cfg['shop_mode'] ?? 'checkout') !== 'checkout'): ?>
        <p class="nano-cart-product-price"><?= htmlspecialchars($product['price_display']) ?></p>
<?php endif; ?>
<?= nano_cart_buy_button($product) ?>
<?= nano_cart_checkout_notice($product) ?>
      </div>
    </div>
  </div>

<?php if (!empty($product['long_description'])): ?>
  <div class="nano-cart-product-description">
<?= nano_cart_render_markdown((string)$product['long_description']) ?>
  </div>
<?php endif; ?>
</article>
<?php
$nano_cart_content = ob_get_clean();
$nano_cart_title = $meta_title;
require __DIR__ . '/template.php';
