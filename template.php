<?php
if (!defined('NANO_CART_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

/**
 * Per-site HTML wrapper. EDIT PER DEPLOYMENT.
 *
 * Variables available (all already escaped or trusted HTML):
 *   $nano_cart_title    - <title> content
 *   $nano_cart_head     - <link>/<meta>/<script> block (canonical, OG, etc.)
 *   $nano_cart_jsonld   - JSON-LD <script> blocks (may be empty)
 *   $nano_cart_content  - rendered shop page (homepage / category / product)
 *
 * The marked paste-zone sections are intended to receive HTML pasted from
 * the client's existing static site so the shop inherits the host design.
 * Mirror of the Nano CMS template pattern: same family, same flexibility.
 */

$shop_path = nano_cart_shop_path();
$assets    = $shop_path . '/assets';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?= $nano_cart_head ?? '' ?>

  <!--
    SHOP STYLING - choose one mode:
      A) No CSS              - comment out both lines below
      B) Default neutral     - leave the first line uncommented
      C) Default + custom    - uncomment both
    Adjust paths if the shop is installed somewhere other than /shop/.
  -->
  <link rel="stylesheet" href="<?= htmlspecialchars($assets) ?>/nano-cart.css">
  <!-- <link rel="stylesheet" href="<?= htmlspecialchars($assets) ?>/theme-custom.css" /> -->

  <?= $nano_cart_jsonld ?? '' ?>

  <!-- =========================================================== -->
  <!-- BEGIN: paste from client's static site <head> below          -->
  <!-- (link tags for CSS, fonts, favicon, analytics, etc.)         -->
  <!-- =========================================================== -->



  <!-- =========================================================== -->
  <!-- END: client site <head>                                      -->
  <!-- =========================================================== -->
</head>
<body>

  <!-- =========================================================== -->
  <!-- BEGIN: paste client's site header HTML below                 -->
  <!-- =========================================================== -->



  <!-- =========================================================== -->
  <!-- END: client site header                                      -->
  <!-- =========================================================== -->

  <main class="nano-cart-main">
<?= $nano_cart_content ?? '' ?>
<?= nano_cart_footer_attribution() ?>
  </main>

  <!-- =========================================================== -->
  <!-- BEGIN: paste client's site footer HTML below                 -->
  <!-- =========================================================== -->



  <!-- =========================================================== -->
  <!-- END: client site footer                                      -->
  <!-- =========================================================== -->

  <script src="<?= htmlspecialchars($assets) ?>/nano-cart.js" defer></script>
</body>
</html>
