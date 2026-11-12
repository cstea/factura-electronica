<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Hacienda;

/**
 * Where the IdP access token lives between requests. Hacienda tokens last about 5 minutes; every worker should reuse the same one,
 * so a shared store (cache) with a "single flight" lock is what a queue worker fleet needs.
 *
 * Every call names a $scope: one per set of credentials (environment + username, hashed by HaciendaClient). A store shared by
 * several taxpayers must keep scopes apart, or one taxpayer's token would be sent under another's identity.
 */
interface TokenStore
{
    /** The stored token for $scope if it is still valid at $now (unix seconds), else null. */
    public function get(string $scope, int $now): ?string;

    public function put(string $scope, string $token, int $expiresAt): void;

    public function forget(string $scope): void;

    /**
     * Runs $fetch holding an exclusive lock for $scope, so that only one process logs in when the token expired; $recheck is called
     * inside the lock and returns a token another process may have stored while this one waited.
     *
     * @param  callable(): ?string  $recheck
     * @param  callable(): string  $fetch
     */
    public function exclusive(string $scope, callable $recheck, callable $fetch): string;
}
