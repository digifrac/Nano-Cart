<?php
/**
 * Nano Cart - first-time setup wizard.
 *
 * Runs only when no config.json exists at NANO_CART_CONFIG_PATH. Once
 * setup is complete, this page rejects further visits and points the
 * operator at the login form.
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/auth.php';

$config_exists = defined('NANO_CART_CONFIG_PATH') && is_file(NANO_CART_CONFIG_PATH);
// During setup itself the config does not yet exist; assume the standard
// /shop mount until the operator saves their preference.
$shop_path = $config_exists ? nano_cart_shop_path() : '/shop';
$admin_url = $shop_path . '/admin';

if ($config_exists) {
    nano_cart_admin_redirect($admin_url . '/login.php');
}

$errors = [];
$values = [
    'site_name'      => '',
    'site_url'       => '',
    'shop_mode'      => 'checkout',
    'enquiry_action' => '',
    'currency'       => 'GBP',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nano_cart_admin_csrf_require();

    $values['site_name']      = trim((string)($_POST['site_name'] ?? ''));
    $values['site_url']       = rtrim(trim((string)($_POST['site_url'] ?? '')), '/');
    $values['shop_mode']      = $_POST['shop_mode'] === 'catalogue' ? 'catalogue' : 'checkout';
    $values['enquiry_action'] = trim((string)($_POST['enquiry_action'] ?? ''));
    $values['currency']       = strtoupper(trim((string)($_POST['currency'] ?? 'GBP')));
    $password  = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password_confirm'] ?? '');

    if ($values['site_name'] === '' || mb_strlen($values['site_name']) > 100) {
        $errors[] = 'Site name is required (1-100 characters).';
    }
    if (!preg_match('#^https?://[^\s/]+#i', $values['site_url'])) {
        $errors[] = 'Site URL must begin with http:// or https://.';
    }
    if ($values['shop_mode'] === 'catalogue' && $values['enquiry_action'] === '') {
        $errors[] = 'Enquiry action is required in catalogue mode (mailto: or https:// URL).';
    }
    if ($values['shop_mode'] === 'catalogue'
        && !preg_match('#^(mailto:[^\s]+|https?://[^\s]+)$#i', $values['enquiry_action'])) {
        $errors[] = 'Enquiry action must be a mailto: address or https:// URL.';
    }
    if (!preg_match('/^[A-Z]{3}$/', $values['currency'])) {
        $errors[] = 'Currency must be a 3-letter ISO code (e.g. GBP).';
    }
    if (mb_strlen($password) < 10) {
        $errors[] = 'Password must be at least 10 characters.';
    }
    if ($password !== $password2) {
        $errors[] = 'Password confirmation does not match.';
    }

    if (empty($errors)) {
        $cfg = [
            'site_name'          => $values['site_name'],
            'site_url'           => $values['site_url'],
            'shop_path'          => $shop_path,
            'shop_mode'          => $values['shop_mode'],
            'enquiry_action'     => $values['shop_mode'] === 'catalogue' ? $values['enquiry_action'] : null,
            'password_hash'      => password_hash($password, PASSWORD_BCRYPT),
            'licence_key'        => '',
            'image_quality_jpeg' => 85,
            'image_quality_webp' => 80,
            'thumbnail_widths'   => [400, 800, 120],
            'default_currency'   => $values['currency'],
            'card_image_height'  => '240',
            'card_image_fit'     => 'cover',
            'seo' => [
                'default_meta_description' => '',
                'og_image'                 => '',
                'twitter_handle'           => '',
                'brand_name'               => $values['site_name'],
            ],
            'created'            => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        if (nano_cart_admin_save_config($cfg)) {
            nano_cart_admin_login_success();
            nano_cart_admin_flash_set('success', 'Setup complete. Welcome to the Nano Cart admin.');
            nano_cart_admin_redirect($admin_url . '/');
        }
        $errors[] = 'Could not write config.json. Check that ' . dirname(NANO_CART_CONFIG_PATH) . ' is writable.';
    }
}

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Nano Cart - first-time setup</title>
<link rel="stylesheet" href="<?= $h($admin_url) ?>/assets/admin.css">
</head>
<body class="nano-cart-admin nano-cart-admin-setup">
<main class="nano-cart-admin-main">
<h1 class="nano-cart-admin-page-title">Nano Cart - first-time setup</h1>

<section class="nano-cart-admin-advisory">
  <h2>Nano Cart works best when</h2>
  <ul>
    <li>You sell roughly 20-50 products (scales to 150 if needed)</li>
    <li>Each product is a single-item purchase at a fixed price</li>
    <li>You don't need size, colour, or other variants</li>
    <li>You don't need quantity selectors or a multi-item shopping cart</li>
    <li>Checkout happens via Stripe Payment Link, PayPal hosted checkout, Square, Gumroad, Ko-fi, or similar hosted checkout URL</li>
  </ul>
  <h3>If your shop needs are different</h3>
  <ul>
    <li>Variant-heavy retail (clothing in sizes/colours): try Shopify</li>
    <li>Larger catalogues over 150 SKUs: try WooCommerce</li>
    <li>Simple shops with multi-item cart: try Big Cartel or Gumroad</li>
    <li>Subscriptions or recurring billing: try Lemon Squeezy</li>
  </ul>
  <p class="nano-cart-admin-advisory-note">You can still use Nano Cart if some of these don't quite match your needs. This is guidance, not a restriction.</p>
</section>

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
    <input type="text" name="site_name" required maxlength="100" value="<?= $h($values['site_name']) ?>">
  </label>
  <label>
    Site URL (with scheme, no trailing slash)
    <input type="url" name="site_url" required placeholder="https://example.com" value="<?= $h($values['site_url']) ?>">
  </label>
  <label>
    Default currency (3-letter ISO code)
    <input type="text" name="currency" required pattern="[A-Za-z]{3}" maxlength="3" value="<?= $h($values['currency']) ?>">
  </label>

  <h2>Shop mode</h2>
  <fieldset class="nano-cart-admin-fieldset">
    <label class="nano-cart-admin-inline">
      <input type="radio" name="shop_mode" value="checkout" <?= $values['shop_mode'] === 'checkout' ? 'checked' : '' ?>>
      Checkout: each product links to an external payment URL
    </label>
    <label class="nano-cart-admin-inline">
      <input type="radio" name="shop_mode" value="catalogue" <?= $values['shop_mode'] === 'catalogue' ? 'checked' : '' ?>>
      Catalogue: each product shows an enquiry action instead of buy
    </label>
  </fieldset>
  <label>
    Enquiry action (required in catalogue mode)
    <input type="text" name="enquiry_action" placeholder="mailto:hello@example.com or https://example.com/contact" value="<?= $h($values['enquiry_action']) ?>">
  </label>

  <h2>Admin password</h2>
  <label>
    Password (minimum 10 characters)
    <input type="password" name="password" required minlength="10" autocomplete="new-password">
  </label>
  <label>
    Confirm password
    <input type="password" name="password_confirm" required minlength="10" autocomplete="new-password">
  </label>

  <div class="nano-cart-admin-form-actions">
    <button type="submit" class="nano-cart-admin-button nano-cart-admin-button-primary">I understand, continue setup</button>
  </div>
</form>
</main>
</body>
</html>
