<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Credentials;

use DateTimeImmutable;
use InvalidArgumentException;
use Stea\FacturaElectronica\Exceptions\SigningException;

final class SigningCertificate
{
    private function __construct(
        private readonly string $contents,
        private readonly string $pin,
    ) {}

    public static function fromPath(string $path, string $pin): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Certificate not readable at: {$path}");
        }

        return new self((string) file_get_contents($path), $pin);
    }

    public static function fromContents(string $bytes, string $pin): self
    {
        return new self($bytes, $pin);
    }

    public function contents(): string
    {
        return $this->contents;
    }

    public function pin(): string
    {
        return $this->pin;
    }

    /**
     * Opens the PKCS#12 and reads its certificate. Throws {@see SigningException} with a message a person can act on when the file
     * cannot be read (wrong PIN, damaged, or an old cipher that OpenSSL 3 refuses), is expired, or its key is under 2048 bits.
     */
    public function validar(): CertificadoInfo
    {
        $partes = [];

        if (! @openssl_pkcs12_read($this->contents, $partes, $this->pin)) {
            $detalle = '';
            while (($e = openssl_error_string()) !== false) {
                $detalle .= $e.' ';
            }

            if (str_contains($detalle, 'unsupported') || str_contains($detalle, '0308010C')) {
                throw new SigningException('El archivo .p12 usa un cifrado antiguo (RC2) que este servidor no lee. Reexpórtalo con: openssl pkcs12 -legacy -in llave.p12 -nodes -out k.pem && openssl pkcs12 -export -in k.pem -out llave-nueva.p12');
            }

            throw new SigningException('No se pudo abrir el certificado: revisa el PIN o que el archivo no esté dañado.');
        }

        $info = openssl_x509_parse($partes['cert']);
        $llave = openssl_pkey_get_details(openssl_pkey_get_public($partes['cert']) ?: throw new SigningException('El certificado no tiene llave pública.'));

        if ($info === false || $llave === false) {
            throw new SigningException('No se pudo leer el contenido del certificado.');
        }

        $sujeto = (string) ($info['subject']['CN'] ?? implode(', ', array_map(strval(...), $info['subject'] ?? [])));
        $desde = (new DateTimeImmutable)->setTimestamp((int) $info['validFrom_time_t']);
        $vence = (new DateTimeImmutable)->setTimestamp((int) $info['validTo_time_t']);
        $bits = (int) ($llave['bits'] ?? 0);
        $cert = new CertificadoInfo($sujeto, $desde, $vence, $bits);

        if ($cert->vencido()) {
            throw new SigningException('El certificado venció el '.$vence->format('Y-m-d').'.');
        }

        if ($bits < 2048) {
            throw new SigningException("La llave del certificado es de {$bits} bits; Hacienda exige RSA de 2048 o 4096.");
        }

        return $cert;
    }

    public function __debugInfo(): array
    {
        return ['contents' => '***', 'pin' => '***'];
    }
}
