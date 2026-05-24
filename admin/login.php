<?php
/**
 * Nano Cart - admin login.
 *
 * Password-only (single-user admin). Rate-limited via exponential
 * backoff per-IP, never hard lockout. The response delay before
 * reporting a failure is the brute-force defence: at 50+ failures from
 * one IP each attempt takes 16 seconds. Successful login resets the
 * counter.
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/auth.php';

// Check config existence FIRST so we don't call nano_cart_shop_path()
// before config.json exists (it throws when config is missing).
if (!defined('NANO_CART_CONFIG_PATH') || !is_file(NANO_CART_CONFIG_PATH)) {
    nano_cart_admin_redirect('setup.php');
}

$shop_path = nano_cart_shop_path();
$admin_url = $shop_path . '/admin';

if (nano_cart_admin_is_logged_in()) {
    nano_cart_admin_redirect($admin_url . '/');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nano_cart_admin_csrf_require();
    $password = (string)($_POST['password'] ?? '');
    $ip = nano_cart_admin_client_ip();

    $delay = nano_cart_admin_rate_limit_delay($ip);
    if ($delay > 0) sleep($delay);

    if (nano_cart_admin_password_check($password)) {
        nano_cart_admin_rate_limit_reset($ip);
        nano_cart_admin_login_success();
        nano_cart_admin_redirect($admin_url . '/');
    }
    nano_cart_admin_rate_limit_record_failure($ip);
    $error = 'Incorrect password.';
}

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Log in - Nano Cart admin</title>
<link rel="stylesheet" href="<?= $h($admin_url) ?>/assets/admin.css">
</head>
<body class="nano-cart-admin nano-cart-admin-login">
<main class="nano-cart-admin-main">
<h1 class="nano-cart-admin-page-title">Nano Cart admin</h1>
<?php if ($error !== ''): ?>
<div class="nano-cart-admin-flash nano-cart-admin-flash-error"><?= $h($error) ?></div>
<?php endif; ?>
<form method="post" class="nano-cart-admin-form nano-cart-admin-form-login" autocomplete="off">
  <?= nano_cart_admin_csrf_field() ?>
  <label>
    Password
    <input type="password" name="password" required autofocus autocomplete="current-password">
  </label>
  <div class="nano-cart-admin-form-actions">
    <button type="submit" class="nano-cart-admin-button nano-cart-admin-button-primary">Log in</button>
  </div>
</form>
</main>
</body>
</html>
