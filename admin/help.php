<?php
/**
 * Admin help page. Reference card for product fields, image handling,
 * media uploads, and deployment expectations. No state, no forms - just
 * static content gated behind the admin login. Mirrors the Nano CMS help
 * page so the two admins are laid out identically.
 */
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/auth.php';

if (!defined('NANO_CART_CONFIG_PATH') || !is_file(NANO_CART_CONFIG_PATH)) {
    nano_cart_admin_redirect('setup.php');
}

nano_cart_admin_auth_check();

$cfg = nano_cart_load_config();
$h   = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

echo nano_cart_admin_header('Help', 'help');
echo nano_cart_admin_flash_html();
?>
<section class="nano-cart-admin-section">
<h2 class="nano-cart-admin-section-title">Product fields</h2>
<table class="nano-cart-admin-table">
<tr><th>Field</th><th>Required</th><th>Notes</th></tr>
<tr><td><code>SKU</code></td><td>yes</td><td>Lowercase URL slug, <code>[a-z0-9-]+</code>. Authoritative and permanent - it is the filename and cannot be changed after creation.</td></tr>
<tr><td><code>Title</code></td><td>yes</td><td>Product name. Used in the page <code>&lt;title&gt;</code> and the product heading.</td></tr>
<tr><td><code>Short description</code></td><td>yes</td><td>Up to 300 chars. Shown on product cards and used as the meta description.</td></tr>
<tr><td><code>Long description</code></td><td>yes</td><td>The full product body. Markdown, rendered in safe mode (raw HTML stripped).</td></tr>
<tr><td><code>Category</code></td><td>yes</td><td>One category, chosen from the categories you have created.</td></tr>
<tr><td><code>Price display</code></td><td>yes</td><td>Free-form price text, e.g. <code>&pound;49</code> or <code>From &pound;49</code>. Shown verbatim.</td></tr>
<tr><td><code>Checkout URL</code></td><td>yes</td><td>Must be <code>https://</code>. Where the Buy / Add button sends the customer.</td></tr>
<tr><td><code>Featured</code> / <code>Hero featured</code></td><td>no</td><td>Promote the product on listing pages / the homepage hero.</td></tr>
<tr><td><code>Sort order</code></td><td>no</td><td>Lower numbers sort first within a category. Blank sorts by most recent.</td></tr>
<tr><td><code>Status</code></td><td>yes</td><td><code>Draft</code> hides the product from the shop; <code>Published</code> makes it live.</td></tr>
</table>
</section>

<section class="nano-cart-admin-section">
<h2 class="nano-cart-admin-section-title">Per-product image control</h2>
<p>Each product carries its own image settings - there is no single global crop that mangles every picture. Set these on the product editor, per image:</p>
<table class="nano-cart-admin-table">
<tr><th>Setting</th><th>Notes</th></tr>
<tr><td><code>Width</code> / <code>Height</code></td><td>The display box for the card image. The browser fits the picture inside this box using the Fit mode below.</td></tr>
<tr><td><code>Fit: cover</code></td><td>Fills the whole box and crops the overflow. Best for photos where edge loss is acceptable.</td></tr>
<tr><td><code>Fit: contain</code></td><td>Shows the <em>entire</em> image inside the box with no cropping. Any leftover space shows the background colour. Best for logos, packshots, and anything that must not be cut.</td></tr>
<tr><td><code>Background</code></td><td>Hex colour (e.g. <code>#1d0a3e</code>) shown behind a <code>contain</code> image or behind transparency. Leave blank for none.</td></tr>
</table>
<p class="nano-cart-admin-help">Resized variants are generated on demand and cached - changing a product's width/height/fit just changes how the same uploaded file is displayed, so you can tune any image at any time without re-uploading.</p>
</section>

<section class="nano-cart-admin-section">
<h2 class="nano-cart-admin-section-title">Recommended image sizes</h2>
<ul>
<li><strong>Upload large, display small.</strong> Upload a generous source (e.g. 1200&times;1200 or larger); the shop downscales to the display box you set per product. It never upscales, so a small source stays small.</li>
<li><strong>Square or 3:2 sources are the safe default.</strong> They fit most card boxes with the least surprise under <code>cover</code>.</li>
<li><strong>Use <code>contain</code> for anything that must show in full</strong> - product packaging, logos, screenshots - and pick a background colour that suits the artwork.</li>
<li><strong>JPG for photos, PNG for graphics with transparency, WebP for smaller files.</strong> Uploads are re-encoded on the server, so source compression does not matter.</li>
</ul>
</section>

<section class="nano-cart-admin-section">
<h2 class="nano-cart-admin-section-title">Media uploads</h2>
<ul>
<li>Allowed types: <code>jpg</code>, <code>jpeg</code>, <code>png</code>, <code>gif</code>, <code>webp</code>. Nothing else.</li>
<li>Every upload is decoded and re-encoded through GD (or Imagick) to strip any embedded payload. If neither extension is available on the server, uploads are refused.</li>
<li>Product images live under <code>/media/product-images/&lt;sku&gt;/</code>; resized variants are written to <code>/media/img/</code> on first request and cached.</li>
</ul>
</section>

<section class="nano-cart-admin-section">
<h2 class="nano-cart-admin-section-title">Deployment notes</h2>
<ul>
<li><strong>HTTPS is mandatory.</strong> The admin refuses to load over HTTP.</li>
<li><strong>Config lives outside webroot.</strong> <code>config.json</code> sits at the path declared by <code>bootstrap.php</code> and contains the password hash and shop settings.</li>
<li><strong>Remove the admin folder when done.</strong> Upload via SFTP to publish, work, then delete the entire <code>/admin/</code> tree. The shop keeps serving the same products after the admin is gone.</li>
<li><strong>Backups: rsync the <code>/products/</code>, <code>/categories/</code> and <code>/media/</code> directories.</strong> The whole shop is on disk - there is no database.</li>
</ul>
</section>

<p class="nano-cart-admin-help">Nano Cart <?= $h('v' . (defined('NANO_CART_VERSION') ? NANO_CART_VERSION : '?')) ?> &middot; format version <?= $h((string)($cfg['format_version'] ?? '?')) ?>.</p>

<?= nano_cart_admin_footer() ?>
