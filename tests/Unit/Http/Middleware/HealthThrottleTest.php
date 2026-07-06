<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Http\Middleware\HealthThrottle;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

it('uses a configured non-database cache store so production health throttling stays shared', function (): void {
    config([
        'cache.default' => 'redis',
        'cache.stores.redis.driver' => 'redis',
    ]);

    $cacheFactory = Mockery::mock(CacheFactory::class);
    $redisStore = healthThrottleCacheRepositoryMock();

    $cacheFactory->shouldReceive('store')->once()->with('redis')->andReturn($redisStore);
    $cacheFactory->shouldNotReceive('store')->with('file');

    $response = app(HealthThrottle::class, ['cacheFactory' => $cacheFactory])
        ->handle(Request::create('/health', 'GET'), fn (): Response => new Response('ok'));

    expect($response->getStatusCode())->toBe(200);
});

it('falls back to the file cache when the default cache store depends on the database', function (): void {
    config([
        'cache.default' => 'database',
        'cache.stores.database.driver' => 'database',
    ]);

    $cacheFactory = Mockery::mock(CacheFactory::class);
    $fileStore = healthThrottleCacheRepositoryMock();

    $cacheFactory->shouldReceive('store')->once()->with('file')->andReturn($fileStore);
    $cacheFactory->shouldNotReceive('store')->with('database');

    $response = app(HealthThrottle::class, ['cacheFactory' => $cacheFactory])
        ->handle(Request::create('/health', 'GET'), fn (): Response => new Response('ok'));

    expect($response->getStatusCode())->toBe(200);
});

it('falls back to the file cache when a failover store includes a database-backed store', function (): void {
    config([
        'cache.default' => 'failover',
        'cache.stores.failover.driver' => 'failover',
        'cache.stores.failover.stores' => ['database', 'array'],
        'cache.stores.database.driver' => 'database',
        'cache.stores.array.driver' => 'array',
    ]);

    $cacheFactory = Mockery::mock(CacheFactory::class);
    $fileStore = healthThrottleCacheRepositoryMock();

    $cacheFactory->shouldReceive('store')->once()->with('file')->andReturn($fileStore);
    $cacheFactory->shouldNotReceive('store')->with('failover');

    $response = app(HealthThrottle::class, ['cacheFactory' => $cacheFactory])
        ->handle(Request::create('/health', 'GET'), fn (): Response => new Response('ok'));

    expect($response->getStatusCode())->toBe(200);
});

it('falls back to the file cache when a configured non-database store is unavailable', function (): void {
    config([
        'cache.default' => 'redis',
        'cache.stores.redis.driver' => 'redis',
    ]);

    $cacheFactory = Mockery::mock(CacheFactory::class);
    $redisStore = Mockery::mock(CacheRepository::class);
    $fileStore = healthThrottleCacheRepositoryMock();

    $cacheFactory->shouldReceive('store')->once()->with('redis')->andReturn($redisStore);
    $cacheFactory->shouldReceive('store')->once()->with('file')->andReturn($fileStore);
    $redisStore->shouldReceive('get')->once()->andThrow(new RuntimeException('Redis unavailable.'));

    $response = app(HealthThrottle::class, ['cacheFactory' => $cacheFactory])
        ->handle(Request::create('/health', 'GET'), fn (): Response => new Response('ok'));

    expect($response->getStatusCode())->toBe(200);
});

function healthThrottleCacheRepositoryMock(): CacheRepository
{
    $store = Mockery::mock(CacheRepository::class);
    $store->shouldReceive('get')->with(Mockery::type('string'), 0)->andReturn(0);
    $store->shouldReceive('forget')->with(Mockery::type('string'))->andReturn(true);
    $store->shouldReceive('add')->with(Mockery::type('string'), Mockery::any(), 60)->andReturn(true);
    $store->shouldReceive('increment')->with(Mockery::type('string'))->andReturn(1);

    return $store;
}
