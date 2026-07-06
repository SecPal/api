<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class HealthThrottle
{
    private const MAX_ATTEMPTS = 60;

    private const DECAY_SECONDS = 60;

    public function __construct(
        private readonly CacheFactory $cacheFactory,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        [$limitedResponse, $attempts] = $this->evaluateThrottleWithFallback($request);

        if ($limitedResponse instanceof JsonResponse) {
            return $limitedResponse;
        }

        $response = $next($request);

        if ($attempts !== null) {
            $response->headers->set('X-RateLimit-Limit', (string) self::MAX_ATTEMPTS);
            $response->headers->set('X-RateLimit-Remaining', (string) max(0, self::MAX_ATTEMPTS - $attempts));
        }

        return $response;
    }

    /**
     * @return array{0: JsonResponse|null, 1: int|null}
     */
    private function evaluateThrottleWithFallback(Request $request): array
    {
        $preferredStore = $this->preferredCacheStoreName();

        try {
            return $this->evaluateThrottle($request, $this->cacheStore($preferredStore));
        } catch (Throwable) {
            if ($preferredStore === 'file') {
                return [null, null];
            }
        }

        try {
            return $this->evaluateThrottle($request, $this->cacheStore('file'));
        } catch (Throwable) {
            return [null, null];
        }
    }

    /**
     * @return array{0: JsonResponse|null, 1: int}
     */
    private function evaluateThrottle(Request $request, CacheRepository $cache): array
    {
        $key = $this->key($request);
        $timerKey = $key.':timer';
        $now = time();
        $retryAt = $this->integerCacheValue($cache->get($timerKey, 0));

        if ($retryAt <= $now) {
            $cache->forget($key);
            $cache->forget($timerKey);
            $retryAt = 0;
        }

        $attempts = $this->integerCacheValue(
            $this->withoutSerializationOrCompression($cache, fn (): mixed => $cache->get($key, 0))
        );

        if ($attempts >= self::MAX_ATTEMPTS && $retryAt > $now) {
            return [$this->buildLimitedResponse($retryAt - $now), $attempts];
        }

        $cache->add($timerKey, $now + self::DECAY_SECONDS, self::DECAY_SECONDS);
        $added = $this->withoutSerializationOrCompression(
            $cache,
            fn (): bool => $cache->add($key, 0, self::DECAY_SECONDS),
        );
        $attempts = $this->integerCacheValue($cache->increment($key));

        if (! $added && $attempts === 1) {
            $this->withoutSerializationOrCompression(
                $cache,
                fn (): bool => $cache->put($key, 1, self::DECAY_SECONDS),
            );
        }

        return [null, $attempts];
    }

    private function buildLimitedResponse(int $retryAfter): JsonResponse
    {
        return response()->json([
            'message' => 'Too many health check requests. Please try again later.',
        ], 429, [
            'Retry-After' => (string) $retryAfter,
            'X-RateLimit-Limit' => (string) self::MAX_ATTEMPTS,
            'X-RateLimit-Remaining' => '0',
        ]);
    }

    private function cacheStore(string $store): CacheRepository
    {
        return $this->cacheFactory->store($store);
    }

    private function preferredCacheStoreName(): string
    {
        $defaultStore = config('cache.default');

        if (! is_string($defaultStore) || $defaultStore === '') {
            return 'file';
        }

        return $this->cacheStoreUsesDatabase($defaultStore)
            ? 'file'
            : $defaultStore;
    }

    /**
     * @param  array<string, bool>  $visited
     */
    private function cacheStoreUsesDatabase(string $store, array $visited = []): bool
    {
        if (isset($visited[$store])) {
            return false;
        }

        $driver = config("cache.stores.{$store}.driver");

        if ($driver === 'database') {
            return true;
        }

        if ($driver !== 'failover') {
            return false;
        }

        $fallbackStores = config("cache.stores.{$store}.stores", []);

        if (! is_array($fallbackStores)) {
            return false;
        }

        $visited[$store] = true;

        foreach ($fallbackStores as $fallbackStore) {
            if (is_string($fallbackStore) && $this->cacheStoreUsesDatabase($fallbackStore, $visited)) {
                return true;
            }
        }

        return false;
    }

    private function key(Request $request): string
    {
        $scope = trim($request->path(), '/');

        return 'health|'.$request->ip().'|'.$scope;
    }

    private function integerCacheValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withoutSerializationOrCompression(CacheRepository $cache, callable $callback): mixed
    {
        if (! is_callable([$cache, 'getStore'])) {
            return $callback();
        }

        $store = $cache->getStore();

        if (! $store instanceof RedisStore) {
            return $callback();
        }

        $connection = $store->connection();

        if (! $connection instanceof PhpRedisConnection) {
            return $callback();
        }

        return $connection->withoutSerializationOrCompression($callback);
    }
}
