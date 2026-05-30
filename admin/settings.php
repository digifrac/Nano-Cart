<?php
/**
 * Nano Cart - shop settings.
 *
 * Form-editor for every config.json field operators are expected to
 * tune. Validates ranges; writes back atomically; regenerates sitemap.
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/auth.php';
nano_cart_admin_auth_check();

$shop_path = nano_cart_shop_path();
$admin_url = $shop_path . '/admin';
$cfg = nano_cart_load_config();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nano_cart_admin_csrf_require();

    $next = $cfg;
    $next['site_name']         = trim((string)($_POST['site_name'] ?? ''));
    $next['site_url']          = rtrim(trim((string)($_POST['site_url'] ?? '')), '/');
    $next['shop_mode']         = ($_POST['shop_mode'] ?? 'checkout') === 'catalogue' ? 'catalogue' : 'checkout';
    $next['enquiry_action']    = trim((string)($_POST['enquiry_action'] ?? '')) ?: null;
    $next['show_checkout_notice'] = isset($_POST['show_checkout_notice']);
    $next['default_currency']  = strtoupper(trim((string)($_POST['default_currency'] ?? 'GBP')));
    $next['image_quality_jpeg']= (int)($_POST['image_quality_jpeg'] ?? 85);
    $next['image_quality_webp']= (int)($_POST['image_quality_webp'] ?? 80);
    $next['card_image_height']   = (string)($_POST['card_image_height'] ?? '240');
    $next['card_image_fit']      = ($_POST['card_image_fit'] ?? 'cover') === 'contain' ? 'contain' : 'cover';
    $card_pos = (string)($_POST['card_image_position'] ?? 'center');
    $next['card_image_position'] = in_array($card_pos, ['top', 'center', 'bottom', 'left', 'right'], true) ? $card_pos : 'center';
    $next['card_image_bg']       = strtolower(trim((string)($_POST['card_image_bg'] ?? '')));
    $cats_raw  = (int)($_POST['categories_per_row'] ?? 4);
    $prods_raw = (int)($_POST['products_per_row'] ?? 4);
    $next['categories_per_row'] = ($cats_raw === 3) ? 3 : 4;
    $next['products_per_row']   = ($prods_raw === 3) ? 3 : 4;
    $next['seo'] = [
        'default_meta_description' => trim((string)($_POST['seo_default_meta_description'] ?? '')),
        'og_image'                 => trim((string)($_POST['seo_og_image'] ?? '')),
        'twitter_handle'           => trim((string)($_POST['seo_twitter_handle'] ?? '')),
        'brand_name'               => trim((string)($_POST['seo_brand_name'] ?? '')),
    ];

    if ($next['site_name'] === '' || mb_strlen($next['site_name']) > 100) {
        $errors[] = 'Site name is required (1-100 characters).';
    }
    if (!preg_match('#^https?://[^\s/]+#i', $next['site_url'])) {
        $errors[] = 'Site URL must begin with http:// or https://.';
    }
    if ($next['shop_mode'] === 'catalogue'
        && ($next['enquiry_action'] === null
            || !preg_match('#^(mailto:[^\s]+|https?://[^\s]+)$#i', $next['enquiry_action']))) {
        $errors[] = 'Enquiry action must be a mailto: address or https:// URL in catalogue mode.';
    }
    if (!preg_match('/^[A-Z]{3}$/', $next['default_currency'])) {
        $errors[] = 'Currency must be a 3-letter ISO code.';
    }
    foreach (['image_quality_jpeg', 'image_quality_webp'] as $k) {
        if ($next[$k] < 60 || $next[$k] > 95) {
            $errors[] = ucfirst(str_replace('_', ' ', $k)) . ' must be 60-95.';
        }
    }
    if (!preg_match('/^\d+$/', $next['card_image_height'])
        || (int)$next['card_image_height'] < 100
        || (int)$next['card_image_height'] > 600) {
        $errors[] = 'Card thumbnail proportion must be 100-600.';
    }
    if ($next['card_image_bg'] !== '' && !preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $next['card_image_bg'])) {
        $errors[] = 'Image background must be a hex colour like #ffffff, or left blank.';
    }

    // Optional password change.
    $new_password = (string)($_POST['new_password'] ?? '');
    if ($new_password !== '') {
        if (mb_strlen($new_password) < 10) {
            $errors[] = 'New password must be at least 10 characters.';
        } elseif ($new_password !== (string)($_POST['new_password_confirm'] ?? '')) {
            $errors[] = 'New password confirmation does not match.';
        } else {
            $next['password_hash'] = password_hash($new_password, PASSWORD_BCRYPT);
        }
    }

    if (empty($errors)) {
        if (nano_cart_admin_save_config($next)) {
            nano_cart_admin_regenerate_sitemap();
            if ($new_password !== '') {
                nano_cart_admin_logout();
                nano_cart_admin_flash_set('success', 'Settings saved. Password changed - please log in again.');
                nano_cart_admin_redirect($admin_url . '/login.php');
            }
            nano_cart_admin_flash_set('success', 'Settings saved.');
            nano_cart_admin_redirect($admin_url . '/settings.php');
        }
        $errors[] = 'Could not write config.json.';
    }
    $cfg = $next;
}

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$seo = is_array($cfg['seo'] ?? null) ? $cfg['seo'] : [];
$cats_per_row  = ((int)($cfg['categories_per_row'] ?? 4) === 3) ? 3 : 4;
$prods_per_row = ((int)($cfg['products_per_row'] ?? 4) === 3) ? 3 : 4;

echo nano_cart_admin_header('Settings', 'settings');
echo nano_cart_admin_flash_html();
?>

<?php if (!empty($errors)): ?>
<div class="nano-cart-admin-flash nano-cart-admin-flash-error">
  <ul><?php foreach ($errors as $e): ?><li><?= $h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="post" class="nano-cart-admin-form" autocomplete="off">
  <?= nano_cart_admin_csrf_field() ?>

  <h2>Site</h2>
  <label>
    Site name
    <input type="text" name="site_name" required maxlength="100" value="<?= $h((string)$cfg['site_name']) ?>">
  </label>
  <label>
    Site URL (with scheme, no trailing slash)
    <input type="url" name="site_url" required value="<?= $h((string)$cfg['site_url']) ?>">
  </label>
  <label>
    Default currency (3-letter ISO code)
    <input type="text" name="default_currency" required pattern="[A-Za-z]{3}" maxlength="3" value="<?= $h((string)($cfg['default_currency'] ?? 'GBP')) ?>">
  </label>

  <h2>Shop mode</h2>
  <fieldset class="nano-cart-admin-fieldset">
    <label class="nano-cart-admin-inline">
      <input type="radio" name="shop_mode" value="checkout" <?= ($cfg['shop_mode'] ?? 'checkout') === 'checkout' ? 'checked' : '' ?>>
      Checkout: each product links to an external payment URL
    </label>
    <label class="nano-cart-admin-inline">
      <input type="radio" name="shop_mode" value="catalogue" <?= ($cfg['shop_mode'] ?? 'checkout') === 'catalogue' ? 'checked' : '' ?>>
      Catalogue: each product shows an enquiry action
    </label>
  </fieldset>
  <label>
    Enquiry action (required in catalogue mode)
    <input type="text" name="enquiry_action" placeholder="mailto:hello@example.com or https://example.com/contact" value="<?= $h((string)($cfg['enquiry_action'] ?? '')) ?>">
  </label>
  <label class="nano-cart-admin-inline">
    <input type="checkbox" name="show_checkout_notice" value="1" <?= ($cfg['show_checkout_notice'] ?? true) ? 'checked' : '' ?>>
    Show a "secure checkout" notice under the buy button (checkout mode only)
  </label>
  <p class="nano-cart-admin-help">Tells the customer which payment provider handles checkout and that it
    opens in a new tab. The provider name is detected automatically from each product's checkout URL.</p>

  <h2>Image quality</h2>
  <label>
    JPEG quality (60-95)
    <input type="number" name="image_quality_jpeg" min="60" max="95" value="<?= (int)($cfg['image_quality_jpeg'] ?? 85) ?>">
  </label>
  <label>
    WebP quality (60-95)
    <input type="number" name="image_quality_webp" min="60" max="95" value="<?= (int)($cfg['image_quality_webp'] ?? 80) ?>">
  </label>

  <h2>Grid card thumbnails</h2>
  <p class="nano-cart-admin-help">These control the small product and category image thumbnails shown in the
    <strong>category and home grids</strong> only. They do <strong>not</strong> affect the large image on the
    product page (that is set per product, under each product's Image fields).</p>
  <label>
    Card thumbnail proportion (100-600)
    <input type="number" name="card_image_height" min="100" max="600" value="<?= (int)($cfg['card_image_height'] ?? 240) ?>">
    <span class="nano-cart-admin-help">Controls the thumbnail's shape, which now scales with the card so the framing is identical on every screen size. <code>240</code> is a square; lower is wider (landscape), higher is taller (portrait). It is no longer a fixed pixel height, so it can't be cropped differently on phones and desktops.</span>
  </label>
  <fieldset class="nano-cart-admin-fieldset">
    <legend>Card thumbnail fit</legend>
    <label class="nano-cart-admin-inline">
      <input type="radio" name="card_image_fit" value="cover" <?= ($cfg['card_image_fit'] ?? 'cover') === 'cover' ? 'checked' : '' ?>>
      Cover (crop to fill the card, uniform look)
    </label>
    <label class="nano-cart-admin-inline">
      <input type="radio" name="card_image_fit" value="contain" <?= ($cfg['card_image_fit'] ?? 'cover') === 'contain' ? 'checked' : '' ?>>
      Contain (show the whole image, may leave space)
    </label>
  </fieldset>
  <label>
    Card thumbnail crop position (used when fit is Cover)
    <?php $cpos = (string)($cfg['card_image_position'] ?? 'center'); ?>
    <select name="card_image_position">
<?php foreach (['top' => 'Top', 'center' => 'Center', 'bottom' => 'Bottom', 'left' => 'Left', 'right' => 'Right'] as $val => $lbl): ?>
      <option value="<?= $h($val) ?>" <?= $cpos === $val ? 'selected' : '' ?>><?= $h($lbl) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label>
    Image background colour
    <input type="text" name="card_image_bg" value="<?= $h((string)($cfg['card_image_bg'] ?? '')) ?>" placeholder="#ffffff" maxlength="7">
    <span class="nano-cart-admin-help">Shown behind images, including through the transparent areas of a PNG. A hex colour like <code>#ffffff</code>; leave blank for none (transparent). Applies in both the default and neon themes.</span>
  </label>

  <h2>Layout</h2>
  <label>Categories per row
    <select name="categories_per_row">
      <option value="3"<?= $cats_per_row === 3 ? ' selected' : '' ?>>3</option>
      <option value="4"<?= $cats_per_row === 4 ? ' selected' : '' ?>>4 (default)</option>
    </select>
  </label>
  <p class="nano-cart-admin-help">How many category cards appear per row on the shop homepage (on wide screens; narrower screens automatically show fewer).</p>

  <label>Products per row
    <select name="products_per_row">
      <option value="3"<?= $prods_per_row === 3 ? ' selected' : '' ?>>3</option>
      <option value="4"<?= $prods_per_row === 4 ? ' selected' : '' ?>>4 (default)</option>
    </select>
  </label>
  <p class="nano-cart-admin-help">How many product cards appear per row on category pages and the homepage Featured grid (on wide screens).</p>

  <h2>SEO defaults</h2>
  <label>
    Default meta description (used on the homepage)
    <textarea name="seo_default_meta_description" rows="2" maxlength="300"><?= $h((string)($seo['default_meta_description'] ?? '')) ?></textarea>
  </label>
  <label>
    Default OG image URL (absolute or site-relative, used when a page has no image of its own)
    <input type="text" name="seo_og_image" value="<?= $h((string)($seo['og_image'] ?? '')) ?>">
  </label>
  <label>
    Twitter handle (include @)
    <input type="text" name="seo_twitter_handle" value="<?= $h((string)($seo['twitter_handle'] ?? '')) ?>">
  </label>
  <label>
    Brand name (used in JSON-LD Product schema; defaults to site name if blank)
    <input type="text" name="seo_brand_name" value="<?= $h((string)($seo['brand_name'] ?? '')) ?>">
  </label>

  <h2>Admin password</h2>
  <p class="nano-cart-admin-help">Leave blank to keep the current password. Changing the password logs you out.</p>
  <label>
    New password (minimum 10 characters)
    <input type="password" name="new_password" minlength="10" autocomplete="new-password">
  </label>
  <label>
    Confirm new password
    <input type="password" name="new_password_confirm" minlength="10" autocomplete="new-password">
  </label>

  <div class="nano-cart-admin-form-actions">
    <button type="submit" class="nano-cart-admin-button nano-cart-admin-button-primary">Save settings</button>
  </div>
</form>

<?= nano_cart_admin_footer() ?>
