<?php
/**
 * Nano Cart - first-time web installer.
 *
 * Lives at /shop/install.php. Detects whether the install is already
 * configured (bootstrap.php exists). If not, creates the outside-webroot
 * config directory, writes bootstrap.php with absolute paths, and
 * redirects to the admin setup wizard.
 *
 * Operator deletes this file after install (same pattern as the admin
 * folder). The "delete me" reminder is shown prominently on the success
 * page; the script also refuses to run once bootstrap.php exists, so a
 * forgotten install.php cannot reconfigure a live shop.
 */

$shop_dir   = __DIR__;
$bootstrap  = $shop_dir . '/bootstrap.php';
$self_file  = __FILE__;
$admin_url  = 'admin/';

/**
 * Pick a sensible default for the outside-webroot config directory.
 *
 * The naive choice "sibling of /shop/" works on stock hosting where the
 * webroot is e.g. /var/www/html and shop/ sits inside it. On cPanel /
 * Plesk setups with addon domains, the webroot IS one level under
 * /home/<user>/ (e.g. /home/xpressdr/nanocart.co.uk/), so "sibling of
 * shop/" lands inside the webroot, which is exactly what we are trying
 * to avoid.
 *
 * Strategy: take DOCUMENT_ROOT as authoritative if present, go one
 * level above it. Fall back to dirname(__DIR__) only when DOCUMENT_ROOT
 * is missing or matches __DIR__ exactly (the installer is in the
 * webroot itself, in which case dirname is still correct).
 */
function nano_install_default_cfg_dir(string $shop_dir): string
{
    $name = 'nano-shop-config';
    $docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', (string)$_SERVER['DOCUMENT_ROOT']), '/') : '';
    $shop_norm = rtrim(str_replace('\\', '/', $shop_dir), '/');
    $parent = dirname($shop_norm);
    if ($docroot !== '' && (str_starts_with($shop_norm, $docroot . '/') || $shop_norm === $docroot)) {
        // The shop is inside DOCUMENT_ROOT. Go one level ABOVE the
        // document root to be safely outside.
        return dirname($docroot) . DIRECTORY_SEPARATOR . $name;
    }
    return $parent . DIRECTORY_SEPARATOR . $name;
}

$default_cfg_dir = nano_install_default_cfg_dir($shop_dir);

/**
 * True if the given path would be web-accessible (lives inside
 * DOCUMENT_ROOT). Used to warn the operator if they enter a path
 * that defeats the "config outside webroot" guarantee.
 */
function nano_install_is_inside_docroot(string $path): bool
{
    $docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', (string)$_SERVER['DOCUMENT_ROOT']), '/') : '';
    if ($docroot === '') return false;
    $p = rtrim(str_replace('\\', '/', $path), '/');
    return $p === $docroot || str_starts_with($p, $docroot . '/');
}

function nano_install_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function nano_install_page(string $title, string $body, string $extra_head = ''): void
{
    $title_h = nano_install_h($title);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow">'
       . '<title>' . $title_h . ' - Nano Cart install</title>'
       . '<style>'
       . 'body{font-family:system-ui,-apple-system,sans-serif;max-width:42em;margin:2em auto;padding:0 1em;color:#1f2328;line-height:1.55}'
       . 'h1{font-size:1.5em;margin:0 0 1em}h2{font-size:1.1em;margin:1.5em 0 .5em}'
       . 'code,pre{background:#f6f8fa;border-radius:4px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.92em}'
       . 'code{padding:.1em .35em}pre{padding:.75em 1em;overflow:auto}'
       . 'label{display:block;margin:1em 0;font-weight:500}'
       . 'input[type=text]{display:block;width:100%;padding:.55em .7em;font-family:inherit;font-size:1em;border:1px solid #d0d7de;border-radius:4px;margin-top:.35em}'
       . '.btn{display:inline-block;padding:.6em 1.2em;background:#1f6feb;color:#fff;border:1px solid #1f6feb;border-radius:4px;text-decoration:none;cursor:pointer;font:inherit}'
       . '.btn:hover{background:#0a4fc4}'
       . '.btn-secondary{background:#fff;color:#1f2328;border-color:#d0d7de}'
       . '.danger{background:#ffebe9;border:1px solid #82071e;color:#82071e;padding:1em;border-radius:4px;margin:1em 0}'
       . '.success{background:#dafbe1;border:1px solid #1a7f37;color:#1a7f37;padding:1em;border-radius:4px;margin:1em 0}'
       . '.warning{background:#fff8c5;border:1px solid #9a6700;color:#7d4e00;padding:1em;border-radius:4px;margin:1em 0}'
       . '.meta{color:#57606a;font-size:.85em}'
       . $extra_head
       . '</style></head><body>'
       . '<h1>Nano Cart install: ' . $title_h . '</h1>'
       . $body
       . '<p class="meta">install.php should be deleted from /shop/ after a successful install. Re-uploading is only needed to reinstall on a fresh install.</p>'
       . '</body></html>';
}

/* ----------------------------------------------------------------------- */
/* Self-delete action (POST action=delete)                                  */
/* ----------------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $deleted = @unlink($self_file);
    if ($deleted) {
        // After delete, send the operator to /admin/ which routes
        // correctly based on state (dashboard if logged in, login if
        // config exists, setup if not). Don't second-guess the state
        // from install.php since by the time delete happens, setup is
        // typically already complete (the dashboard banner is the
        // primary entry point to this action).
        nano_install_page(
            'install.php removed',
            '<div class="success"><p><strong>install.php deleted from the server.</strong></p></div>'
            . (file_exists($bootstrap)
                ? '<p><a class="btn" href="' . nano_install_h($admin_url) . '">Go to admin</a></p>'
                : '<p>You can now configure the shop. Re-upload install.php from the release zip if you want to run it again later.</p>')
        );
    } else {
        nano_install_page(
            'cannot delete',
            '<div class="danger"><p>PHP could not delete <code>install.php</code> on this host. Some shared hosts block scripts from unlinking themselves.</p>'
            . '<p>Please remove it manually:</p>'
            . '<ul>'
            . '<li><strong>cPanel:</strong> File Manager &rarr; navigate to <code>/shop/</code> &rarr; right-click <code>install.php</code> &rarr; Delete</li>'
            . '<li><strong>SFTP client</strong> (FileZilla, Cyberduck, WinSCP): connect, navigate to <code>/shop/</code>, right-click <code>install.php</code> &rarr; Delete</li>'
            . '<li><strong>SSH:</strong> <code>rm ' . nano_install_h($self_file) . '</code></li>'
            . '</ul></div>'
            . (file_exists($bootstrap)
                ? '<p><a class="btn btn-secondary" href="' . nano_install_h($admin_url) . '">Go to admin</a></p>'
                : '')
        );
    }
    exit;
}

$delete_form = '<form method="post" style="display:inline">'
    . '<input type="hidden" name="action" value="delete">'
    . '<button class="btn btn-secondary" type="submit" onclick="return confirm(\'Delete install.php from the server now?\')">Delete install.php</button>'
    . '</form>';

/* ----------------------------------------------------------------------- */
/* Already configured? Bail.                                                */
/* ----------------------------------------------------------------------- */

if (file_exists($bootstrap)) {
    nano_install_page(
        'already configured',
        '<div class="warning"><p><strong>This shop is already configured.</strong> <code>bootstrap.php</code> exists. Re-running the installer is not allowed because it would overwrite the live configuration.</p></div>'
        . '<p>If you want to reconfigure from scratch, delete <code>bootstrap.php</code> AND the config directory (the one referenced inside <code>bootstrap.php</code>), then reload this page.</p>'
        . '<p><strong>You should delete this <code>install.php</code> file now.</strong> It served its purpose; leaving it on the server is a small fingerprinting risk.</p>'
        . '<p><a class="btn" href="' . nano_install_h($admin_url) . '">Go to admin</a> ' . $delete_form . '</p>'
    );
    exit;
}

/* ----------------------------------------------------------------------- */
/* POST: try to install                                                     */
/* ----------------------------------------------------------------------- */

$errors = [];
$cfg_dir = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cfg_dir = rtrim(str_replace('\\', '/', trim((string)($_POST['cfg_dir'] ?? ''))), '/');

    if ($cfg_dir === '') {
        $errors[] = 'Config directory path is required.';
    } elseif (str_contains($cfg_dir, '..')) {
        $errors[] = 'Path may not contain "..".';
    } elseif (str_starts_with($cfg_dir, $shop_dir . '/') || $cfg_dir === $shop_dir) {
        $errors[] = 'Config directory must be OUTSIDE the shop directory (that is the point).';
    } elseif (nano_install_is_inside_docroot($cfg_dir)) {
        $errors[] = 'Config directory <code>' . nano_install_h($cfg_dir) . '</code> is inside the webroot (<code>' . nano_install_h((string)$_SERVER['DOCUMENT_ROOT']) . '</code>). It would be web-accessible, defeating the whole point of an outside-webroot config. Try a path ABOVE the webroot, e.g. <code>' . nano_install_h(dirname((string)$_SERVER['DOCUMENT_ROOT']) . '/nano-shop-config') . '</code>.';
    } else {
        // Try to create the directory.
        if (!is_dir($cfg_dir)) {
            if (!@mkdir($cfg_dir, 0750, true)) {
                $errors[] = "Could not create directory <code>" . nano_install_h($cfg_dir) . "</code>. "
                    . "PHP probably lacks permission to write to its parent. "
                    . "Create the directory manually, run <code>chmod 750 " . nano_install_h($cfg_dir) . "</code>, "
                    . "then reload this page.";
            }
        }
        // Try to write a test file to confirm writability.
        if (empty($errors)) {
            $test = $cfg_dir . '/.write-test';
            if (@file_put_contents($test, 'ok') === false) {
                $errors[] = "Directory <code>" . nano_install_h($cfg_dir) . "</code> exists but PHP cannot write to it. "
                    . "Run <code>chown</code> to set ownership to the PHP user (typically <code>www-data</code> "
                    . "or your host's PHP user) and <code>chmod 750</code>, then reload.";
            } else {
                @unlink($test);
                @chmod($cfg_dir, 0750);
            }
        }
        // Write bootstrap.php.
        if (empty($errors)) {
            $cfg_dir_php = var_export($cfg_dir, true);
            $bootstrap_contents = "<?php\n"
                . "// Generated by install.php on " . gmdate('Y-m-d\TH:i:s\Z') . " UTC.\n"
                . "// Edit the paths below if you ever move the config directory.\n\n"
                . "\$cfg_dir = " . $cfg_dir_php . ";\n\n"
                . "define('NANO_CART_CONFIG_PATH',     \$cfg_dir . '/config.json');\n"
                . "define('NANO_CART_RATE_LIMIT_PATH', \$cfg_dir . '/rate-limit.json');\n"
                . "define('NANO_CART_PRODUCTS_PATH',   __DIR__ . '/products');\n"
                . "define('NANO_CART_CATEGORIES_PATH', __DIR__ . '/categories');\n"
                . "define('NANO_CART_MEDIA_PATH',      __DIR__ . '/media');\n\n"
                . "define('NANO_CART_BOOTSTRAPPED', true);\n"
                . "require __DIR__ . '/core.php';\n";
            if (@file_put_contents($bootstrap, $bootstrap_contents) === false) {
                $errors[] = "Could not write <code>bootstrap.php</code> in the shop directory. "
                    . "Check that PHP can write to <code>" . nano_install_h($shop_dir) . "</code>.";
            } else {
                @chmod($bootstrap, 0640);
            }
        }
        if (empty($errors)) {
            // Success - render the success page and stop.
            nano_install_page(
                'install complete',
                '<div class="success"><p><strong>Installed.</strong> <code>bootstrap.php</code> is in place and points at <code>' . nano_install_h($cfg_dir) . '</code>.</p></div>'
                . '<div class="danger"><p><strong>Delete this <code>install.php</code> file now.</strong> SFTP into the shop directory and remove it. The installer should not stay on the server.</p></div>'
                . '<h2>Next step</h2>'
                . '<p><a class="btn" href="admin/setup.php">Open setup wizard</a></p>'
                . '<p>The setup wizard creates the operator password and shop settings. <strong>Do not delete install.php yet</strong>; the admin dashboard will offer to delete it once setup completes successfully.</p>'
                . '<h2>What just happened</h2>'
                . '<ul>'
                . '<li>Created <code>' . nano_install_h($cfg_dir) . '</code> (mode 0750) for outside-webroot config.</li>'
                . '<li>Wrote <code>bootstrap.php</code> in the shop directory pointing at it.</li>'
                . '<li>Did NOT create <code>config.json</code> yet; that happens when you complete the setup wizard.</li>'
                . '</ul>'
            );
            exit;
        }
    }
}

/* ----------------------------------------------------------------------- */
/* GET (or POST with errors): show the form                                 */
/* ----------------------------------------------------------------------- */

if ($cfg_dir === '') $cfg_dir = $default_cfg_dir;

$parent_writable = is_writable(dirname($cfg_dir));
$php_user = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown')
    : (getenv('USERNAME') ?: getenv('USER') ?: 'unknown');

$error_html = '';
if (!empty($errors)) {
    $error_html = '<div class="danger"><ul>';
    foreach ($errors as $e) {
        $error_html .= '<li>' . $e . '</li>';
    }
    $error_html .= '</ul></div>';
}

$writable_hint = $parent_writable
    ? '<p class="meta">Parent directory <code>' . nano_install_h(dirname($cfg_dir)) . '</code> appears writable by PHP. The installer should be able to create the config directory automatically.</p>'
    : '<div class="warning"><p>Parent directory <code>' . nano_install_h(dirname($cfg_dir)) . '</code> is NOT writable by PHP (running as <code>' . nano_install_h($php_user) . '</code>). You may need to either:</p>'
      . '<ul>'
      . '<li>Create the config directory manually first, then make it writable: <code>mkdir ' . nano_install_h($cfg_dir) . ' && chmod 750 ' . nano_install_h($cfg_dir) . '</code></li>'
      . '<li>Or chmod the parent so PHP can create it: <code>chmod 750 ' . nano_install_h(dirname($cfg_dir)) . '</code></li>'
      . '</ul></div>';

$docroot_warning = '';
if (nano_install_is_inside_docroot($cfg_dir)) {
    $alt = dirname((string)$_SERVER['DOCUMENT_ROOT']) . '/nano-shop-config';
    $docroot_warning = '<div class="danger"><p><strong>Warning:</strong> the path <code>' . nano_install_h($cfg_dir) . '</code> is INSIDE the webroot (<code>' . nano_install_h((string)$_SERVER['DOCUMENT_ROOT']) . '</code>). A web-accessible config directory would leak your password hash and licence key.</p>'
        . '<p>On cPanel / Plesk hosting where addon domains live under <code>/home/&lt;user&gt;/&lt;domain&gt;/</code>, use a sibling of the webroot like <code>' . nano_install_h($alt) . '</code> instead.</p></div>';
}

$body = $error_html
    . '<p>Nano Cart needs a config directory <strong>outside the webroot</strong> for its <code>config.json</code> (admin password hash, licence key) and <code>rate-limit.json</code> (per-IP login backoff state). Web-accessible config would leak credentials.</p>'
    . '<p>The installer will create that directory and write <code>bootstrap.php</code> for you, then hand off to the admin setup wizard.</p>'
    . $docroot_warning
    . $writable_hint
    . '<form method="post">'
    . '<label>Config directory (absolute path)'
    . '<input type="text" name="cfg_dir" required value="' . nano_install_h($cfg_dir) . '">'
    . '</label>'
    . '<p class="meta">Default is a sibling of <code>/shop/</code>, which keeps it tidy and ensures it is not web-accessible.</p>'
    . '<button class="btn" type="submit">Install</button>'
    . '</form>'
    . '<h2>What if this fails?</h2>'
    . '<p>If the installer cannot create the directory, follow the fallback steps in <a href="https://github.com/digifrac/Nano-Cart/blob/main/INSTALL.md">INSTALL.md</a>: create the directory by hand via SFTP, copy <code>bootstrap.example.php</code> to <code>bootstrap.php</code>, edit the two outside-webroot path constants, then visit <code>admin/setup.php</code> directly.</p>';

nano_install_page('configure', $body);
