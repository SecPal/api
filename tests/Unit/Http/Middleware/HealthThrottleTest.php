<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Middleware\HealthThrottle;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Http\Request;
use Illuminate\Redis\Connections\PhpRedisConnection;
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

it('initializes redis-backed counters without serialization or compression', function (): void {
    config([
        'cache.default' => 'redis',
        'cache.stores.redis.driver' => 'redis',
    ]);

    $cacheFactory = Mockery::mock(CacheFactory::class);
    $store = Mockery::mock(RedisStore::class);
    $connection = Mockery::mock(PhpRedisConnection::class);
    $redisStore = new LaravelCacheRepository($store);

    $cacheFactory->shouldReceive('store')->once()->with('redis')->andReturn($redisStore);
    $cacheFactory->shouldNotReceive('store')->with('file');

    $store->shouldReceive('connection')->twice()->andReturn($connection);
    $store->shouldReceive('get')->with(Mockery::type('string'))->andReturn(0);
    $store->shouldReceive('forget')->with(Mockery::type('string'))->andReturn(true);
    $store->shouldReceive('add')->with(Mockery::type('string'), Mockery::any(), 60)->andReturn(true);
    $store->shouldReceive('increment')->with(Mockery::type('string'), 1)->andReturn(1);
    $connection->shouldReceive('withoutSerializationOrCompression')
        ->twice()
        ->andReturnUsing(static fn (callable $callback): mixed => $callback());

    $response = app(HealthThrottle::class, ['cacheFactory' => $cacheFactory])
        ->handle(Request::create('/health', 'GET'), fn (): Response => new Response('ok'));

    expect($response->getStatusCode())->toBe(200);
});

function healthThrottleCacheRepositoryMock(): CacheRepository
{
    $store = Mockery::mock(Store::class);
    $store->shouldReceive('get')->with(Mockery::type('string'))->andReturn(0);
    $store->shouldReceive('forget')->with(Mockery::type('string'))->andReturn(true);
    $store->shouldReceive('increment')->with(Mockery::type('string'), 1)->andReturn(1);
    $store->shouldReceive('put')->with(Mockery::type('string'), Mockery::any(), 60)->andReturn(true);

    return new LaravelCacheRepository($store);
}
