<?php
/**
 * Nano Cart - admin logout.
 *
 * Destroys the session and redirects to the login form.
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/auth.php';

nano_cart_admin_logout();
nano_cart_admin_redirect(nano_cart_shop_path() . '/admin/login.php');
