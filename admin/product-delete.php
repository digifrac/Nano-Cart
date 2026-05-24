<?php
/**
 * Nano Cart - product delete with confirmation.
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/auth.php';
nano_cart_admin_auth_check();

$shop_path = nano_cart_shop_path();
$admin_url = $shop_path . '/admin';

$sku = (string)($_GET['sku'] ?? $_POST['sku'] ?? '');
$product = nano_cart_load_product($sku);
if ($product === null) {
    nano_cart_admin_flash_set('error', 'Product not found.');
    nano_cart_admin_redirect($admin_url . '/products.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nano_cart_admin_csrf_require();
    if (nano_cart_admin_delete_product($sku)) {
        nano_cart_admin_flash_set('success', 'Product deleted.');
    } else {
        nano_cart_admin_flash_set('error', 'Could not delete product.');
    }
    nano_cart_admin_redirect($admin_url . '/products.php');
}

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

echo nano_cart_admin_header('Delete product', 'products');
?>

<div class="nano-cart-admin-confirm">
  <p>Delete product <strong><?= $h((string)$product['title']) ?></strong> (<code><?= $h((string)$product['sku']) ?></code>)?</p>
  <p>This removes <code>products/<?= $h((string)$product['sku']) ?>.json</code> and the entire <code>media/product-images/<?= $h((string)$product['sku']) ?>/</code> directory. It cannot be undone from the admin.</p>
  <form method="post" class="nano-cart-admin-form">
    <?= nano_cart_admin_csrf_field() ?>
    <input type="hidden" name="sku" value="<?= $h((string)$product['sku']) ?>">
    <div class="nano-cart-admin-form-actions">
      <button type="submit" class="nano-cart-admin-button nano-cart-admin-button-danger">Delete permanently</button>
      <a class="nano-cart-admin-button nano-cart-admin-button-secondary" href="<?= $h($admin_url) ?>/products.php">Cancel</a>
    </div>
  </form>
</div>

<?= nano_cart_admin_footer() ?>
