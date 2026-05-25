<?php
/**
 * Nano Cart - admin dashboard.
 *
 * Quick stats, recent products, links to common tasks.
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/auth.php';

// No config yet means the operator has not run setup. Redirect with a
// relative URL so we don't need to call nano_cart_shop_path(), which
// itself depends on config.json.
if (!defined('NANO_CART_CONFIG_PATH') || !is_file(NANO_CART_CONFIG_PATH)) {
    nano_cart_admin_redirect('setup.php');
}

nano_cart_admin_auth_check();

$cfg        = nano_cart_load_config();
$products   = nano_cart_load_products(['status' => 'published']);
$drafts     = nano_cart_load_products(['status' => 'draft']);
$categories = nano_cart_load_categories();
$shop_path  = nano_cart_shop_path();
$admin_url  = $shop_path . '/admin';

$recent = $products;
usort($recent, static fn($a, $b) => strcmp((string)($b['updated'] ?? ''), (string)($a['updated'] ?? '')));
$recent = array_slice($recent, 0, 5);

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$install_php = dirname(__DIR__) . '/install.php';
$install_exists = is_file($install_php);

$health = nano_cart_admin_health_checks();
$health_problems = array_values(array_filter($health, static fn($c) => !$c['ok']));

echo nano_cart_admin_header('Dashboard', 'dashboard');
echo nano_cart_admin_flash_html();

if (!empty($health_problems)):
?>
<div class="nano-cart-admin-flash nano-cart-admin-flash-error">
  <p><strong>Installation health check found <?= count($health_problems) ?> problem<?= count($health_problems) === 1 ? '' : 's' ?>.</strong> See the Health check panel below. This usually means an upgrade did not finish: re-upload the affected files.</p>
</div>
<?php endif;

if ($install_exists):
?>
<div class="nano-cart-admin-flash nano-cart-admin-flash-error">
  <p><strong>install.php is still on the server.</strong> Setup is complete; the installer should be removed now. Leaving it in place is a small fingerprinting risk and could let someone reconfigure the shop if your config files were ever wiped.</p>
  <form method="post" action="<?= $h($shop_path) ?>/install.php" style="margin-top:0.5rem">
    <input type="hidden" name="action" value="delete">
    <button type="submit" class="nano-cart-admin-button nano-cart-admin-button-danger" onclick="return confirm('Delete install.php from the server now?')">Delete install.php now</button>
  </form>
</div>
<?php endif; ?>

<section class="nano-cart-admin-stats">
  <div class="nano-cart-admin-stat">
    <div class="nano-cart-admin-stat-value"><?= count($products) ?></div>
    <div class="nano-cart-admin-stat-label">Published products</div>
  </div>
  <div class="nano-cart-admin-stat">
    <div class="nano-cart-admin-stat-value"><?= count($drafts) ?></div>
    <div class="nano-cart-admin-stat-label">Draft products</div>
  </div>
  <div class="nano-cart-admin-stat">
    <div class="nano-cart-admin-stat-value"><?= count($categories) ?></div>
    <div class="nano-cart-admin-stat-label">Categories</div>
  </div>
  <div class="nano-cart-admin-stat">
    <div class="nano-cart-admin-stat-value"><?= $h((string)($cfg['shop_mode'] ?? 'checkout')) ?></div>
    <div class="nano-cart-admin-stat-label">Shop mode</div>
  </div>
</section>

<section class="nano-cart-admin-section">
  <h2 class="nano-cart-admin-section-title">Health check</h2>
  <table class="nano-cart-admin-table">
    <tbody>
<?php foreach ($health as $c): ?>
    <tr>
      <td style="width:1%;white-space:nowrap"><span style="color:<?= $c['ok'] ? '#2c7a2c' : '#b00020' ?>;font-weight:600"><?= $c['ok'] ? 'OK' : 'CHECK' ?></span></td>
      <td style="width:1%;white-space:nowrap"><?= $h($c['label']) ?></td>
      <td><?= $h($c['detail']) ?></td>
    </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  <p class="nano-cart-admin-help">Nano Cart <?= $h('v' . (defined('NANO_CART_VERSION') ? NANO_CART_VERSION : '?')) ?> &middot; running PHP <?= $h(PHP_VERSION) ?>. Check this panel after every upgrade.</p>
</section>

<section class="nano-cart-admin-section">
  <h2 class="nano-cart-admin-section-title">Recent products</h2>
<?php if (empty($recent)): ?>
  <p class="nano-cart-admin-empty">No products yet. <a href="<?= $h($admin_url) ?>/product-edit.php">Create your first product.</a></p>
<?php else: ?>
  <table class="nano-cart-admin-table">
    <thead><tr><th>SKU</th><th>Title</th><th>Category</th><th>Price</th><th>Updated</th></tr></thead>
    <tbody>
<?php foreach ($recent as $p): ?>
    <tr>
      <td><a href="<?= $h($admin_url . '/product-edit.php?sku=' . urlencode((string)$p['sku'])) ?>"><?= $h((string)$p['sku']) ?></a></td>
      <td><?= $h((string)$p['title']) ?></td>
      <td><?= $h((string)$p['category']) ?></td>
      <td><?= $h((string)$p['price_display']) ?></td>
      <td><?= $h(substr((string)($p['updated'] ?? ''), 0, 10)) ?></td>
    </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>

<section class="nano-cart-admin-section">
  <h2 class="nano-cart-admin-section-title">Quick actions</h2>
  <div class="nano-cart-admin-quick-actions">
    <a class="nano-cart-admin-button" href="<?= $h($admin_url) ?>/product-edit.php">Add product</a>
    <a class="nano-cart-admin-button" href="<?= $h($admin_url) ?>/category-edit.php">Add category</a>
    <a class="nano-cart-admin-button" href="<?= $h($admin_url) ?>/settings.php">Edit settings</a>
    <a class="nano-cart-admin-button nano-cart-admin-button-secondary" href="<?= $h($shop_path) ?>/" target="_blank" rel="noopener">View shop</a>
  </div>
</section>

<?= nano_cart_admin_footer() ?>
