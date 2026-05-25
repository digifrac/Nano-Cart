<?php
/**
 * Nano Cart - category create / edit.
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/auth.php';
nano_cart_admin_auth_check();

$shop_path = nano_cart_shop_path();
$admin_url = $shop_path . '/admin';

$existing_slug = (string)($_GET['slug'] ?? '');
$loaded = $existing_slug !== '' ? nano_cart_load_category($existing_slug) : null;
$is_edit = $loaded !== null;

$errors = [];
$values = [
    'slug'             => $loaded['slug']             ?? '',
    'name'             => $loaded['name']             ?? '',
    'description'      => $loaded['description']      ?? '',
    'image'            => $loaded['image']            ?? '',
    'sort_order'       => $loaded['sort_order']       ?? '',
    'meta_title'       => $loaded['meta_title']       ?? '',
    'meta_description' => $loaded['meta_description'] ?? '',
    'image_width'      => $loaded['image_width']      ?? '400',
    'image_height'     => $loaded['image_height']     ?? 'auto',
    'image_fit'        => $loaded['image_fit']        ?? 'contain',
    'image_position'   => $loaded['image_position']   ?? 'left',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nano_cart_admin_csrf_require();

    $values['slug']             = strtolower(trim((string)($_POST['slug'] ?? '')));
    $values['name']             = trim((string)($_POST['name'] ?? ''));
    $values['description']      = (string)($_POST['description'] ?? '');
    $values['sort_order']       = trim((string)($_POST['sort_order'] ?? ''));
    $values['meta_title']       = trim((string)($_POST['meta_title'] ?? ''));
    $values['meta_description'] = trim((string)($_POST['meta_description'] ?? ''));
    $values['image_width']      = (string)($_POST['image_width'] ?? '400');
    $values['image_height']     = (string)($_POST['image_height'] ?? 'auto');
    $values['image_fit']        = ($_POST['image_fit'] ?? 'contain') === 'cover' ? 'cover' : 'contain';
    $values['image_position']   = ($_POST['image_position'] ?? 'left') === 'right' ? 'right' : 'left';

    if (!nano_cart_slug_ok($values['slug'])) {
        $errors[] = 'Slug must be lowercase alphanumeric and hyphens only, 2+ characters.';
    }
    if (mb_strlen($values['name']) < 1 || mb_strlen($values['name']) > 100) {
        $errors[] = 'Name is required, max 100 characters.';
    }
    $reserved = ['admin','sitemap','assets','lib','products','categories','media'];
    if (in_array($values['slug'], $reserved, true)) {
        $errors[] = 'Slug is reserved (collides with a Nano Cart path).';
    }
    if ($values['sort_order'] !== '' && !preg_match('/^-?\d+$/', $values['sort_order'])) {
        $errors[] = 'Sort order must be an integer (positive or negative).';
    }
    if ($is_edit && $values['slug'] !== $existing_slug) {
        $errors[] = 'Changing the slug on an existing category is not supported (it would break URLs).';
    }
    if (!$is_edit && is_file(nano_cart_admin_category_path($values['slug']))) {
        $errors[] = 'A category with that slug already exists.';
    }

    if (empty($errors)) {
        // The banner is owned by the image picker (saved over AJAX), not this
        // form. Re-read it from disk so a banner just selected or removed in
        // the picker is preserved, never resurrected from a stale page load.
        $final_image = $is_edit ? (nano_cart_load_category($existing_slug)['image'] ?? null) : null;

        $to_save = [
            'slug'             => $values['slug'],
            'name'             => $values['name'],
            'description'      => $values['description'],
            'image'            => $final_image,
            'sort_order'       => $values['sort_order'] !== '' ? (int)$values['sort_order'] : null,
            'meta_title'       => $values['meta_title'] !== '' ? $values['meta_title'] : null,
            'meta_description' => $values['meta_description'] !== '' ? $values['meta_description'] : null,
            'image_width'      => $values['image_width'],
            'image_height'     => $values['image_height'],
            'image_fit'        => $values['image_fit'],
            'image_position'   => $values['image_position'],
        ];
        if (nano_cart_admin_save_category($to_save)) {
            nano_cart_admin_flash_set('success', 'Category saved.');
            nano_cart_admin_redirect($admin_url . '/categories.php');
        }
        $errors[] = 'Could not write category JSON.';
    }
}

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$shop_path = nano_cart_shop_path();
echo nano_cart_admin_header($is_edit ? 'Edit category' : 'Add category', 'categories');
$v = '?v=' . (defined('NANO_CART_VERSION') ? NANO_CART_VERSION : '');
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
    Slug (URL segment; cannot change later)
    <input type="text" name="slug" required pattern="[a-z0-9][a-z0-9\-]*[a-z0-9]" maxlength="64"
           value="<?= $h($values['slug']) ?>" <?= $is_edit ? 'readonly' : '' ?>>
  </label>

  <label>
    Name (display name in headings and breadcrumbs)
    <input type="text" name="name" required maxlength="100" value="<?= $h($values['name']) ?>">
  </label>

  <label>
    Description (markdown)
    <div class="nano-cart-admin-markdown-editor">
      <div class="nano-cart-admin-md-toolbar">
        <button type="button" data-action="bold">Bold</button>
        <button type="button" data-action="italic">Italic</button>
        <button type="button" data-action="link">Link</button>
        <button type="button" data-action="bullet">List</button>
        <button type="button" data-action="paragraph">Paragraph</button>
        <button type="button" class="nano-cart-admin-md-preview-toggle" data-toggle-preview>Preview</button>
      </div>
      <textarea name="description" rows="8"><?= $h($values['description']) ?></textarea>
      <div class="nano-cart-admin-md-preview" hidden></div>
    </div>
  </label>

  <label>
    Sort order (integer, lower appears first; leave blank to sort alphabetically)
    <input type="text" name="sort_order" pattern="-?\d+" value="<?= $h((string)$values['sort_order']) ?>">
  </label>

  <fieldset class="nano-cart-admin-fieldset">
    <legend>Banner image</legend>
<?php
$cat_images = [];
if ($values['image'] !== '') {
    $cat_images[] = ['file' => $values['image'], 'alt' => (string)$values['name'], 'is_primary' => true];
}
?>
<?php if ($is_edit): ?>
    <div class="nano-cart-admin-image-manager"
         data-endpoint="<?= $h($admin_url . '/upload.php') ?>"
         data-media-endpoint="<?= $h($admin_url . '/media.php') ?>"
         data-media-url="<?= $h($admin_url . '/media.php') ?>"
         data-csrf="<?= $h(nano_cart_admin_csrf_token()) ?>"
         data-target-type="category"
         data-target-id="<?= $h($values['slug']) ?>"
         data-media-base="<?= $h($shop_path . '/media') ?>"
         data-rel-root="category-images"
         data-single-image="1"
         data-images='<?= $h(json_encode($cat_images, JSON_UNESCAPED_SLASHES)) ?>'></div>
    <p class="nano-cart-admin-help">One banner per category. Select it from the media library; upload and organise images in the Media tab.</p>
<?php else: ?>
    <p class="nano-cart-admin-empty">Save the category first; image selection opens once the slug exists on disk.</p>
<?php endif; ?>
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
      Contain
    </label>
    <label class="nano-cart-admin-inline">
      <input type="radio" name="image_fit" value="cover" <?= $values['image_fit'] === 'cover' ? 'checked' : '' ?>>
      Cover
    </label>
    <label class="nano-cart-admin-inline">
      <input type="radio" name="image_position" value="left" <?= $values['image_position'] === 'left' ? 'checked' : '' ?>>
      Banner floats left of description
    </label>
    <label class="nano-cart-admin-inline">
      <input type="radio" name="image_position" value="right" <?= $values['image_position'] === 'right' ? 'checked' : '' ?>>
      Banner floats right of description
    </label>
  </fieldset>

  <fieldset class="nano-cart-admin-fieldset">
    <legend>SEO overrides</legend>
    <label>
      Meta title override (leave blank to use "Name - site name")
      <input type="text" name="meta_title" maxlength="120" value="<?= $h((string)$values['meta_title']) ?>">
    </label>
    <label>
      Meta description override (leave blank to derive from description)
      <textarea name="meta_description" rows="2" maxlength="300"><?= $h((string)$values['meta_description']) ?></textarea>
    </label>
  </fieldset>

  <div class="nano-cart-admin-form-actions">
    <button type="submit" class="nano-cart-admin-button nano-cart-admin-button-primary">Save</button>
    <a class="nano-cart-admin-button nano-cart-admin-button-secondary" href="<?= $h($admin_url) ?>/categories.php">Cancel</a>
  </div>
</form>

<?= nano_cart_admin_footer() ?>
