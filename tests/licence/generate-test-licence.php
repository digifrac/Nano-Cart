<?php
/**
 * Test licence generator.
 *
 * Creates a throwaway Ed25519 keypair and signs a Nano Cart licence
 * payload. Prints both the public key (paste into licence.php's
 * NANO_CART_LICENCE_PUBKEY_V1 constant for testing) and the licence
 * key (paste into the admin Licence page).
 *
 * Usage:
 *   php tests/licence/generate-test-licence.php <domain> [tier]
 *
 * Examples:
 *   php tests/licence/generate-test-licence.php example.com
 *   php tests/licence/generate-test-licence.php "*" agency-unlimited
 *
 * NOT for production. Production licences are signed with the real
 * Digital Fracture private key in the private nano-licence-tools repo.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the command line.\n");
    exit(1);
}
if ($argc < 2) {
    fwrite(STDERR, "Usage: php " . basename(__FILE__) . " <domain> [tier]\n");
    fwrite(STDERR, "Tiers: single (default), agency-3, agency-unlimited\n");
    exit(1);
}
if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "libsodium required (built into PHP 7.2+; this PHP build is missing it).\n");
    exit(1);
}

$domain = $argv[1];
$tier   = $argv[2] ?? 'single';
$allowed_tiers = ['single', 'agency-3', 'agency-unlimited'];
if (!in_array($tier, $allowed_tiers, true)) {
    fwrite(STDERR, "Unknown tier '$tier'. Allowed: " . implode(', ', $allowed_tiers) . "\n");
    exit(1);
}

$keypair = sodium_crypto_sign_keypair();
$secret  = sodium_crypto_sign_secretkey($keypair);
$public  = sodium_crypto_sign_publickey($keypair);

// Payload field order matches nano-licence-tools' nano_signer.php so the
// verifier reconstructs identical JSON bytes for signature checking.
$payload = [
    'product'     => 'nano-cart',
    'domain'      => $domain,
    'tier'        => $tier,
    'licence_id'  => sprintf(
        '%08x-%04x-%04x-%04x-%012x',
        random_int(0, 0xffffffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffffffffffff)
    ),
    'issued'      => date('Y-m-d'),
    'expires'     => null,
    'key_version' => 1,
];
$payload_json = json_encode($payload, JSON_UNESCAPED_SLASHES);
$signature    = sodium_crypto_sign_detached($payload_json, $secret);
$licence_key  = base64_encode($payload_json) . '.' . base64_encode($signature);

echo "=== TEST LICENCE GENERATOR (NOT PRODUCTION) ===\n\n";
echo "Domain: $domain\n";
echo "Tier:   $tier\n\n";
echo "PUBLIC KEY (paste into licence.php's NANO_CART_LICENCE_PUBKEY_V1 for testing):\n";
echo base64_encode($public) . "\n\n";
echo "LICENCE KEY (paste into admin Licence page):\n";
echo $licence_key . "\n\n";
echo "After testing, REVERT licence.php to the real Digital Fracture key:\n";
echo "OW0ZWPowsYFF4Hv49r8Kc8OcM31COddoOk5j1UVCWfY=\n";
