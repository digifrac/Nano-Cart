<?php
/**
 * Nano Cart - HTML wrapper.
 *
 * Per-site customisable. Operators edit this file to match the host
 * site's existing chrome (header, nav, footer). Renderers expose
 * variables before requiring this file:
 *   $nano_cart_title     string  <title> contents
 *   $nano_cart_head      string  metadata block (already includes <title>)
 *   $nano_cart_content   string  page body HTML
 *   $nano_cart_jsonld    string  JSON-LD <script> blocks (may be empty)
 *
 * This default template is bare so it works standalone; replace the
 * header / footer with your host site's HTML.
 */

if (!defined('NANO_CART_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

$shop_path = nano_cart_shop_path();
$assets    = $shop_path . '/assets';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?= $nano_cart_head ?? '' ?>
<link rel="stylesheet" href="<?= htmlspecialchars($assets) ?>/nano-cart.css">
<?= $nano_cart_jsonld ?? '' ?>
</head>
<body>
<header class="nano-cart-site-header">
  <a href="<?= htmlspecialchars($shop_path) ?>/"><?= htmlspecialchars(nano_cart_site_name()) ?></a>
</header>
<main class="nano-cart-main">
<?= $nano_cart_content ?? '' ?>
</main>
<footer class="nano-cart-site-footer">
<?= nano_cart_footer_attribution() ?>
</footer>
<script src="<?= htmlspecialchars($assets) ?>/nano-cart.js" defer></script>
</body>
</html>
