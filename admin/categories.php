<?php
/**
 * Nano Cart - category list.
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/auth.php';
nano_cart_admin_auth_check();

$shop_path = nano_cart_shop_path();
$admin_url = $shop_path . '/admin';
$categories = nano_cart_load_categories();

$counts = [];
foreach (nano_cart_load_products(['status' => 'published']) as $p) {
    $c = (string)($p['category'] ?? '');
    if ($c !== '') $counts[$c] = ($counts[$c] ?? 0) + 1;
}
foreach (nano_cart_load_products(['status' => 'draft']) as $p) {
    $c = (string)($p['category'] ?? '');
    if ($c !== '') $counts[$c] = ($counts[$c] ?? 0) + 1;
}

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

echo nano_cart_admin_header('Categories', 'categories');
echo nano_cart_admin_flash_html();
?>

<div class="nano-cart-admin-actions">
  <a class="nano-cart-admin-button nano-cart-admin-button-primary" href="<?= $h($admin_url) ?>/category-edit.php">Add category</a>
</div>

<?php if (empty($categories)): ?>
<p class="nano-cart-admin-empty">No categories yet.</p>
<?php else: ?>
<table class="nano-cart-admin-table">
  <thead>
    <tr>
      <th>Slug</th>
      <th>Name</th>
      <th>Products</th>
      <th>Sort order</th>
      <th>Has image</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($categories as $c):
    $slug = (string)$c['slug'];
    $edit_url = $admin_url . '/category-edit.php?slug=' . urlencode($slug);
    $del_url  = $admin_url . '/category-delete.php?slug=' . urlencode($slug);
?>
    <tr>
      <td><a href="<?= $h($edit_url) ?>"><code><?= $h($slug) ?></code></a></td>
      <td><?= $h((string)$c['name']) ?></td>
      <td><?= (int)($counts[$slug] ?? 0) ?></td>
      <td><?= $h((string)($c['sort_order'] ?? '-')) ?></td>
      <td><?= !empty($c['image']) ? 'yes' : '-' ?></td>
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
