<?php
/**
 * Nano Cart - licence management (placeholder).
 *
 * Full Ed25519 licence verification ships in Cart Session 5. This page
 * currently shows a placeholder explaining that.
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/auth.php';
nano_cart_admin_auth_check();

echo nano_cart_admin_header('Licence', 'licence');
?>

<section class="nano-cart-admin-section">
  <h2 class="nano-cart-admin-section-title">Licence management</h2>
  <p>The "Powered by Nano Cart" footer attribution can be suppressed with a paid Digital Fracture licence key. Verification and the form to paste a key here are built in Cart Session 5.</p>
  <p>Until then, the footer renders on every public shop page.</p>
</section>

<?= nano_cart_admin_footer() ?>
