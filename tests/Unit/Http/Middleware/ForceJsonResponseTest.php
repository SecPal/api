<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2024-2025 SecPal <https://github.com/SecPal>

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(ForceJsonResponse::class)]
final class ForceJsonResponseTest extends TestCase
{
    private ForceJsonResponse $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new ForceJsonResponse;
    }

    /**
     * Test that middleware adds Accept: application/json header when not present.
     */
    public function test_adds_accept_json_header_when_missing(): void
    {
        $request = Request::create('/api/test', 'GET');

        $this->middleware->handle($request, function ($req) {
            $this->assertSame('application/json', $req->header('Accept'));

            return new Response('test');
        });
    }

    /**
     * Test that middleware preserves existing Accept header if already set to application/json.
     */
    public function test_preserves_existing_accept_json_header(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Accept', 'application/json');

        $this->middleware->handle($request, function ($req) {
            $this->assertSame('application/json', $req->header('Accept'));

            return new Response('test');
        });
    }

    /**
     * Test that middleware overrides non-JSON Accept headers.
     */
    public function test_overrides_non_json_accept_headers(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Accept', 'text/html');

        $this->middleware->handle($request, function ($req) {
            $this->assertSame('application/json', $req->header('Accept'));

            return new Response('test');
        });
    }

    /**
     * Test that middleware handles multiple Accept header values.
     */
    public function test_overrides_multiple_accept_headers(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Accept', 'text/html, application/xml, application/json');

        $this->middleware->handle($request, function ($req) {
            $this->assertSame('application/json', $req->header('Accept'));

            return new Response('test');
        });
    }

    /**
     * Test that middleware works correctly in the request pipeline.
     */
    public function test_works_in_request_pipeline(): void
    {
        $request = Request::create('/api/test', 'GET');
        $expectedResponse = new Response('test response');

        $response = $this->middleware->handle($request, function ($req) use ($expectedResponse) {
            return $expectedResponse;
        });

        $this->assertSame($expectedResponse, $response);
    }

    /**
     * Test that middleware preserves other headers.
     */
    public function test_preserves_other_headers(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer token123');
        $request->headers->set('X-Custom-Header', 'custom-value');

        $this->middleware->handle($request, function ($req) {
            $this->assertSame('Bearer token123', $req->header('Authorization'));
            $this->assertSame('custom-value', $req->header('X-Custom-Header'));
            $this->assertSame('application/json', $req->header('Accept'));

            return new Response('test');
        });
    }

    /**
     * Test that middleware works with POST requests.
     */
    public function test_works_with_post_requests(): void
    {
        $request = Request::create('/api/test', 'POST', ['key' => 'value']);

        $this->middleware->handle($request, function ($req) {
            $this->assertSame('application/json', $req->header('Accept'));
            $this->assertSame('value', $req->input('key'));

            return new Response('test');
        });
    }

    /**
     * Test that middleware works with PUT requests.
     */
    public function test_works_with_put_requests(): void
    {
        $request = Request::create('/api/test', 'PUT', ['key' => 'value']);

        $this->middleware->handle($request, function ($req) {
            $this->assertSame('application/json', $req->header('Accept'));

            return new Response('test');
        });
    }

    /**
     * Test that middleware works with DELETE requests.
     */
    public function test_works_with_delete_requests(): void
    {
        $request = Request::create('/api/test', 'DELETE');

        $this->middleware->handle($request, function ($req) {
            $this->assertSame('application/json', $req->header('Accept'));

            return new Response('test');
        });
    }

    /**
     * Test that middleware ensures JSON responses for validation errors.
     */
    public function test_ensures_json_response_for_validation_errors(): void
    {
        $request = Request::create('/api/employees', 'POST');
        // Simulate a request without Accept header (like from frontend before fix)
        $request->headers->remove('Accept');

        $this->middleware->handle($request, function ($req) {
            // Verify that after middleware, the request will trigger JSON response
            $this->assertSame('application/json', $req->header('Accept'));

            // This would prevent Laravel from returning HTML error pages
            return new Response('success');
        });
    }
}
