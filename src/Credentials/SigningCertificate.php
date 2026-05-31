<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Credentials;

use InvalidArgumentException;

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

    public function __debugInfo(): array
    {
        return ['contents' => '***', 'pin' => '***'];
    }
}
