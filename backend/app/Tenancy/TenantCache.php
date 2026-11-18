<?php

namespace App\Tenancy;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository;

/**
 * Cache access namespaced to the current tenant ("tenant:{id}:{key}").
 *
 * Use this for anything that holds tenant data. The plain Cache facade is
 * for platform-wide values only. Resolve it from the container per use; it
 * reads the tenant at call time and throws when none is in context.
 */
final class TenantCache
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Repository $cache,
    ) {}

    public function key(string $key): string
    {
        return 'tenant:'.$this->context->id().':'.$key;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($this->key($key), $default);
    }

    public function has(string $key): bool
    {
        return $this->cache->has($this->key($key));
    }

    public function put(string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        return $this->cache->put($this->key($key), $value, $ttl);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function remember(string $key, DateTimeInterface|DateInterval|int|null $ttl, Closure $callback): mixed
    {
        return $this->cache->remember($this->key($key), $ttl, $callback);
    }

    public function forget(string $key): bool
    {
        return $this->cache->forget($this->key($key));
    }
}
