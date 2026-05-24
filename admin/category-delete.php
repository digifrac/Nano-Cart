<?php
/**
 * Nano Cart - category delete with confirmation and reassignment check.
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/auth.php';
nano_cart_admin_auth_check();

$shop_path = nano_cart_shop_path();
$admin_url = $shop_path . '/admin';

$slug = (string)($_GET['slug'] ?? $_POST['slug'] ?? '');
$category = nano_cart_load_category($slug);
if ($category === null) {
    nano_cart_admin_flash_set('error', 'Category not found.');
    nano_cart_admin_redirect($admin_url . '/categories.php');
}

$using = nano_cart_admin_products_using_category($slug);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nano_cart_admin_csrf_require();
    if (!empty($using) && empty($_POST['confirm_reassign'])) {
        nano_cart_admin_flash_set('error', 'Reassign the listed products first.');
    } elseif (nano_cart_admin_delete_category($slug)) {
        nano_cart_admin_flash_set('success', 'Category deleted.');
    } else {
        nano_cart_admin_flash_set('error', 'Could not delete category.');
    }
    nano_cart_admin_redirect($admin_url . '/categories.php');
}

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

echo nano_cart_admin_header('Delete category', 'categories');
?>

<div class="nano-cart-admin-confirm">
  <p>Delete category <strong><?= $h((string)$category['name']) ?></strong> (<code><?= $h((string)$category['slug']) ?></code>)?</p>
<?php if (!empty($using)): ?>
  <div class="nano-cart-admin-flash nano-cart-admin-flash-error">
    <p>The following <?= count($using) ?> product(s) are in this category. Reassign them to another category before deleting:</p>
    <ul>
<?php foreach ($using as $sku): ?>
      <li><a href="<?= $h($admin_url . '/product-edit.php?sku=' . urlencode($sku)) ?>"><code><?= $h($sku) ?></code></a></li>
<?php endforeach; ?>
    </ul>
  </div>
  <form method="post" class="nano-cart-admin-form">
    <div class="nano-cart-admin-form-actions">
      <a class="nano-cart-admin-button nano-cart-admin-button-secondary" href="<?= $h($admin_url) ?>/categories.php">Back to categories</a>
    </div>
  </form>
<?php else: ?>
  <p>No products reference this category. Safe to delete.</p>
  <form method="post" class="nano-cart-admin-form">
    <?= nano_cart_admin_csrf_field() ?>
    <input type="hidden" name="slug" value="<?= $h((string)$category['slug']) ?>">
    <div class="nano-cart-admin-form-actions">
      <button type="submit" class="nano-cart-admin-button nano-cart-admin-button-danger">Delete permanently</button>
      <a class="nano-cart-admin-button nano-cart-admin-button-secondary" href="<?= $h($admin_url) ?>/categories.php">Cancel</a>
    </div>
  </form>
<?php endif; ?>
</div>

<?= nano_cart_admin_footer() ?>
