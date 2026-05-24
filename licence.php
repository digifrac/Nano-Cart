<?php
/**
 * Nano Cart - licence verification.
 *
 * Loaded by core.php. Verifies customer licence keys against the
 * embedded Digital Fracture public key using Ed25519. Mirrors the
 * Nano CMS pattern; only the expected `product` field in the payload
 * is different ("nano-cart" instead of "nano-cms").
 *
 * No network calls. No phone-home. All verification is local. See the
 * private nano-licence-tools repo for the generator and signing key.
 */

if (!defined('NANO_CART_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

/**
 * Digital Fracture master public key (Ed25519, base64-encoded). Shared
 * with Nano CMS; only the signed payload's `product` field distinguishes
 * which product a given licence is valid for.
 *
 * Safe to ship in MIT-licensed code: this is the verification half of
 * the keypair. Only the matching private key (held offline in
 * nano-licence-tools) can mint a valid licence. The `_V1` suffix leaves
 * room for rotation if the private key is ever compromised.
 */
const NANO_CART_LICENCE_PUBKEY_V1 = 'OW0ZWPowsYFF4Hv49r8Kc8OcM31COddoOk5j1UVCWfY=';

/* ----------------------------------------------------------------------- */
/* Canonical host (from config site_url, not HTTP_HOST)                     */
/* ----------------------------------------------------------------------- */

/**
 * Return the host the licence is bound to, derived from `site_url` in
 * config.json. Never reads `$_SERVER['HTTP_HOST']`: the request header
 * is attacker-controlled and would otherwise allow Host-spoof bypass
 * or reverse-proxy cache-poisoning of the licence check.
 *
 * Returns '' on any failure. Callers treat '' as "do not verify".
 */
function nano_cart_licence_canonical_host(): string
{
    try {
        $cfg = nano_cart_load_config();
    } catch (Throwable $e) {
        return '';
    }
    $base = (string)($cfg['site_url'] ?? '');
    if ($base === '') return '';

    $parts = parse_url($base);
    $host = $parts['host'] ?? null;
    if (!is_string($host) || $host === '') return '';
    $host = strtolower($host);

    // Preserve non-default port so nano_cart_is_dev_host() can still see
    // the dev-shape marker on URLs like http://example.com:8000/shop.
    $port = isset($parts['port']) ? (int)$parts['port'] : 0;
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $default_port = $scheme === 'http' ? 80 : ($scheme === 'https' ? 443 : 0);
    if ($port > 0 && $port !== $default_port) {
        return $host . ':' . $port;
    }
    return $host;
}

/* ----------------------------------------------------------------------- */
/* Dev-host detection                                                        */
/* ----------------------------------------------------------------------- */

/**
 * Return true for hosts that bypass the licence check entirely.
 *   - localhost / 127.0.0.1 / ::1 (exact, with or without brackets)
 *   - any host containing a port (colon in the value)
 *   - *.test       (RFC 6761 reserved for testing)
 *   - *.local      (mDNS reserved local domain)
 */
function nano_cart_is_dev_host(string $host): bool
{
    $host = strtolower(trim($host));
    if ($host === '') return true;
    if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || $host === '[::1]') {
        return true;
    }
    if (strpos($host, ':') !== false) return true;
    foreach (['.test', '.local'] as $suffix) {
        if (substr($host, -strlen($suffix)) === $suffix) return true;
    }
    return false;
}

/* ----------------------------------------------------------------------- */
/* Licence verification                                                      */
/* ----------------------------------------------------------------------- */

/**
 * Thin wrapper around nano_cart_licence_inspect() that returns just the
 * boolean. Use this in render paths; use the inspector for admin UI.
 */
function nano_cart_verify_licence(string $licence_key, string $current_host): bool
{
    return nano_cart_licence_inspect($licence_key, $current_host)['ok'];
}

/**
 * Detailed verification result.
 *
 * Returns ['ok' => bool, 'reason' => ?string, 'payload' => ?array].
 * `payload` is populated whenever the licence parses, even if a later
 * check fails, so the admin can show "your licence covers X, this site
 * runs on Y" without re-decoding.
 */
function nano_cart_licence_inspect(string $licence_key, string $current_host): array
{
    $licence_key = trim($licence_key);
    if ($licence_key === '') {
        return ['ok' => false, 'reason' => 'No licence key set.', 'payload' => null];
    }
    if (substr_count($licence_key, '.') !== 1) {
        return ['ok' => false, 'reason' => 'Malformed licence key (expected base64.base64).', 'payload' => null];
    }

    [$payload_b64, $signature_b64] = explode('.', $licence_key, 2);
    $payload_json = base64_decode($payload_b64, true);
    $signature    = base64_decode($signature_b64, true);
    if ($payload_json === false || $signature === false) {
        return ['ok' => false, 'reason' => 'Licence key contains invalid base64.', 'payload' => null];
    }
    if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
        return ['ok' => false, 'reason' => 'Signature length is wrong.', 'payload' => null];
    }

    $payload = json_decode($payload_json, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'reason' => 'Licence payload is not valid JSON.', 'payload' => null];
    }

    foreach (['product', 'domain', 'tier', 'licence_id', 'issued'] as $field) {
        if (!array_key_exists($field, $payload)) {
            return ['ok' => false, 'reason' => "Licence payload missing field '$field'.", 'payload' => $payload];
        }
    }

    if ((string)$payload['product'] !== 'nano-cart') {
        $other = (string)$payload['product'];
        return ['ok' => false, 'reason' => "Licence is for product '$other', not nano-cart.", 'payload' => $payload];
    }

    $pubkey = base64_decode(NANO_CART_LICENCE_PUBKEY_V1, true);
    if ($pubkey === false || strlen($pubkey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        return ['ok' => false, 'reason' => 'Embedded public key is malformed.', 'payload' => $payload];
    }

    // Verify against the RAW decoded payload bytes (what the signer signed).
    // Re-encoding the parsed array would risk a key-order or whitespace
    // change that invalidates a genuine signature.
    try {
        $sig_ok = sodium_crypto_sign_verify_detached($signature, $payload_json, $pubkey);
    } catch (Throwable $e) {
        return ['ok' => false, 'reason' => 'Signature verification raised an error.', 'payload' => $payload];
    }
    if (!$sig_ok) {
        return ['ok' => false, 'reason' => 'Signature does not match the embedded public key.', 'payload' => $payload];
    }

    // Wildcard `*` is only honoured for agency-unlimited tier. The
    // generator enforces this; mirror as defence in depth.
    $licence_domain = strtolower((string)$payload['domain']);
    $check_host     = strtolower(trim($current_host));
    if (str_starts_with($check_host, 'www.')) {
        $check_host = substr($check_host, 4);
    }
    $tier = (string)$payload['tier'];
    if ($licence_domain === '*' && $tier === 'agency-unlimited') {
        // wildcard pass
    } elseif ($licence_domain !== $check_host) {
        return [
            'ok' => false,
            'reason' => "Licence covers '$licence_domain', site runs on '$check_host'.",
            'payload' => $payload,
        ];
    }

    $expires = $payload['expires'] ?? null;
    if ($expires !== null && $expires !== '') {
        $ts = strtotime((string)$expires);
        if ($ts === false) {
            return ['ok' => false, 'reason' => "Licence has an unparseable expiry value: $expires.", 'payload' => $payload];
        }
        if ($ts < time()) {
            return ['ok' => false, 'reason' => "Licence expired on $expires.", 'payload' => $payload];
        }
    }

    return ['ok' => true, 'reason' => null, 'payload' => $payload];
}

/* ----------------------------------------------------------------------- */
/* Footer rendering                                                          */
/* ----------------------------------------------------------------------- */

/**
 * Render the "Powered by Nano Cart. Developed by Digital Fracture."
 * footer iff the site is unlicensed. Empty string in every "no footer"
 * case (dev host, valid licence, config problem).
 *
 * Silent on failure by design: visitor sees either the footer or
 * nothing, never an error message. Locked wording per STYLE.md uses
 * sentence separators, not em-dashes.
 */
function nano_cart_render_licence_footer(): string
{
    $footer = '<p class="nano-cart-footer-attribution">'
            . 'Powered by '
            . '<a href="https://nanocart.co.uk/" target="_blank" rel="noopener noreferrer">Nano Cart</a>. '
            . 'Developed by '
            . '<a href="https://digitalfracture.co.uk/" target="_blank" rel="noopener noreferrer">Digital Fracture</a>.'
            . '</p>';

    $host = nano_cart_licence_canonical_host();
    if ($host === '') {
        // Config missing or site_url unset: fail safe to showing the footer
        // rather than silent suppression on a misconfigured install.
        return $footer;
    }
    if (nano_cart_is_dev_host($host)) {
        return '';
    }

    try {
        $licence_key = (string)(nano_cart_load_config()['licence_key'] ?? '');
    } catch (Throwable $e) {
        $licence_key = '';
    }

    if ($licence_key !== '' && nano_cart_verify_licence($licence_key, $host)) {
        return '';
    }
    return $footer;
}
