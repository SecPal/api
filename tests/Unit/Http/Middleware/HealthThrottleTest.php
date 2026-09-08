<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Middleware\HealthThrottle;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

it('uses the configured database cache so production health throttling stays shared', function (): void {
    config([
        'cache.default' => 'database',
        'cache.stores.database.driver' => 'database',
    ]);

    $cacheFactory = Mockery::mock(CacheFactory::class);
    $databaseStore = healthThrottleCacheRepositoryMock();

    $cacheFactory->shouldReceive('store')->once()->with('database')->andReturn($databaseStore);
    $cacheFactory->shouldNotReceive('store')->with('file');

    $response = app(HealthThrottle::class, ['cacheFactory' => $cacheFactory])
        ->handle(Request::create('/health', 'GET'), fn (): Response => new Response('ok'));

    expect($response->getStatusCode())->toBe(200);
});

it('falls back explicitly to file throttling when the database cache is unavailable', function (): void {
    config([
        'cache.default' => 'database',
        'cache.stores.database.driver' => 'database',
    ]);

    $cacheFactory = Mockery::mock(CacheFactory::class);
    $databaseStore = Mockery::mock(CacheRepository::class);
    $fileStore = healthThrottleCacheRepositoryMock();

    $cacheFactory->shouldReceive('store')->once()->with('database')->andReturn($databaseStore);
    $cacheFactory->shouldReceive('store')->once()->with('file')->andReturn($fileStore);
    $databaseStore->shouldReceive('get')->once()->andThrow(new RuntimeException('Database unavailable.'));

    $response = app(HealthThrottle::class, ['cacheFactory' => $cacheFactory])
        ->handle(Request::create('/health', 'GET'), fn (): Response => new Response('ok'));

    expect($response->getStatusCode())->toBe(200);
});

it('keeps liveness available when both throttle stores are unavailable', function (): void {
    config([
        'cache.default' => 'database',
        'cache.stores.database.driver' => 'database',
    ]);

    $cacheFactory = Mockery::mock(CacheFactory::class);
    $databaseStore = Mockery::mock(CacheRepository::class);
    $fileStore = Mockery::mock(CacheRepository::class);

    $cacheFactory->shouldReceive('store')->once()->with('database')->andReturn($databaseStore);
    $cacheFactory->shouldReceive('store')->once()->with('file')->andReturn($fileStore);
    $databaseStore->shouldReceive('get')->once()->andThrow(new RuntimeException('Database unavailable.'));
    $fileStore->shouldReceive('get')->once()->andThrow(new RuntimeException('File cache unavailable.'));

    $response = app(HealthThrottle::class, ['cacheFactory' => $cacheFactory])
        ->handle(Request::create('/health', 'GET'), fn (): Response => new Response('ok'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->has('X-RateLimit-Limit'))->toBeFalse();
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
