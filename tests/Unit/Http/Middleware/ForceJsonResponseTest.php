<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2024-2025 SecPal <https://github.com/SecPal>

declare(strict_types=1);

use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * @property App\Http\Middleware\ForceJsonResponse $middleware
 */
beforeEach(function () {
    $this->middleware = new ForceJsonResponse;
});

test('adds accept json header when missing', function () {
    $request = Request::create('/api/test', 'GET');

    $this->middleware->handle($request, function ($req) {
        expect($req->header('Accept'))->toBe('application/json');

        return new Response('test');
    });
});

test('preserves existing accept json header', function () {
    $request = Request::create('/api/test', 'GET');
    $request->headers->set('Accept', 'application/json');

    $this->middleware->handle($request, function ($req) {
        expect($req->header('Accept'))->toBe('application/json');

        return new Response('test');
    });
});

test('overrides non json accept headers', function () {
    $request = Request::create('/api/test', 'GET');
    $request->headers->set('Accept', 'text/html');

    $this->middleware->handle($request, function ($req) {
        expect($req->header('Accept'))->toBe('application/json');

        return new Response('test');
    });
});

test('overrides multiple accept headers', function () {
    $request = Request::create('/api/test', 'GET');
    $request->headers->set('Accept', 'text/html, application/xml, application/json');

    $this->middleware->handle($request, function ($req) {
        expect($req->header('Accept'))->toBe('application/json');

        return new Response('test');
    });
});

test('works in request pipeline', function () {
    $request = Request::create('/api/test', 'GET');
    $expectedResponse = new Response('test response');

    $response = $this->middleware->handle($request, function ($req) use ($expectedResponse) {
        return $expectedResponse;
    });

    expect($response)->toBe($expectedResponse);
});

test('preserves other headers', function () {
    $request = Request::create('/api/test', 'GET');
    $request->headers->set('Authorization', 'Bearer token123');
    $request->headers->set('X-Custom-Header', 'custom-value');

    $this->middleware->handle($request, function ($req) {
        expect($req->header('Authorization'))->toBe('Bearer token123')
            ->and($req->header('X-Custom-Header'))->toBe('custom-value')
            ->and($req->header('Accept'))->toBe('application/json');

        return new Response('test');
    });
});

test('works with post requests', function () {
    $request = Request::create('/api/test', 'POST', ['key' => 'value']);

    $this->middleware->handle($request, function ($req) {
        expect($req->header('Accept'))->toBe('application/json')
            ->and($req->input('key'))->toBe('value');

        return new Response('test');
    });
});

test('works with put requests', function () {
    $request = Request::create('/api/test', 'PUT', ['key' => 'value']);

    $this->middleware->handle($request, function ($req) {
        expect($req->header('Accept'))->toBe('application/json');

        return new Response('test');
    });
});

test('works with delete requests', function () {
    $request = Request::create('/api/test', 'DELETE');

    $this->middleware->handle($request, function ($req) {
        expect($req->header('Accept'))->toBe('application/json');

        return new Response('test');
    });
});

test('ensures json response for validation errors', function () {
    $request = Request::create('/api/employees', 'POST');
    $request->headers->remove('Accept');

    $this->middleware->handle($request, function ($req) {
        expect($req->header('Accept'))->toBe('application/json');

        return new Response('success');
    });
});
