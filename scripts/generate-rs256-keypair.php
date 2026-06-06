<?php

declare(strict_types=1);

/**
 * Generates a fresh RS256 key pair for the Chat API v2.
 *
 * - PRIVATE KEY must be stored in Next.js (JWT signer)
 * - PUBLIC KEY must be stored in PHP (JWT verifier)
 *
 * Usage: php scripts/generate-rs256-keypair.php [output_dir]
 */

$outputDir = $argv[1] ?? __DIR__ . '/../keys';
if (!is_dir($outputDir)) {
    if (!mkdir($outputDir, 0700, true) && !is_dir($outputDir)) {
        fwrite(STDERR, "Failed to create output directory: {$outputDir}\n");
        exit(1);
    }
}

$config = [
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
];

$resource = openssl_pkey_new($config);
if ($resource === false) {
    fwrite(STDERR, "Failed to generate key pair: " . openssl_error_string() . "\n");
    exit(1);
}

openssl_pkey_export($resource, $privateKey);
$details = openssl_pkey_get_details($resource);
$publicKey = $details['key'];

$privatePath = $outputDir . '/jwt_private.pem';
$publicPath = $outputDir . '/jwt_public.pem';

if (file_put_contents($privatePath, $privateKey) === false) {
    fwrite(STDERR, "Failed to write private key to {$privatePath}\n");
    exit(1);
}
chmod($privatePath, 0600);

if (file_put_contents($publicPath, $publicKey) === false) {
    fwrite(STDERR, "Failed to write public key to {$publicPath}\n");
    exit(1);
}
chmod($publicPath, 0644);

echo "RS256 key pair generated.\n";
echo "  Private key: {$privatePath}\n";
echo "  Public  key: {$publicPath}\n";
echo "\n";
echo "Next.js (.env):\n";
echo "  JWT_API_PRIVATE_KEY=\"" . addslashes(file_get_contents($privatePath)) . "\"\n";
echo "\n";
echo "PHP (.env):\n";
echo "  JWT_API_PUBLIC_KEY=\"" . addslashes(file_get_contents($publicPath)) . "\"\n";
