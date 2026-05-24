<?php
/**
 * Nano Cart - admin licence management.
 *
 * Status display + paste-to-verify + save + remove. Operator-facing
 * messages here can be verbose because only the admin sees them; the
 * public frontend stays silent on licence state (footer either shows
 * or doesn't, never a reason).
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/auth.php';
nano_cart_admin_auth_check();

$shop_path = nano_cart_shop_path();
$admin_url = $shop_path . '/admin';
$cfg = nano_cart_load_config();
$current_host = nano_cart_licence_canonical_host();

$errors = [];
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nano_cart_admin_csrf_require();
    $op = (string)($_POST['op'] ?? '');

    if ($op === 'save') {
        $key = trim((string)($_POST['licence_key'] ?? ''));
        if ($key === '') {
            $errors[] = 'Paste a licence key into the box before saving.';
        } else {
            $inspect = nano_cart_licence_inspect($key, $current_host);
            if (!$inspect['ok']) {
                $errors[] = 'Licence rejected: ' . $inspect['reason'];
            } else {
                $cfg['licence_key'] = $key;
                if (nano_cart_admin_save_config($cfg)) {
                    $payload = $inspect['payload'] ?? [];
                    nano_cart_admin_flash_set(
                        'success',
                        'Licence saved. Covers ' . ($payload['domain'] ?? '?') . ' (tier: ' . ($payload['tier'] ?? '?') . ').'
                    );
                    nano_cart_admin_redirect($admin_url . '/licence.php');
                }
                $errors[] = 'Could not write config.json.';
            }
        }
    } elseif ($op === 'remove') {
        $cfg['licence_key'] = '';
        if (nano_cart_admin_save_config($cfg)) {
            nano_cart_admin_flash_set('success', 'Licence removed. Footer attribution will appear on public pages.');
            nano_cart_admin_redirect($admin_url . '/licence.php');
        }
        $errors[] = 'Could not write config.json.';
    }
    $cfg = nano_cart_load_config();
}

$current_key = (string)($cfg['licence_key'] ?? '');
$inspect = $current_key === ''
    ? ['ok' => false, 'reason' => null, 'payload' => null]
    : nano_cart_licence_inspect($current_key, $current_host);
$is_dev = $current_host !== '' && nano_cart_is_dev_host($current_host);

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

echo nano_cart_admin_header('Licence', 'licence');
echo nano_cart_admin_flash_html();
?>

<?php if (!empty($errors)): ?>
<div class="nano-cart-admin-flash nano-cart-admin-flash-error">
  <ul><?php foreach ($errors as $e): ?><li><?= $h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<section class="nano-cart-admin-section">
  <h2 class="nano-cart-admin-section-title">Current status</h2>
  <p class="nano-cart-admin-help">Site is verified against <code><?= $h($current_host ?: '(site_url not set)') ?></code> derived from <code>site_url</code> in config.</p>

<?php if ($is_dev): ?>
  <p>Development host: licence check is bypassed locally. The footer is hidden regardless of licence state. On a real host (no port, not <code>.test</code> / <code>.local</code> / <code>localhost</code>), the rules below apply.</p>
<?php endif; ?>

<?php if ($current_key === ''): ?>
  <p><strong>No licence active.</strong> The "Powered by Nano Cart" attribution appears in the public shop footer.</p>
<?php elseif ($inspect['ok']): ?>
  <p><strong>Licensed.</strong> Covers <code><?= $h((string)($inspect['payload']['domain'] ?? '?')) ?></code> (tier: <code><?= $h((string)($inspect['payload']['tier'] ?? '?')) ?></code>). Footer attribution is hidden on public pages.</p>
<?php else: ?>
  <p><strong>Licence present but not valid.</strong> Footer attribution still appears. Reason: <?= $h((string)$inspect['reason']) ?></p>
<?php endif; ?>
</section>

<section class="nano-cart-admin-section">
  <h2 class="nano-cart-admin-section-title">Apply a licence key</h2>
  <form method="post" class="nano-cart-admin-form" autocomplete="off">
    <?= nano_cart_admin_csrf_field() ?>
    <input type="hidden" name="op" value="save">
    <label>
      Licence key (paste the full <code>base64.base64</code> string)
      <textarea name="licence_key" rows="4" required><?= $h($current_key) ?></textarea>
    </label>
    <div class="nano-cart-admin-form-actions">
      <button type="submit" class="nano-cart-admin-button nano-cart-admin-button-primary">Verify and save</button>
    </div>
  </form>
</section>

<?php if ($current_key !== ''): ?>
<section class="nano-cart-admin-section">
  <h2 class="nano-cart-admin-section-title">Remove licence</h2>
  <form method="post" class="nano-cart-admin-form">
    <?= nano_cart_admin_csrf_field() ?>
    <input type="hidden" name="op" value="remove">
    <p>Clears <code>licence_key</code> from <code>config.json</code>. The footer attribution will appear on public pages until a valid licence is saved again.</p>
    <div class="nano-cart-admin-form-actions">
      <button type="submit" class="nano-cart-admin-button nano-cart-admin-button-danger">Remove licence</button>
    </div>
  </form>
</section>
<?php endif; ?>

<section class="nano-cart-admin-section">
  <h2 class="nano-cart-admin-section-title">Buy a licence</h2>
  <p>Purchase a per-domain perpetual licence at <a href="https://digitalfracture.co.uk/nano-cart.html" target="_blank" rel="noopener">digitalfracture.co.uk/nano-cart.html</a>.</p>
  <ul>
    <li>Single domain: &pound;29</li>
    <li>3-domain agency pack: &pound;69</li>
    <li>Unlimited agency (wildcard): &pound;249</li>
  </ul>
</section>

<?= nano_cart_admin_footer() ?>
