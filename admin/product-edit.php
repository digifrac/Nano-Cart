<?php
/**
 * Nano Cart - product create / edit.
 *
 * Handles both new product creation and editing. When ?sku= is set and
 * the product exists, loads it. POST handler validates, applies
 * hero_featured uniqueness, atomically writes, regenerates sitemap.
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/auth.php';
nano_cart_admin_auth_check();

$shop_path = nano_cart_shop_path();
$admin_url = $shop_path . '/admin';
$cfg = nano_cart_load_config();
$is_checkout = ($cfg['shop_mode'] ?? 'checkout') === 'checkout';

$existing_sku = (string)($_GET['sku'] ?? '');
$loaded = null;
if ($existing_sku !== '') {
    $loaded = nano_cart_load_product($existing_sku);
}
$is_edit = $loaded !== null;

$categories = nano_cart_load_categories();
$errors = [];

$values = [
    'sku'               => $loaded['sku']               ?? '',
    'title'             => $loaded['title']             ?? '',
    'short_description' => $loaded['short_description'] ?? '',
    'long_description'  => $loaded['long_description']  ?? '',
    'category'          => $loaded['category']          ?? ($categories[0]['slug'] ?? ''),
    'price_display'     => $loaded['price_display']     ?? '',
    'checkout_url'      => $loaded['checkout_url']      ?? '',
    'featured'          => !empty($loaded['featured']),
    'hero_featured'     => !empty($loaded['hero_featured']),
    'image_width'       => $loaded['image_width']       ?? '400',
    'image_height'      => $loaded['image_height']      ?? 'auto',
    'image_fit'         => $loaded['image_fit']         ?? 'contain',
    'image_bg'          => $loaded['image_bg']          ?? '',
    'sort_order'        => $loaded['sort_order']        ?? '',
    'status'            => $loaded['status']            ?? 'published',
    'images'            => $loaded['images']            ?? [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nano_cart_admin_csrf_require();

    // A draft save needs only a valid SKU so the operator can save partway
    // through (and get the product on disk so its images can be added in the
    // picker), then come back to complete and publish it later.
    $is_draft = !empty($_POST['save_draft']);

    $values['sku']               = strtolower(trim((string)($_POST['sku'] ?? '')));
    $values['title']             = trim((string)($_POST['title'] ?? ''));
    $values['short_description'] = trim((string)($_POST['short_description'] ?? ''));
    $values['long_description']  = (string)($_POST['long_description'] ?? '');
    $values['category']          = trim((string)($_POST['category'] ?? ''));
    $values['price_display']     = trim((string)($_POST['price_display'] ?? ''));
    $values['checkout_url']      = trim((string)($_POST['checkout_url'] ?? ''));
    $values['featured']          = !empty($_POST['featured']);
    $values['hero_featured']     = !empty($_POST['hero_featured']);
    $values['image_width']       = (string)($_POST['image_width'] ?? '400');
    $values['image_height']      = (string)($_POST['image_height'] ?? 'auto');
    $values['image_fit']         = ($_POST['image_fit'] ?? 'contain') === 'cover' ? 'cover' : 'contain';
    $values['image_bg']          = strtolower(trim((string)($_POST['image_bg'] ?? '')));
    $values['sort_order']        = trim((string)($_POST['sort_order'] ?? ''));
    $values['status']            = ($is_draft || ($_POST['status'] ?? 'published') === 'draft') ? 'draft' : 'published';

    if (!nano_cart_slug_ok($values['sku'])) {
        $errors[] = 'SKU must be lowercase alphanumeric and hyphens only, 2+ characters.';
    }
    // The fields below are only required to PUBLISH. A draft skips them.
    if (!$is_draft) {
        if (mb_strlen($values['title']) < 1 || mb_strlen($values['title']) > 200) {
            $errors[] = 'Title is required, max 200 characters.';
        }
        if (mb_strlen($values['short_description']) < 1 || mb_strlen($values['short_description']) > 300) {
            $errors[] = 'Short description is required, max 300 characters.';
        }
        if ($values['long_description'] === '') {
            $errors[] = 'Long description is required.';
        }
        if (nano_cart_load_category($values['category']) === null) {
            $errors[] = 'Category must reference an existing category.';
        }
        if ($values['price_display'] === '' || mb_strlen($values['price_display']) > 50) {
            $errors[] = 'Price display is required, max 50 characters.';
        }
        if ($is_checkout && !preg_match('#^https://#i', $values['checkout_url'])) {
            $errors[] = 'Checkout URL is required in checkout mode and must use https://.';
        }
    }
    $allowed_widths = ['300','400','500','600','full'];
    if (!in_array($values['image_width'], $allowed_widths, true)) {
        $errors[] = 'Image width must be one of the preset values.';
    }
    if ($values['image_bg'] !== '' && !preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $values['image_bg'])) {
        $errors[] = 'Image background must be a hex colour like #ffffff, or left blank.';
    }
    if ($values['sort_order'] !== '' && !preg_match('/^-?\d+$/', $values['sort_order'])) {
        $errors[] = 'Sort order must be a whole number, or left blank.';
    }
    $allowed_heights = ['auto','300','400','500','600'];
    if (!in_array($values['image_height'], $allowed_heights, true)) {
        $errors[] = 'Image height must be one of the preset values.';
    }

    if ($is_edit && $values['sku'] !== $existing_sku) {
        $errors[] = 'Changing the SKU on an existing product is not supported (it would break URLs).';
    }
    if (!$is_edit && is_file(nano_cart_admin_product_path($values['sku']))) {
        $errors[] = 'A product with that SKU already exists.';
    }

    if (empty($errors)) {
        $to_save = [
            'sku'               => $values['sku'],
            'title'             => $values['title'],
            'short_description' => $values['short_description'],
            'long_description'  => $values['long_description'],
            'category'          => $values['category'],
            'price_display'     => $values['price_display'],
            'checkout_url'      => $values['checkout_url'],
            // Images are managed by the picker over AJAX (upload.php), not this
            // form, so re-read them from disk at save time. Otherwise the stale
            // set from page load clobbers any alt text, reorder, or selection
            // change made in the picker after the page loaded.
            'images'            => $is_edit ? (nano_cart_load_product($existing_sku)['images'] ?? []) : [],
            'featured'          => $values['featured'],
            'hero_featured'     => $values['hero_featured'],
            'image_width'       => $values['image_width'],
            'image_height'      => $values['image_height'],
            'image_fit'         => $values['image_fit'],
            'image_bg'          => $values['image_bg'],
            'sort_order'        => $values['sort_order'] !== '' ? (int)$values['sort_order'] : null,
            'status'            => $values['status'],
        ];
        if ($is_edit) {
            $to_save['created'] = $loaded['created'] ?? null;
        }
        if (nano_cart_admin_save_product($to_save)) {
            if ($is_draft) {
                // Stay on the editor so images can be added (the picker needs
                // the product to exist on disk) and the rest filled in.
                nano_cart_admin_flash_set('success', 'Draft saved. Add images and the remaining details, then Save to publish.');
                nano_cart_admin_redirect($admin_url . '/product-edit.php?sku=' . urlencode($values['sku']));
            }
            nano_cart_admin_flash_set('success', 'Product saved.');
            nano_cart_admin_redirect($admin_url . '/products.php');
        }
        $errors[] = 'Could not write product JSON. Check directory permissions.';
    }
}

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$v = '?v=' . (defined('NANO_CART_VERSION') ? NANO_CART_VERSION : '');
echo nano_cart_admin_header($is_edit ? 'Edit product' : 'Add product', 'products');
echo '<link rel="stylesheet" href="' . $h($admin_url . '/editor-images.css' . $v) . '">';
echo '<script src="' . $h($admin_url . '/editor-images.js' . $v) . '" defer></script>';
?>

<?php if (!empty($errors)): ?>
<div class="nano-cart-admin-flash nano-cart-admin-flash-error">
  <ul><?php foreach ($errors as $e): ?><li><?= $h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="post" class="nano-cart-admin-form" autocomplete="off">
  <?= nano_cart_admin_csrf_field() ?>

  <label>
    SKU (used as filename and URL slug; cannot change later)
    <input type="text" name="sku" required pattern="[a-z0-9][a-z0-9\-]*[a-z0-9]" maxlength="64"
           value="<?= $h($values['sku']) ?>" <?= $is_edit ? 'readonly' : '' ?>>
  </label>

  <label>
    Title
    <input type="text" name="title" required maxlength="200" value="<?= $h($values['title']) ?>">
  </label>

  <label>
    Short description (1-300 characters; meta description + card preview)
    <textarea name="short_description" required maxlength="300" rows="2"><?= $h($values['short_description']) ?></textarea>
  </label>

  <label>
    Long description (markdown)
    <div class="nano-cart-admin-markdown-editor">
      <div class="nano-cart-admin-md-toolbar">
        <button type="button" data-action="bold">Bold</button>
        <button type="button" data-action="italic">Italic</button>
        <button type="button" data-action="link">Link</button>
        <button type="button" data-action="bullet">List</button>
        <button type="button" data-action="paragraph">Paragraph</button>
        <button type="button" class="nano-cart-admin-md-preview-toggle" data-toggle-preview>Preview</button>
      </div>
      <textarea name="long_description" required rows="14"><?= $h($values['long_description']) ?></textarea>
      <div class="nano-cart-admin-md-preview" hidden></div>
    </div>
  </label>

  <label>
    Category
    <select name="category" required>
<?php foreach ($categories as $c): ?>
      <option value="<?= $h((string)$c['slug']) ?>" <?= $values['category'] === ($c['slug'] ?? '') ? 'selected' : '' ?>><?= $h((string)$c['name']) ?></option>
<?php endforeach; ?>
    </select>
  </label>

  <label>
    Price display (free-form text, e.g. "£25.00" or "POA")
    <input type="text" name="price_display" required maxlength="50" value="<?= $h($values['price_display']) ?>">
  </label>

<?php if ($is_checkout): ?>
  <label>
    Checkout URL (https:// only)
    <input type="url" name="checkout_url" required pattern="https://.*" value="<?= $h($values['checkout_url']) ?>">
  </label>
<?php endif; ?>

  <fieldset class="nano-cart-admin-fieldset">
    <legend>Image fitting</legend>
    <label>
      Width
      <select name="image_width">
<?php foreach (['300','400','500','600','full'] as $w): ?>
        <option value="<?= $w ?>" <?= $values['image_width'] === $w ? 'selected' : '' ?>><?= $w === 'full' ? 'full' : $w . 'px' ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label>
      Height
      <select name="image_height">
<?php foreach (['auto','300','400','500','600'] as $hh): ?>
        <option value="<?= $hh ?>" <?= $values['image_height'] === $hh ? 'selected' : '' ?>><?= $hh === 'auto' ? 'auto' : $hh . 'px' ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label class="nano-cart-admin-inline">
      <input type="radio" name="image_fit" value="contain" <?= $values['image_fit'] === 'contain' ? 'checked' : '' ?>>
      Contain (full image visible)
    </label>
    <label class="nano-cart-admin-inline">
      <input type="radio" name="image_fit" value="cover" <?= $values['image_fit'] === 'cover' ? 'checked' : '' ?>>
      Cover (fill the space, crop excess)
    </label>
    <label>
      Image background colour
      <input type="text" name="image_bg" value="<?= htmlspecialchars((string)$values['image_bg']) ?>" placeholder="#1d0a3e" maxlength="7">
      <span class="nano-cart-admin-help">Shown behind this product's image, including through the transparent areas of a PNG. Hex like <code>#1d0a3e</code>; leave blank for none.</span>
    </label>
  </fieldset>

  <fieldset class="nano-cart-admin-fieldset">
    <legend>Flags</legend>
    <label class="nano-cart-admin-inline">
      <input type="checkbox" name="featured" value="1" <?= $values['featured'] ? 'checked' : '' ?>>
      Featured (appears on homepage featured grid)
    </label>
    <label class="nano-cart-admin-inline">
      <input type="checkbox" name="hero_featured" value="1" <?= $values['hero_featured'] ? 'checked' : '' ?>>
      Hero featured (only one product across the shop; saving will clear the flag on any other product)
    </label>
  </fieldset>

  <label>
    Sort order (integer, lower appears first; leave blank to sort alphabetically)
    <input type="text" name="sort_order" pattern="-?\d+" value="<?= $h((string)$values['sort_order']) ?>">
  </label>

  <label>
    Status
    <select name="status">
      <option value="published" <?= $values['status'] === 'published' ? 'selected' : '' ?>>Published</option>
      <option value="draft"     <?= $values['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
    </select>
  </label>

  <fieldset class="nano-cart-admin-fieldset">
    <legend>Images</legend>
    <p class="nano-cart-admin-help">Select images from the media library. Drag thumbnails to reorder; the first is the primary unless you set the star. Upload and organise images in the Media tab.</p>
<?php if ($is_edit): ?>
    <div class="nano-cart-admin-image-manager"
         data-endpoint="<?= $h($admin_url . '/upload.php') ?>"
         data-media-endpoint="<?= $h($admin_url . '/media.php') ?>"
         data-media-url="<?= $h($admin_url . '/media.php') ?>"
         data-csrf="<?= $h(nano_cart_admin_csrf_token()) ?>"
         data-target-type="product"
         data-target-id="<?= $h($values['sku']) ?>"
         data-media-base="<?= $h($shop_path . '/media') ?>"
         data-rel-root="product-images/<?= $h($values['sku']) ?>"
         data-images='<?= $h(json_encode($values['images'], JSON_UNESCAPED_SLASHES)) ?>'></div>
<?php else: ?>
    <p class="nano-cart-admin-empty">Save the product first; image selection opens once the SKU exists on disk.</p>
<?php endif; ?>
  </fieldset>

  <p class="nano-cart-admin-help"><strong>Save</strong> publishes (all fields required). <strong>Save draft</strong> needs only a valid SKU, so you can save now, add images, and finish later.</p>
  <div class="nano-cart-admin-form-actions">
    <button type="submit" class="nano-cart-admin-button nano-cart-admin-button-primary">Save</button>
    <button type="submit" name="save_draft" value="1" formnovalidate class="nano-cart-admin-button nano-cart-admin-button-secondary">Save draft</button>
    <a class="nano-cart-admin-button nano-cart-admin-button-secondary" href="<?= $h($admin_url) ?>/products.php">Cancel</a>
  </div>
</form>

<?= nano_cart_admin_footer() ?>
