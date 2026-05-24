<?php
/**
 * Nano Cart - product list.
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/auth.php';
nano_cart_admin_auth_check();

$shop_path = nano_cart_shop_path();
$admin_url = $shop_path . '/admin';
$products = nano_cart_load_products(['status' => 'published'])
          + [];
$drafts = nano_cart_load_products(['status' => 'draft']);
$all = array_merge(nano_cart_load_products(['status' => 'published']), $drafts);
usort($all, static fn($a, $b) => strcmp((string)($b['updated'] ?? ''), (string)($a['updated'] ?? '')));

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

echo nano_cart_admin_header('Products', 'products');
echo nano_cart_admin_flash_html();
?>

<div class="nano-cart-admin-actions">
  <a class="nano-cart-admin-button nano-cart-admin-button-primary" href="<?= $h($admin_url) ?>/product-edit.php">Add product</a>
</div>

<?php if (empty($all)): ?>
<p class="nano-cart-admin-empty">No products yet.</p>
<?php else: ?>
<table class="nano-cart-admin-table">
  <thead>
    <tr>
      <th>SKU</th>
      <th>Title</th>
      <th>Category</th>
      <th>Price</th>
      <th>Status</th>
      <th>Featured</th>
      <th>Updated</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($all as $p):
    $sku = (string)$p['sku'];
    $edit_url = $admin_url . '/product-edit.php?sku=' . urlencode($sku);
    $del_url  = $admin_url . '/product-delete.php?sku=' . urlencode($sku);
    $status = (string)($p['status'] ?? 'published');
    $featured_bits = [];
    if (!empty($p['hero_featured'])) $featured_bits[] = 'hero';
    if (!empty($p['featured'])) $featured_bits[] = 'featured';
?>
    <tr>
      <td><a href="<?= $h($edit_url) ?>"><?= $h($sku) ?></a></td>
      <td><?= $h((string)$p['title']) ?></td>
      <td><?= $h((string)$p['category']) ?></td>
      <td><?= $h((string)$p['price_display']) ?></td>
      <td><span class="nano-cart-admin-pill nano-cart-admin-pill-<?= $h($status) ?>"><?= $h($status) ?></span></td>
      <td><?= $h(implode(' + ', $featured_bits)) ?></td>
      <td><?= $h(substr((string)($p['updated'] ?? ''), 0, 10)) ?></td>
      <td>
        <a href="<?= $h($edit_url) ?>">Edit</a>
        &middot;
        <a href="<?= $h($del_url) ?>" class="nano-cart-admin-link-danger">Delete</a>
      </td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?= nano_cart_admin_footer() ?>
