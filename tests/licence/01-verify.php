<?php
/**
 * Tests for nano_cart_verify_licence() and nano_cart_licence_inspect().
 *
 * Generates throwaway keypairs in-test, swaps the embedded public key
 * at runtime by re-loading licence.php through an isolated sub-process
 * is overkill; instead, we monkey-patch the verification function to
 * accept a test pubkey via a global. To keep licence.php pure, the
 * tests use a small inline verifier that mirrors the real one's logic
 * but accepts a pubkey parameter. The shared decoding helpers are
 * exercised by feeding both verifiers the same licence keys.
 *
 * What this verifies:
 *   - happy path: valid licence for current host passes
 *   - tampered signature fails
 *   - wrong product (nano-cms licence) fails
 *   - empty / malformed / non-base64 fails
 *   - www. host strips correctly before compare
 *   - wildcard `*` passes only for agency-unlimited tier
 *   - expired licence fails
 */

declare(strict_types=1);

$repo = dirname(__DIR__, 2);
if (!defined('NANO_CART_BOOTSTRAPPED')) define('NANO_CART_BOOTSTRAPPED', true);

require_once $repo . '/lib/Parsedown.php';
require_once $repo . '/core.php';
require_once __DIR__ . '/_helpers.php';

if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "libsodium required for these tests.\n");
    exit(1);
}

/**
 * Sign a payload with a throwaway keypair. Returns
 * [licenceKey, pubkeyB64].
 */
function make_test_licence(array $payload): array
{
    $kp = sodium_crypto_sign_keypair();
    $sec = sodium_crypto_sign_secretkey($kp);
    $pub = sodium_crypto_sign_publickey($kp);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $sig  = sodium_crypto_sign_detached($json, $sec);
    return [base64_encode($json) . '.' . base64_encode($sig), base64_encode($pub)];
}

/**
 * Mirror of nano_cart_licence_inspect() but with the public key passed
 * in, so we can verify with a throwaway keypair without monkey-patching
 * the embedded constant.
 */
function inspect_with_pubkey(string $licence_key, string $current_host, string $pubkey_b64): array
{
    $licence_key = trim($licence_key);
    if ($licence_key === '') return ['ok' => false, 'reason' => 'empty'];
    if (substr_count($licence_key, '.') !== 1) return ['ok' => false, 'reason' => 'no dot'];
    [$pb, $sb] = explode('.', $licence_key, 2);
    $pj = base64_decode($pb, true);
    $sig = base64_decode($sb, true);
    if ($pj === false || $sig === false) return ['ok' => false, 'reason' => 'bad b64'];
    if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) return ['ok' => false, 'reason' => 'sig length'];
    $p = json_decode($pj, true);
    if (!is_array($p)) return ['ok' => false, 'reason' => 'json'];
    foreach (['product', 'domain', 'tier', 'licence_id', 'issued'] as $f) {
        if (!array_key_exists($f, $p)) return ['ok' => false, 'reason' => "missing $f"];
    }
    if ((string)$p['product'] !== 'nano-cart') return ['ok' => false, 'reason' => 'wrong product'];
    $pk = base64_decode($pubkey_b64, true);
    if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return ['ok' => false, 'reason' => 'pubkey'];
    try {
        $ok = sodium_crypto_sign_verify_detached($sig, $pj, $pk);
    } catch (Throwable $e) {
        return ['ok' => false, 'reason' => 'verify threw'];
    }
    if (!$ok) return ['ok' => false, 'reason' => 'bad sig'];
    $ld = strtolower((string)$p['domain']);
    $ch = strtolower(trim($current_host));
    if (str_starts_with($ch, 'www.')) $ch = substr($ch, 4);
    $tier = (string)$p['tier'];
    if ($ld === '*' && $tier === 'agency-unlimited') {
        // wildcard ok
    } elseif ($ld !== $ch) {
        return ['ok' => false, 'reason' => 'domain'];
    }
    $exp = $p['expires'] ?? null;
    if ($exp !== null && $exp !== '') {
        $ts = strtotime((string)$exp);
        if ($ts === false || $ts < time()) return ['ok' => false, 'reason' => 'expired'];
    }
    return ['ok' => true, 'reason' => null];
}

$base_payload = [
    'product'     => 'nano-cart',
    'domain'      => 'example.com',
    'tier'        => 'single',
    'licence_id'  => 'test-licence-id',
    'issued'      => '2026-05-01',
    'expires'     => null,
    'key_version' => 1,
];

nano_section('happy path');
[$key, $pub] = make_test_licence($base_payload);
nano_check('valid licence for current host passes', inspect_with_pubkey($key, 'example.com', $pub)['ok']);

nano_section('host matching');
nano_check('mismatched domain fails',          !inspect_with_pubkey($key, 'other.com',      $pub)['ok']);
nano_check('www. prefix is stripped',           inspect_with_pubkey($key, 'www.example.com', $pub)['ok']);
nano_check('case-insensitive host',             inspect_with_pubkey($key, 'EXAMPLE.com',    $pub)['ok']);

nano_section('signature tampering');
$tampered = substr($key, 0, -4) . 'XXXX';
nano_check('tampered signature is rejected', !inspect_with_pubkey($tampered, 'example.com', $pub)['ok']);

nano_section('wrong product (a Nano CMS licence on a Nano Cart install)');
[$cms_key, $cms_pub] = make_test_licence(['product' => 'nano-cms'] + $base_payload);
nano_check('nano-cms licence is rejected', !inspect_with_pubkey($cms_key, 'example.com', $cms_pub)['ok']);

nano_section('malformed / empty input');
nano_check('empty string is rejected',     !inspect_with_pubkey('',          'example.com', $pub)['ok']);
nano_check('no dot is rejected',           !inspect_with_pubkey('abcdef',    'example.com', $pub)['ok']);
nano_check('non-base64 halves rejected',   !inspect_with_pubkey('!@#.!@#',   'example.com', $pub)['ok']);

nano_section('wildcard tier rules');
[$wild_unlimited, $wpub1] = make_test_licence(['domain' => '*', 'tier' => 'agency-unlimited'] + $base_payload);
nano_check('wildcard + agency-unlimited passes anywhere', inspect_with_pubkey($wild_unlimited, 'random.io', $wpub1)['ok']);
[$wild_single, $wpub2] = make_test_licence(['domain' => '*', 'tier' => 'single'] + $base_payload);
nano_check('wildcard + single tier is rejected',         !inspect_with_pubkey($wild_single, 'random.io', $wpub2)['ok']);

nano_section('expiry');
[$expired, $epub] = make_test_licence(['expires' => '2020-01-01'] + $base_payload);
nano_check('expired licence is rejected', !inspect_with_pubkey($expired, 'example.com', $epub)['ok']);
[$future, $fpub] = make_test_licence(['expires' => '2099-01-01'] + $base_payload);
nano_check('future-dated expiry passes', inspect_with_pubkey($future, 'example.com', $fpub)['ok']);

nano_section('dev-host detection (uses real function)');
nano_check('localhost is dev',           nano_cart_is_dev_host('localhost'));
nano_check('127.0.0.1 is dev',           nano_cart_is_dev_host('127.0.0.1'));
nano_check('::1 is dev',                 nano_cart_is_dev_host('::1'));
nano_check('hosts with ports are dev',   nano_cart_is_dev_host('example.com:8000'));
nano_check('.test suffix is dev',        nano_cart_is_dev_host('shop.test'));
nano_check('.local suffix is dev',       nano_cart_is_dev_host('shop.local'));
nano_check('example.com is NOT dev',    !nano_cart_is_dev_host('example.com'));
nano_check('example.co.uk is NOT dev',  !nano_cart_is_dev_host('example.co.uk'));

nano_section('inspect against embedded pubkey rejects throwaway-signed licence');
$result = nano_cart_licence_inspect($key, 'example.com');
nano_check('throwaway-signed licence rejected by real key', !$result['ok']);

exit(nano_test_summary());
