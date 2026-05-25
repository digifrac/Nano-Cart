<?php
/**
 * Nano Cart - category page.
 *
 * Receives ?category=<slug> from the rewrite. Renders breadcrumb +
 * category header (banner + description) + product grid.
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/nano-preflight.php';
nano_cart_preflight();

$slug = (string)($_GET['category'] ?? '');
$category = nano_cart_load_category($slug);
if ($category === null) {
    http_response_code(404);
    require __DIR__ . '/template.php';
    exit;
}

$cfg       = nano_cart_load_config();
$site_name = nano_cart_site_name();
$shop_path = nano_cart_shop_path();
$products  = nano_cart_load_products(['category' => $slug]);
$crumbs    = nano_cart_breadcrumb($category);

$title       = (string)($category['meta_title'] ?? '');
if ($title === '') {
    $title = trim(($category['name'] ?? '') . ' - ' . $site_name, ' -');
}
$description = (string)($category['meta_description'] ?? '');
if ($description === '') {
    $description = $category['description']
        ? nano_cart_plain_excerpt((string)$category['description'])
        : (string)($cfg['seo']['default_meta_description'] ?? '');
}
$canonical = nano_cart_canonical_url($category);
$og_image  = null;
if (!empty($category['image'])) {
    $og_image = nano_cart_image_url('category-images/' . $category['image'], 'hero', 'jpg');
}

$nano_cart_head   = nano_cart_seo_head($title, $description, $canonical, $og_image, 'website');
$nano_cart_jsonld = nano_cart_jsonld_breadcrumb($crumbs);

[$cat_w, $cat_h] = nano_cart_dimensions($category);
$cat_fit         = nano_cart_fit($category);
$cat_pos         = (string)($category['image_position'] ?? 'left');
if ($cat_pos !== 'right') $cat_pos = 'left';

ob_start();
?>
<?= nano_cart_breadcrumb_html($crumbs) ?>
<article class="nano-cart-category">
  <h1 class="nano-cart-page-title"><?= htmlspecialchars($category['name']) ?></h1>
<?php
$has_banner = !empty($category['image']);
$has_desc   = !empty($category['description']);
if ($has_banner || $has_desc):
?>
  <header class="nano-cart-category-header nano-cart-image-<?= htmlspecialchars($cat_pos) ?> <?= $has_banner ? 'has-banner' : 'no-banner' ?>">
<?php if ($has_banner): ?>
    <div class="nano-cart-category-banner">
<?= nano_cart_picture('category-images/' . $category['image'], (string)$category['name'], 'hero', (int)$cat_w, (int)$cat_h, 'nano-cart-category-banner-img', false, $cat_fit) ?>
    </div>
<?php endif; ?>
<?php if ($has_desc): ?>
    <div class="nano-cart-category-description"><?= nano_cart_render_markdown((string)$category['description']) ?></div>
<?php endif; ?>
  </header>
<?php endif; ?>

<?php if (!empty($products)): ?>
  <section class="nano-cart-grid">
<?php foreach ($products as $p):
    $primary = nano_cart_primary_image($p);
    $p_url = $shop_path . '/' . $p['category'] . '/' . $p['sku'] . '/';
?>
    <a class="nano-cart-card" href="<?= htmlspecialchars($p_url) ?>">
<?php if ($primary !== null): ?>
      <div class="nano-cart-card-image">
<?= nano_cart_picture('product-images/' . $p['sku'] . '/' . $primary['file'], (string)$primary['alt'], 'thumb', 0, 0, 'nano-cart-card-img', true, '') ?>
      </div>
<?php endif; ?>
      <div class="nano-cart-card-body">
        <h3 class="nano-cart-card-title"><?= htmlspecialchars($p['title']) ?></h3>
        <p class="nano-cart-card-price"><?= htmlspecialchars($p['price_display']) ?></p>
      </div>
    </a>
<?php endforeach; ?>
  </section>
<?php else: ?>
  <p class="nano-cart-empty">No products in this category yet.</p>
<?php endif; ?>
</article>
<?php
$nano_cart_content = ob_get_clean();
$nano_cart_title = $title;
require __DIR__ . '/template.php';
