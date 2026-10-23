<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Hacienda;

/** Token kept in the PHP process: enough for scripts and tests; each worker would log in on its own. */
final class MemoryTokenStore implements TokenStore
{
    private ?string $token = null;

    private ?int $expiresAt = null;

    public function get(int $now): ?string
    {
        return $this->token !== null && $this->expiresAt !== null && $now < $this->expiresAt ? $this->token : null;
    }

    public function put(string $token, int $expiresAt): void
    {
        $this->token = $token;
        $this->expiresAt = $expiresAt;
    }

    public function forget(): void
    {
        $this->token = null;
        $this->expiresAt = null;
    }

    public function exclusive(callable $recheck, callable $fetch): string
    {
        return $recheck() ?? $fetch();
    }
}
