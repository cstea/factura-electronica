<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Support;

use RuntimeException;

/**
 * The taxpayer a sandbox run signs as, read from the operator's certificate.
 *
 * Hacienda rejects a comprobante whose signer is not its emisor (or, for a
 * factura de compra or mensaje receptor, its receptor) with code -60, so the
 * gated sandbox tests must use the certificate's own cédula, never a fixture.
 * FE_SANDBOX_CEDULA / FE_SANDBOX_TIPO override it.
 */
final class SandboxIdentity
{
    private function __construct(
        public readonly string $tipo,
        public readonly string $cedula,
    ) {}

    public static function fromEnv(): self
    {
        $override = (string) getenv('FE_SANDBOX_CEDULA');
        if ($override !== '') {
            return new self((string) (getenv('FE_SANDBOX_TIPO') ?: (strlen($override) === 9 ? '01' : '02')), $override);
        }

        $p12 = @file_get_contents((string) getenv('FE_P12_PATH'));
        if ($p12 === false || ! openssl_pkcs12_read($p12, $certs, (string) getenv('FE_PIN'))) {
            throw new RuntimeException('Cannot open FE_P12_PATH with FE_PIN to read the sandbox taxpayer.');
        }

        $subject = openssl_x509_parse($certs['cert'])['subject'] ?? [];

        return self::fromSerialNumber((string) ($subject['serialNumber'] ?? ''));
    }

    /**
     * BCCR certificates carry the cédula in the subject serialNumber:
     * "CPF-09-0107-0387" (física) or "CPJ-3-102-935763" (jurídica).
     */
    public static function fromSerialNumber(string $serialNumber): self
    {
        if (preg_match('/^CPF-([\d-]+)$/', $serialNumber, $m)) {
            return new self('01', str_pad(ltrim(str_replace('-', '', $m[1]), '0'), 9, '0', STR_PAD_LEFT));
        }

        if (preg_match('/^CPJ-([\d-]+)$/', $serialNumber, $m)) {
            return new self('02', str_replace('-', '', $m[1]));
        }

        throw new RuntimeException("Unrecognised certificate serialNumber [{$serialNumber}]; set FE_SANDBOX_CEDULA.");
    }
}
