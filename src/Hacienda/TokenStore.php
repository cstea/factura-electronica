<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Hacienda;

final class TokenStore
{
    private ?string $token = null;

    private ?int $expiresAt = null;

    public function valid(int $now): bool
    {
        return $this->token !== null && $this->expiresAt !== null && $now < $this->expiresAt;
    }

    public function token(): ?string
    {
        return $this->token;
    }

    public function set(string $token, int $expiresAt): void
    {
        $this->token = $token;
        $this->expiresAt = $expiresAt;
    }
}
