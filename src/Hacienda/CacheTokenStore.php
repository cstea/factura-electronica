<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Hacienda;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;

/** Token shared by every process through a Laravel cache, with a lock so only one worker logs in at a time. */
final class CacheTokenStore implements TokenStore
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $key = 'factura-electronica.token',
    ) {}

    public function get(int $now): ?string
    {
        $token = $this->cache->get($this->key);

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function put(string $token, int $expiresAt): void
    {
        $ttl = max(1, $expiresAt - time());
        $this->cache->put($this->key, $token, $ttl);
    }

    public function forget(): void
    {
        $this->cache->forget($this->key);
    }

    public function exclusive(callable $recheck, callable $fetch): string
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $recheck() ?? $fetch();
        }

        return $store->lock($this->key.'.lock', 15)->block(10, fn (): string => $recheck() ?? $fetch());
    }
}
