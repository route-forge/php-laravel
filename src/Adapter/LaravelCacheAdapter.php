<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Adapter;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use RouteForge\Common\Contract\CacheInterface;

/**
 * Illuminate Cache Repository → common CacheInterface 桥接。
 *
 * TTL 语义映射（SPEC §3.1.5）：
 *   - $seconds === null：永久缓存（store->forever，对应 Laravel Cache TTL=0 惯例）
 *   - 正整数：store->put(key, value, $seconds)
 */
final class LaravelCacheAdapter implements CacheInterface
{
    public function __construct(private readonly CacheRepository $store)
    {
    }

    public function get(string $key): mixed
    {
        return $this->store->get($key);
    }

    public function put(string $key, mixed $value, ?int $seconds): void
    {
        if ($seconds === null) {
            $this->store->forever($key, $value);

            return;
        }

        $this->store->put($key, $value, $seconds);
    }

    public function forget(string $key): void
    {
        $this->store->forget($key);
    }
}
