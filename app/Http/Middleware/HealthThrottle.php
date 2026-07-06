<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
        $cache = $this->cacheStore();
        $key = $this->key($request);
        $timerKey = $key.':timer';
        $now = time();
        $retryAt = (int) $cache->get($timerKey, 0);

        if ($retryAt <= $now) {
            $cache->forget($key);
            $cache->forget($timerKey);
            $retryAt = 0;
        }

        $attempts = (int) $cache->get($key, 0);

        if ($attempts >= self::MAX_ATTEMPTS && $retryAt > $now) {
            return $this->buildLimitedResponse($retryAt - $now);
        }

        $cache->add($timerKey, $now + self::DECAY_SECONDS, self::DECAY_SECONDS);
        $added = $cache->add($key, 0, self::DECAY_SECONDS);
        $attempts = (int) $cache->increment($key);

        if (! $added && $attempts === 1) {
            $cache->put($key, 1, self::DECAY_SECONDS);
        }

        $response = $next($request);

        return $response->withHeaders([
            'X-RateLimit-Limit' => (string) self::MAX_ATTEMPTS,
            'X-RateLimit-Remaining' => (string) max(0, self::MAX_ATTEMPTS - $attempts),
        ]);
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

    private function cacheStore(): CacheRepository
    {
        return app()->environment('testing')
            ? $this->cacheFactory->store('array')
            : $this->cacheFactory->store('file');
    }

    private function key(Request $request): string
    {
        $scope = trim($request->path(), '/');

        return 'health|'.$request->ip().'|'.$scope;
    }
}
