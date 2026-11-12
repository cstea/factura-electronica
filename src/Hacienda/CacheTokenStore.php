<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Hacienda;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Stea\FacturaElectronica\Exceptions\HaciendaAuthException;

/**
 * Token shared by every process through a Laravel cache, with a lock so only one worker logs in at a time. Keys are
 * "{prefix}.{scope}", so several taxpayers can share one cache without ever seeing each other's token.
 */
final class CacheTokenStore implements TokenStore
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = 'factura-electronica.token',
        // Long enough to cover a slow login (HaciendaClient's default 30 s timeout + 10 s connect).
        private readonly int $lockSeconds = 60,
        private readonly int $waitSeconds = 10,
    ) {}

    public function get(string $scope, int $now): ?string
    {
        $token = $this->cache->get($this->key($scope));

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function put(string $scope, string $token, int $expiresAt): void
    {
        $ttl = max(1, $expiresAt - time());
        $this->cache->put($this->key($scope), $token, $ttl);
    }

    public function forget(string $scope): void
    {
        $this->cache->forget($this->key($scope));
    }

    public function exclusive(string $scope, callable $recheck, callable $fetch): string
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $recheck() ?? $fetch();
        }

        try {
            return $store->lock($this->key($scope).'.lock', $this->lockSeconds)->block($this->waitSeconds, fn (): string => $recheck() ?? $fetch());
        } catch (LockTimeoutException) {
            // Another worker is still logging in: a transient login failure, which enviar() reports and send() lets the caller retry.
            throw new HaciendaAuthException('Token grant pending in another worker; retry shortly.');
        }
    }

    private function key(string $scope): string
    {
        return $this->prefix.'.'.$scope;
    }
}
