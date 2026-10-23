<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Hacienda;

/**
 * Where the IdP access token lives between requests. Hacienda tokens last about 5 minutes; every worker should reuse the same one,
 * so a shared store (cache) with a "single flight" lock is what a queue worker fleet needs.
 */
interface TokenStore
{
    /** The stored token if it is still valid at $now (unix seconds), else null. */
    public function get(int $now): ?string;

    public function put(string $token, int $expiresAt): void;

    public function forget(): void;

    /**
     * Runs $fetch holding an exclusive lock, so that only one process logs in when the token expired; $recheck is called inside the lock
     * and returns a token another process may have stored while this one waited.
     *
     * @param  callable(): ?string  $recheck
     * @param  callable(): string  $fetch
     */
    public function exclusive(callable $recheck, callable $fetch): string;
}
