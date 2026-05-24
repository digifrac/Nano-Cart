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
    $next['default_currency']  = strtoupper(trim((string)($_POST['default_currency'] ?? 'GBP')));
    $next['image_quality_jpeg']= (int)($_POST['image_quality_jpeg'] ?? 85);
    $next['image_quality_webp']= (int)($_POST['image_quality_webp'] ?? 80);
    $next['card_image_height'] = (string)($_POST['card_image_height'] ?? '240');
    $next['card_image_fit']    = ($_POST['card_image_fit'] ?? 'cover') === 'contain' ? 'contain' : 'cover';
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
        $errors[] = 'Card image height must be 100-600 (pixels).';
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

  <h2>Images</h2>
  <label>
    JPEG quality (60-95)
    <input type="number" name="image_quality_jpeg" min="60" max="95" value="<?= (int)($cfg['image_quality_jpeg'] ?? 85) ?>">
  </label>
  <label>
    WebP quality (60-95)
    <input type="number" name="image_quality_webp" min="60" max="95" value="<?= (int)($cfg['image_quality_webp'] ?? 80) ?>">
  </label>
  <label>
    Card image height (pixels, 100-600)
    <input type="number" name="card_image_height" min="100" max="600" value="<?= (int)($cfg['card_image_height'] ?? 240) ?>">
  </label>
  <fieldset class="nano-cart-admin-fieldset">
    <legend>Card image fit (applies to all category-page cards)</legend>
    <label class="nano-cart-admin-inline">
      <input type="radio" name="card_image_fit" value="cover" <?= ($cfg['card_image_fit'] ?? 'cover') === 'cover' ? 'checked' : '' ?>>
      Cover (fill the card)
    </label>
    <label class="nano-cart-admin-inline">
      <input type="radio" name="card_image_fit" value="contain" <?= ($cfg['card_image_fit'] ?? 'cover') === 'contain' ? 'checked' : '' ?>>
      Contain (full image visible)
    </label>
  </fieldset>

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
