<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

/*
 * Ensure a throwaway self-signed PKCS#12 exists for the signing tests.
 * The .p12 is intentionally gitignored (no key material in the repo), so it is
 * generated on first run — locally, on CI, and for anyone cloning fresh.
 * Pin '1234' matches what the tests pass to SigningCertificate::fromPath().
 */
$certDir = __DIR__.'/fixtures/cert';
$p12 = $certDir.'/test.p12';

if (! is_file($p12)) {
    if (! is_dir($certDir)) {
        mkdir($certDir, 0777, true);
    }

    $key = $certDir.'/_throwaway_key.pem';
    $crt = $certDir.'/_throwaway_crt.pem';

    exec('openssl req -x509 -newkey rsa:2048 -keyout '.escapeshellarg($key).' -out '.escapeshellarg($crt).
        ' -days 3650 -nodes -subj "/CN=FE Test" 2>/dev/null');
    exec('openssl pkcs12 -export -inkey '.escapeshellarg($key).' -in '.escapeshellarg($crt).
        ' -out '.escapeshellarg($p12).' -passout pass:1234 2>/dev/null');

    @unlink($key);
    @unlink($crt);

    if (! is_file($p12)) {
        fwrite(STDERR, "Could not generate test PKCS#12 at {$p12}; ensure the openssl CLI is available.\n");
    }
}
