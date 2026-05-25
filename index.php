<?php
/**
 * Nano Cart - shop homepage.
 *
 * Default layout: optional hero featured product + category grid +
 * featured products grid. Operators can replace this file with a
 * custom homepage; it is operator-owned, not framework-owned.
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/nano-preflight.php';
nano_cart_preflight();

$cfg        = nano_cart_load_config();
$site_name  = nano_cart_site_name();
$shop_path  = nano_cart_shop_path();
$categories = nano_cart_load_categories();
$featured   = nano_cart_load_products(['featured' => true]);
$heroes     = nano_cart_load_products(['hero_featured' => true]);
$hero       = $heroes[0] ?? null;
$cats_by_slug = [];
foreach ($categories as $c) {
    if (isset($c['slug'])) $cats_by_slug[$c['slug']] = $c;
}

$title        = $site_name !== '' ? $site_name : 'Shop';
$description  = (string)($cfg['seo']['default_meta_description'] ?? '');
$canonical    = nano_cart_canonical_url();
$nano_cart_head   = nano_cart_seo_head($title, $description, $canonical, null, 'website');
$nano_cart_jsonld = '';

ob_start();

$has_any_content = $hero !== null || !empty($categories) || !empty($featured);
$page_h1 = $site_name !== '' ? $site_name : 'Shop';
?>
<article class="nano-cart-home">
  <h1 class="nano-cart-page-title"><?= htmlspecialchars($page_h1) ?></h1>
<?php if (!$has_any_content): ?>
  <section class="nano-cart-empty">
    <p>This shop has no products yet. Please check back soon.</p>
  </section>
<?php endif; ?>
<?php if ($hero !== null && !empty($hero['category']) && !empty($hero['sku'])):
    $primary = nano_cart_primary_image($hero);
    $hero_cat = $cats_by_slug[$hero['category']] ?? null;
    $hero_url = nano_cart_shop_path() . '/' . $hero['category'] . '/' . $hero['sku'] . '/';
?>
  <section class="nano-cart-hero">
    <a class="nano-cart-hero-link" href="<?= htmlspecialchars($hero_url) ?>">
<?php if ($primary !== null): ?>
      <div class="nano-cart-hero-image">
<?= nano_cart_picture('product-images/' . $hero['sku'] . '/' . $primary['file'], (string)$primary['alt'], 'hero', 0, 0, 'nano-cart-hero-img', false, 'cover') ?>
      </div>
<?php endif; ?>
      <div class="nano-cart-hero-body">
        <h2 class="nano-cart-hero-title"><?= htmlspecialchars($hero['title']) ?></h2>
<?php if (!empty($hero['short_description'])): ?>
        <p class="nano-cart-hero-summary"><?= htmlspecialchars($hero['short_description']) ?></p>
<?php endif; ?>
        <p class="nano-cart-hero-price"><?= htmlspecialchars($hero['price_display']) ?></p>
      </div>
    </a>
  </section>
<?php endif; ?>

<?php if (!empty($categories)): ?>
  <section class="nano-cart-section nano-cart-categories">
    <h2 class="nano-cart-section-title">Categories</h2>
    <div class="nano-cart-grid">
<?php foreach ($categories as $cat):
    $cat_url = $shop_path . '/' . $cat['slug'] . '/';
    $has_img = !empty($cat['image']);
?>
      <a class="nano-cart-card" href="<?= htmlspecialchars($cat_url) ?>">
<?php if ($has_img): ?>
        <div class="nano-cart-card-image">
<?= nano_cart_picture('category-images/' . $cat['image'], (string)$cat['name'], 'thumb', 0, 0, 'nano-cart-card-img', true, '') ?>
        </div>
<?php endif; ?>
        <div class="nano-cart-card-body">
          <h3 class="nano-cart-card-title"><?= htmlspecialchars($cat['name']) ?></h3>
        </div>
      </a>
<?php endforeach; ?>
    </div>
  </section>
<?php endif;
$featured_excl_hero = array_values(array_filter($featured, static fn($p) => empty($p['hero_featured'])));
if (!empty($featured_excl_hero)):
?>
  <section class="nano-cart-section nano-cart-featured">
    <h2 class="nano-cart-section-title">Featured</h2>
    <div class="nano-cart-grid">
<?php foreach ($featured_excl_hero as $p):
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
    </div>
  </section>
<?php endif; ?>
</article>
<?php
$nano_cart_content = ob_get_clean();
$nano_cart_title = $title;
require __DIR__ . '/template.php';
