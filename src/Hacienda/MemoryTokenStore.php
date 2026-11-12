<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Hacienda;

/** Token kept in this process only (the default): fine for scripts and a single worker. */
final class MemoryTokenStore implements TokenStore
{
    /** @var array<string, array{token: string, expiresAt: int}> */
    private array $tokens = [];

    public function get(string $scope, int $now): ?string
    {
        $entry = $this->tokens[$scope] ?? null;

        return $entry !== null && $now < $entry['expiresAt'] ? $entry['token'] : null;
    }

    public function put(string $scope, string $token, int $expiresAt): void
    {
        $this->tokens[$scope] = ['token' => $token, 'expiresAt' => $expiresAt];
    }

    public function forget(string $scope): void
    {
        unset($this->tokens[$scope]);
    }

    public function exclusive(string $scope, callable $recheck, callable $fetch): string
    {
        return $recheck() ?? $fetch();
    }
}
