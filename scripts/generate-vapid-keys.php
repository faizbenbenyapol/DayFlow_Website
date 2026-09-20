<?php
// Generates the VAPID key pair used to sign Web Push messages.
//
//   php scripts/generate-vapid-keys.php
//
// Run once, then put the printed values in .env (or the container's
// environment). Regenerating them invalidates every existing subscription, so
// keep the pair alongside APP_KEY in whatever holds your secrets.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

$key = openssl_pkey_new([
    'curve_name'       => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
]);

if ($key === false) {
    fwrite(STDERR, "Could not generate an EC key. Is the OpenSSL extension available?\n");
    exit(1);
}

$details = openssl_pkey_get_details($key);
if (!isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
    fwrite(STDERR, "The generated key is missing its EC components.\n");
    exit(1);
}

$base64Url = static fn(string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

// The public key travels as an uncompressed point: 0x04 || X || Y.
$publicKey = $base64Url("\x04"
    . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
    . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT));

$privateKey = $base64Url(str_pad($details['ec']['d'], 32, "\x00", STR_PAD_LEFT));

echo "Add these to your .env:\n\n";
echo 'VAPID_PUBLIC_KEY=' . $publicKey . "\n";
echo 'VAPID_PRIVATE_KEY=' . $privateKey . "\n";
echo "VAPID_SUBJECT=mailto:you@example.com\n\n";
echo "Keep the private key secret. Changing the pair unsubscribes every browser.\n";
