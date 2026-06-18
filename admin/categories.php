<?php
/**
 * Nano Cart - category list, plus the homepage slot picker.
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/auth.php';
nano_cart_admin_auth_check();

$shop_path = nano_cart_shop_path();
$admin_url = $shop_path . '/admin';
$cfg = nano_cart_load_config();

// Homepage grid is two rows of cards: 6 at 3-per-row, 8 at 4-per-row.
$cats_per_row = ((int)($cfg['categories_per_row'] ?? 4) === 3) ? 3 : 4;
$cap = $cats_per_row * 2;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nano_cart_admin_csrf_require();
    $slots_in = is_array($_POST['slot'] ?? null) ? $_POST['slot'] : [];
    $slot_to_slug = [];
    for ($i = 1; $i <= $cap; $i++) {
        $slot_to_slug[$i] = (string)($slots_in[$i] ?? '');
    }
    if (nano_cart_admin_set_homepage_slots($slot_to_slug, $cap)) {
        nano_cart_admin_flash_set('success', 'Homepage slots saved.');
    } else {
        nano_cart_admin_flash_set('error', 'Could not save one or more categories.');
    }
    nano_cart_admin_redirect($admin_url . '/categories.php');
}

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

// Which category currently holds each slot (first wins on any clash).
$slot_holder = [];
foreach ($categories as $c) {
    if (!array_key_exists('homepage_slot', $c)) continue;
    $s = (int)$c['homepage_slot'];
    if ($s >= 1 && $s <= $cap && !isset($slot_holder[$s])) {
        $slot_holder[$s] = (string)($c['slug'] ?? '');
    }
}
$slot_by_slug = array_flip($slot_holder);

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

echo nano_cart_admin_header('Categories', 'categories');
echo nano_cart_admin_flash_html();
?>

<div class="nano-cart-admin-actions">
  <a class="nano-cart-admin-button nano-cart-admin-button-primary" href="<?= $h($admin_url) ?>/category-edit.php">Add category</a>
</div>

<?php if (!empty($categories)): ?>
<section class="nano-cart-admin-section">
  <h2 class="nano-cart-admin-section-title">Homepage slots</h2>
  <p class="nano-cart-admin-help">The shop homepage shows up to <strong><?= (int)$cap ?></strong> category cards
    (two rows of <?= (int)$cats_per_row ?>, set by Products/Categories per row in Settings). Pick which category fills
    each slot, in order. Categories left out still appear in the off-canvas <em>Categories</em> menu.
    Leave every slot empty to show all categories (the default).</p>
  <form method="post" class="nano-cart-admin-form">
    <?= nano_cart_admin_csrf_field() ?>
    <div class="nano-cart-admin-slots">
<?php for ($i = 1; $i <= $cap; $i++):
    $held = $slot_holder[$i] ?? ''; ?>
      <label>Slot <?= (int)$i ?>
        <select name="slot[<?= (int)$i ?>]">
          <option value="">&mdash; Empty &mdash;</option>
<?php foreach ($categories as $c): $cslug = (string)($c['slug'] ?? ''); ?>
          <option value="<?= $h($cslug) ?>"<?= $cslug === $held ? ' selected' : '' ?>><?= $h((string)($c['name'] ?? $cslug)) ?></option>
<?php endforeach; ?>
        </select>
      </label>
<?php endfor; ?>
    </div>
    <div class="nano-cart-admin-form-actions">
      <button type="submit" class="nano-cart-admin-button nano-cart-admin-button-primary">Save homepage slots</button>
    </div>
  </form>
</section>
<?php endif; ?>

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
      <th>Homepage</th>
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
      <td><?= isset($slot_by_slug[$slug]) ? 'Slot ' . (int)$slot_by_slug[$slug] : '-' ?></td>
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
