<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Controllers\AuthController;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\Site;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

describe('SPA Session Login', function () {
    beforeEach(function () {
        clearLoginRateLimiter('spa@secpal.dev');
    });

    test('spa login rejects stateless api-style requests with a controlled 400 response', function () {
        User::factory()->create([
            'email' => 'spa@secpal.dev',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/v1/auth/login', [
            'email' => 'spa@secpal.dev',
            'password' => 'password123',
        ]);

        $response->assertBadRequest()
            ->assertJson([
                'message' => 'This endpoint requires a browser session context. Use /v1/auth/token for API clients.',
            ]);
    });

    test('spa login sets remember token for long-lived sessions', function () {
        $user = User::factory()->create([
            'email' => 'spa@secpal.dev',
            'password' => bcrypt('password123'),
            'remember_token' => null, // Ensure no remember token before login
        ]);

        // Verify remember_token is null before login
        expect($user->remember_token)->toBeNull();

        // Simulate stateful SPA request with Origin header matching SANCTUM_STATEFUL_DOMAINS
        $response = $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/login', [
            'email' => 'spa@secpal.dev',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email'],
            ]);

        // Verify remember_token is set after login (for PWA long-lived sessions)
        $user->refresh();
        expect($user->remember_token)->not->toBeNull();
    });

    test('spa login returns user data', function () {
        User::factory()->create([
            'name' => 'SPA User',
            'email' => 'spa@secpal.dev',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/login', [
            'email' => 'spa@secpal.dev',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'user' => [
                    'id',
                    'name',
                    'email',
                    'emailVerified',
                    'roles',
                    'permissions',
                    'hasOrganizationalScopes',
                    'hasCustomerAccess',
                    'hasSiteAccess',
                ],
            ])
            ->assertJson([
                'user' => [
                    'name' => 'SPA User',
                    'email' => 'spa@secpal.dev',
                    'emailVerified' => true,
                ],
            ]);
    });

    test('spa login and me payloads include employee status, workflow state, and canonical employee fields', function () {
        $email = 'employee-auth-payload@secpal.dev';

        $user = User::factory()->create([
            'name' => 'Employee Payload User',
            'email' => $email,
            'password' => bcrypt('password123'),
        ]);

        $employee = Employee::factory()->create([
            'user_id' => $user->id,
            'email' => $email,
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_workflow_status' => null,
            'contract_start_date' => '2026-06-01',
        ]);

        $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/login', [
            'email' => $email,
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('user.employeeStatus', Employee::STATUS_PRE_CONTRACT)
            ->assertJsonPath('user.onboardingWorkflowStatus', Employee::WORKFLOW_STATUS_ACCOUNT_INITIALIZED)
            ->assertJsonPath('user.employee.id', (string) $employee->id)
            ->assertJsonPath('user.employee.contractStartDate', '2026-06-01');

        $this->withHeaders(spaHeaders())
            ->getJson('/v1/me')
            ->assertOk()
            ->assertJsonPath('employeeStatus', Employee::STATUS_PRE_CONTRACT)
            ->assertJsonPath('onboardingWorkflowStatus', Employee::WORKFLOW_STATUS_ACCOUNT_INITIALIZED)
            ->assertJsonPath('employee.id', (string) $employee->id)
            ->assertJsonPath('employee.contractStartDate', '2026-06-01');
    });

    test('spa login fails with invalid credentials', function () {
        User::factory()->create([
            'email' => 'spa@secpal.dev',
            'password' => bcrypt('correct-password'),
        ]);

        $response = $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/login', [
            'email' => 'spa@secpal.dev',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    test('spa login authenticates user via session', function () {
        User::factory()->create([
            'email' => 'spa@secpal.dev',
            'password' => bcrypt('password123'),
        ]);

        // Login with stateful headers
        $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/login', [
            'email' => 'spa@secpal.dev',
            'password' => 'password123',
        ])->assertOk();

        // Verify we can access protected endpoint via session (cookies are preserved in test)
        $this->withHeaders(spaHeaders())->getJson('/v1/me')
            ->assertOk()
            ->assertJson(['email' => 'spa@secpal.dev']);
    });

    test('spa logout via canonical endpoint clears remember token', function () {
        $email = 'spa-logout-'.Str::uuid().'@secpal.dev';

        $user = User::factory()->create([
            'email' => $email,
            'password' => bcrypt('password123'),
            'remember_token' => null,
        ]);

        // Login
        $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/login', [
            'email' => $email,
            'password' => 'password123',
        ])->assertOk();

        // Verify remember token is set
        $user->refresh();
        expect($user->remember_token)->not->toBeNull();

        // Logout
        $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/logout')->assertOk();

        // Verify remember token is cleared
        $user->refresh();
        expect($user->remember_token)->toBeNull();
    });

    test('spa logout clears the browser session even when an authorization header is present', function () {
        $email = 'spa-mixed-logout-'.Str::uuid().'@secpal.dev';

        $user = User::factory()->create([
            'email' => $email,
            'password' => bcrypt('password123'),
            'remember_token' => null,
        ]);

        $token = $user->createToken('mobile-app');

        $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/login', [
            'email' => $email,
            'password' => 'password123',
        ])->assertOk();

        $this->withHeaders(spaHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/logout')
            ->assertOk()
            ->assertJson([
                'message' => 'Logged out successfully',
            ]);

        $user->refresh();

        expect($user->remember_token)->toBeNull();
        expect($user->tokens()->count())->toBe(1);

        // Clear the Authorization header from the logout request before the session-only check
        $this->flushHeaders();

        // Reset cached auth guards so the next request reads fresh from the (now empty) session.
        // In the Laravel test framework the AuthManager caches guard instances across requests
        // in the same PHP process; forgetGuards() ensures the session-invalidation is visible.
        $this->app->make('auth')->forgetGuards();

        // Session is invalidated: a same-domain request with no Authorization header must return 401
        $this->withHeaders(spaHeaders())
            ->getJson('/v1/me')
            ->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/v1/me')
            ->assertOk()
            ->assertJson([
                'email' => $email,
            ]);
    });

    test('legacy session logout alias remains available for existing spa clients', function () {
        $email = 'spa-legacy-logout-'.Str::uuid().'@secpal.dev';

        $user = User::factory()->create([
            'email' => $email,
            'password' => bcrypt('password123'),
            'remember_token' => null,
        ]);

        $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/login', [
            'email' => $email,
            'password' => 'password123',
        ])->assertOk();

        $user->refresh();
        expect($user->remember_token)->not->toBeNull();

        $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/session/logout')->assertOk();

        $user->refresh();
        expect($user->remember_token)->toBeNull();
    });
});

describe('Auth Token Generation', function () {
    beforeEach(function () {
        clearLoginRateLimiter('test@secpal.dev');
    });

    test('user can generate token with valid credentials', function () {
        $email = 'token-success-'.Str::uuid().'@secpal.dev';

        $user = User::factory()->create([
            'email' => $email,
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/v1/auth/token', [
            'email' => $email,
            'password' => 'password123',
            'device_name' => 'test-device',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email', 'emailVerified', 'roles', 'permissions', 'hasOrganizationalScopes', 'hasCustomerAccess', 'hasSiteAccess'],
            ]);

        expect($response->json('user.email'))->toBe($email);
        expect($response->json('user.emailVerified'))->toBeTrue();
        expect($user->tokens()->count())->toBe(1);
        expect($user->tokens()->first()?->can(User::API_ACCESS_ABILITY))->toBeTrue();
    });

    test('token endpoint issues tokens with the explicit api access ability only', function () {
        $email = 'token-ability-'.Str::uuid().'@secpal.dev';

        $user = User::factory()->create([
            'email' => $email,
            'password' => bcrypt('password123'),
        ]);

        $this->postJson('/v1/auth/token', [
            'email' => $email,
            'password' => 'password123',
            'device_name' => 'ability-check',
        ])->assertCreated();

        $token = $user->fresh()->tokens()->sole();

        expect($token->can(User::API_ACCESS_ABILITY))->toBeTrue()
            ->and($token->can('employees.write'))->toBeFalse();
    });

    test('token generation fails with invalid email', function () {
        $response = $this->postJson('/v1/auth/token', [
            'email' => 'nonexistent@secpal.dev',
            'password' => 'password123',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    test('token generation fails with invalid password', function () {
        $email = 'token-invalid-password-'.Str::uuid().'@secpal.dev';

        User::factory()->create([
            'email' => $email,
            'password' => bcrypt('correct-password'),
        ]);

        $response = $this->postJson('/v1/auth/token', [
            'email' => $email,
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    test('token generation requires email', function () {
        $response = $this->postJson('/v1/auth/token', [
            'password' => 'password123',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    test('token generation requires password', function () {
        $email = 'token-missing-password-'.Str::uuid().'@secpal.dev';

        $response = $this->postJson('/v1/auth/token', [
            'email' => $email,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    });

    test('token generation uses default device name when not provided', function () {
        $email = 'test-'.Str::uuid().'@secpal.dev';

        $user = User::factory()->create([
            'email' => $email,
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/v1/auth/token', [
            'email' => $email,
            'password' => 'password123',
        ]);

        $response->assertCreated();
        expect($user->tokens()->first()?->name)->toBe('api-client');
    });

    test('token generation trims surrounding whitespace from device name', function () {
        $email = 'token-trimmed-device-'.Str::uuid().'@secpal.dev';

        $user = User::factory()->create([
            'email' => $email,
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/v1/auth/token', [
            'email' => $email,
            'password' => 'password123',
            'device_name' => '  android-phone  ',
        ]);

        $response->assertCreated();
        expect($user->fresh()->tokens()->first()?->name)->toBe('android-phone');
    });

    test('token generation falls back to default device name when device name is blank', function () {
        $email = 'token-blank-device-'.Str::uuid().'@secpal.dev';

        $user = User::factory()->create([
            'email' => $email,
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/v1/auth/token', [
            'email' => $email,
            'password' => 'password123',
            'device_name' => '   ',
        ]);

        $response->assertCreated();
        expect($user->fresh()->tokens()->first()?->name)->toBe('api-client');
    });

    test('user can generate multiple tokens for different devices', function () {
        $email = 'test-'.Str::uuid().'@secpal.dev';

        $user = User::factory()->create([
            'email' => $email,
            'password' => bcrypt('password123'),
        ]);

        $this->postJson('/v1/auth/token', [
            'email' => $email,
            'password' => 'password123',
            'device_name' => 'mobile',
        ])->assertCreated();

        $this->postJson('/v1/auth/token', [
            'email' => $email,
            'password' => 'password123',
            'device_name' => 'desktop',
        ])->assertCreated();

        expect($user->tokens()->count())->toBe(2);
        expect($user->tokens()->pluck('name')->toArray())->toContain('mobile', 'desktop');
    });
});

describe('Protected Endpoints', function () {
    test('protected endpoint requires authentication', function () {
        $response = $this->getJson('/v1/me');

        $response->assertUnauthorized();
    });

    test('protected endpoint works with valid token', function () {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@secpal.dev',
        ]);

        $token = $user->createToken('test-device')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/me');

        $response->assertOk()
            ->assertJson([
                'id' => $user->id,
                'name' => 'John Doe',
                'email' => 'john@secpal.dev',
            ]);
    });

    test('protected endpoint rejects bearer tokens without the required api access ability', function () {
        $user = User::factory()->create();
        $token = $user->createToken('limited-device', ['profile.read'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/me')
            ->assertForbidden();
    });

    test('protected endpoint rejects invalid token', function () {
        $response = $this->withHeader('Authorization', 'Bearer invalid-token-here')
            ->getJson('/v1/me');

        $response->assertUnauthorized();
    });

    test('unsupported auth and self-service aliases remain undefined', function (string $path) {
        $user = User::factory()->create();
        $token = $user->createToken('test-device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson($path)
            ->assertNotFound();
    })->with([
        '/v1/auth/me',
        '/v1/user',
        '/v1/user/profile',
        '/v1/profile',
    ]);
});

describe('Token Revocation', function () {
    test('user can logout and revoke current token', function () {
        $user = User::factory()->create([
            'email' => 'test@secpal.dev',
            'password' => bcrypt('password123'),
        ]);

        $token = $user->createToken('device-1')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/auth/logout');

        $response->assertOk()
            ->assertJson(['message' => 'Logged out successfully']);

        expect($user->tokens()->count())->toBe(0);
    });

    test('user can logout from all devices', function () {
        $user = User::factory()->create([
            'email' => 'test@secpal.dev',
            'password' => bcrypt('password123'),
            'remember_token' => 'persist-me',
        ]);

        $token1 = $user->createToken('device-1')->plainTextToken;
        $user->createToken('device-2');
        $user->createToken('device-3');

        DB::table('sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Pest',
            'payload' => base64_encode('logout-all-session'),
            'last_activity' => now()->timestamp,
        ]);

        expect($user->tokens()->count())->toBe(3);

        $response = $this->withHeader('Authorization', "Bearer {$token1}")
            ->postJson('/v1/auth/logout-all');

        $response->assertOk()
            ->assertJson(['message' => 'All tokens revoked successfully']);

        $refreshedUser = $user->fresh();

        expect($refreshedUser->tokens()->count())->toBe(0)
            ->and($refreshedUser->remember_token)->toBeNull()
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);

        $activity = Activity::query()
            ->where('log_name', 'authentication')
            ->where('description', 'User logged out from all devices')
            ->latest('id')
            ->first();

        expect($activity)->toBeInstanceOf(Activity::class)
            ->and($activity->properties['event'])->toBe('logout_all');
    });

    test('logout-all via spa session invalidates the browser session', function () {
        $email = 'spa-logout-all-'.Str::uuid().'@secpal.dev';

        $user = User::factory()->create([
            'email' => $email,
            'password' => bcrypt('password123'),
            'remember_token' => 'persist-me',
        ]);

        // Log in via SPA to create a real session (no Bearer token)
        $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/login', [
            'email' => $email,
            'password' => 'password123',
        ])->assertOk();

        // Call logout-all via SPA session (currentAccessToken() returns null → session path)
        $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/logout-all')
            ->assertOk()
            ->assertJson(['message' => 'All tokens revoked successfully']);

        $refreshedUser = $user->fresh();
        expect($refreshedUser->remember_token)->toBeNull()
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);

        // Reset cached auth guards so the next request reads the invalidated session
        $this->flushHeaders();
        $this->app->make('auth')->forgetGuards();

        // Session is fully invalidated: SPA request without Bearer must return 401
        $this->withHeaders(spaHeaders())
            ->getJson('/v1/me')
            ->assertUnauthorized();
    });

    test('logout successfully deletes token from database', function () {
        $user = User::factory()->create();
        $token = $user->createToken('test-device')->plainTextToken;

        // Token exists before logout
        expect($user->tokens()->count())->toBe(1);

        // Logout (revoke token)
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/auth/logout')
            ->assertOk();

        // Token deleted after logout
        expect($user->fresh()->tokens()->count())->toBe(0);
    });

    test('logout handles already-deleted token gracefully', function () {
        $user = User::factory()->create();
        $token1 = $user->createToken('device-1');
        $token2 = $user->createToken('device-2');

        // Simulate race condition: delete token2 manually (e.g., concurrent logout)
        $token2->accessToken->delete();

        // Now logout with token1, but mock currentAccessToken to return null
        // This tests the controller's null handling directly
        $response = $this->withHeader('Authorization', "Bearer {$token1->plainTextToken}")
            ->postJson('/v1/auth/logout');

        // Should succeed without crashing (200 OK)
        $response->assertOk()
            ->assertJson(['message' => 'Logged out successfully']);

        // Token1 should be deleted
        expect($user->fresh()->tokens()->count())->toBe(0);
    });

    test('logout requires authentication', function () {
        $response = $this->postJson('/v1/auth/logout');

        $response->assertUnauthorized();
    });

    test('logout-all requires authentication', function () {
        $response = $this->postJson('/v1/auth/logout-all');

        $response->assertUnauthorized();
    });

    test('legacy session logout alias rejects bearer-token clients', function () {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $token = $user->createToken('mobile-device')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/v1/auth/session/logout')
            ->assertUnauthorized();

        // Token must still be intact — legacy alias must not revoke it
        expect($user->fresh()->tokens()->count())->toBe(1);
    });
});

describe('Token Security', function () {
    test('token does not expose sensitive user data', function () {
        $user = User::factory()->create([
            'password' => bcrypt('secret-password'),
        ]);

        $response = $this->postJson('/v1/auth/token', [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $response->assertCreated()
            ->assertJsonMissing(['password'])
            ->assertJsonMissing(['remember_token']);
    });

    test('protected endpoint does not expose sensitive user data', function () {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/me');

        $response->assertOk()
            ->assertJsonMissing(['password'])
            ->assertJsonMissing(['remember_token']);
    });

    test('token is stored hashed in database', function () {
        $email = 'test-'.Str::uuid().'@secpal.dev';

        $user = User::factory()->create([
            'email' => $email,
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/v1/auth/token', [
            'email' => $email,
            'password' => 'password123',
        ]);

        $plainTextToken = $response->json('token');
        $tokenRecord = $user->tokens()->first();

        // Plain text token should not match database token
        expect($tokenRecord->token)->not->toBe($plainTextToken);
        // Database token should be hashed (64 chars for SHA-256)
        expect(strlen($tokenRecord->token))->toBe(64);
    });

    test('token endpoint prefixes newly issued tokens for secret scanning', function () {
        $email = 'token-prefix-'.Str::uuid().'@secpal.dev';

        $user = User::factory()->create([
            'email' => $email,
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/v1/auth/token', [
            'email' => $email,
            'password' => 'password123',
        ]);

        $response->assertCreated();

        $plainTextToken = $response->json('token');
        $tokenPrefix = config('sanctum.token_prefix');

        expect($plainTextToken)->toBeString();
        expect($plainTextToken)->toContain('|');

        [$tokenId, $tokenSecret] = explode('|', $plainTextToken, 2);

        expect($tokenPrefix)->toBe('sec_');
        expect($tokenId)->not->toBe('');
        expect(Str::startsWith($tokenSecret, $tokenPrefix))->toBeTrue();
        expect($user->tokens()->first()?->token)->not->toBe($plainTextToken);
    });
});

describe('Email Verification', function () {
    test('auth payloads expose the email verification state for unverified browser sessions', function () {
        User::factory()->unverified()->create([
            'email' => 'unverified-spa@secpal.dev',
            'password' => bcrypt('password123'),
        ]);

        $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/login', [
            'email' => 'unverified-spa@secpal.dev',
            'password' => 'password123',
        ])->assertOk()
            ->assertJson([
                'user' => [
                    'email' => 'unverified-spa@secpal.dev',
                    'emailVerified' => false,
                ],
            ]);

        $this->withHeaders(spaHeaders())
            ->getJson('/v1/me')
            ->assertOk()
            ->assertJson([
                'email' => 'unverified-spa@secpal.dev',
                'emailVerified' => false,
            ]);
    });

    test('unverified users can request a fresh verification email', function () {
        Notification::fake();

        $user = User::factory()->unverified()->create();
        $token = $user->createToken('test-device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/auth/email/verification-notification')
            ->assertStatus(202)
            ->assertJson([
                'message' => 'Verification link sent successfully.',
            ]);

        Notification::assertSentTo($user, VerifyEmail::class);
    });

    test('verified users receive 200 when resending email verification', function () {
        $user = User::factory()->create();
        $token = $user->createToken('test-device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJson([
                'message' => 'Email address is already verified.',
            ]);
    });

    test('verification notification requires authentication', function () {
        $this->postJson('/v1/auth/email/verification-notification')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    });

    test('verification resend is throttled after six requests per minute', function () {
        Cache::flush();
        Notification::fake();

        $user = User::factory()->unverified()->create();
        $token = $user->createToken('test-device')->plainTextToken;

        for ($i = 0; $i < 6; $i++) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson('/v1/auth/email/verification-notification')
                ->assertStatus(202);
        }

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/auth/email/verification-notification')
            ->assertStatus(429)
            ->assertJson([
                'message' => 'Too Many Attempts.',
            ]);
    });

    test('signed verification links mark the user email as verified', function () {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = (string) parse_url($url, PHP_URL_QUERY);

        $this->getJson("{$path}?{$query}")
            ->assertOk()
            ->assertJson([
                'message' => 'Email address verified successfully.',
            ]);

        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    });

    test('unverified users cannot access verified-only protected routes', function () {
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('test-device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/me/mfa')
            ->assertForbidden()
            ->assertJson([
                'message' => 'Your email address is not verified.',
            ]);
    });
});

describe('Login Rate Limiting', function () {
    beforeEach(function () {
        // Clear rate limiter cache between tests
        // RateLimiter::clear('login') doesn't work because it expects full key like 'login:ip|email'
        // Using Cache::flush() ensures clean state for each test
        Cache::flush();
    });

    test('token endpoint is rate limited after 5 failed attempts', function () {
        User::factory()->create([
            'email' => 'test@secpal.dev',
            'password' => bcrypt('correct-password'),
        ]);

        // Make 5 failed token attempts (the limit)
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/v1/auth/token', [
                'email' => 'test@secpal.dev',
                'password' => 'wrong-password',
            ]);

            $response->assertUnprocessable();
        }

        // 6th attempt should be rate limited
        $response = $this->postJson('/v1/auth/token', [
            'email' => 'test@secpal.dev',
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests();
        expect($response->json('message'))->toMatch('/^Too many login attempts\. Please try again in \d+ seconds\./');
    });

    test('token login reaches lockout even when attempts are spread over the lockout window', function () {
        User::factory()->create([
            'email' => 'slow-token-lockout@secpal.dev',
            'password' => bcrypt('correct-password'),
        ]);

        // Simulate ~30 seconds between each attempt — total time ≈150 seconds.
        // With a 60-second window this would silently reset; the lockout window
        // must be long enough to capture all five wrong-password attempts.
        $expectedRemaining = ['4', '3', '2', '1', '0'];
        foreach ($expectedRemaining as $expected) {
            $response = $this->postJson('/v1/auth/token', [
                'email' => 'slow-token-lockout@secpal.dev',
                'password' => 'wrong-password',
            ]);

            $response->assertUnprocessable()
                ->assertHeader('X-RateLimit-Remaining', $expected);

            $this->travel(30)->seconds();
        }

        $lockout = $this->postJson('/v1/auth/token', [
            'email' => 'slow-token-lockout@secpal.dev',
            'password' => 'wrong-password',
        ]);

        $lockout->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Remaining', '0');

        expect((int) $lockout->headers->get('Retry-After'))->toBeGreaterThan(0);
    });

    test('token login remaining counter progresses monotonically before the first lockout response', function () {
        User::factory()->create([
            'email' => 'token-sequence@secpal.dev',
            'password' => bcrypt('correct-password'),
        ]);

        $expectedRemainingSequence = ['4', '3', '2', '1', '0'];

        foreach ($expectedRemainingSequence as $expectedRemaining) {
            $response = $this->postJson('/v1/auth/token', [
                'email' => 'token-sequence@secpal.dev',
                'password' => 'wrong-password',
            ]);

            $response->assertUnprocessable()
                ->assertHeader('X-RateLimit-Limit', '5')
                ->assertHeader('X-RateLimit-Remaining', $expectedRemaining);
        }

        $lockoutResponse = $this->postJson('/v1/auth/token', [
            'email' => 'token-sequence@secpal.dev',
            'password' => 'wrong-password',
        ]);

        $lockoutResponse->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        expect((int) $lockoutResponse->headers->get('Retry-After'))->toBeGreaterThan(0)
            ->and($lockoutResponse->headers->get('X-RateLimit-Reset'))->not->toBeNull();
    });

    test('token endpoint is rate limited after 5 failed attempts for a non-existent account too', function () {
        $email = 'definitely-no-account-'.Str::uuid().'@secpal.dev';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/token', [
                'email' => $email,
                'password' => 'wrong-password',
            ])->assertUnprocessable()
                ->assertJsonValidationErrors(['email']);
        }

        $response = $this->postJson('/v1/auth/token', [
            'email' => $email,
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
            ->and($response->headers->get('X-RateLimit-Reset'))->not->toBeNull();
    });

    test('token login keeps the same error envelope for existing and non-existent accounts', function () {
        User::factory()->create([
            'email' => 'known-token-user@secpal.dev',
            'password' => bcrypt('correct-password'),
        ]);

        $existingResponse = $this->postJson('/v1/auth/token', [
            'email' => 'known-token-user@secpal.dev',
            'password' => 'wrong-password',
        ]);

        $unknownResponse = $this->postJson('/v1/auth/token', [
            'email' => 'unknown-token-user-'.Str::uuid().'@secpal.dev',
            'password' => 'wrong-password',
        ]);

        $existingResponse->assertUnprocessable();
        $unknownResponse->assertUnprocessable();

        expect($unknownResponse->json())->toEqual($existingResponse->json());
    });

    test('token login runs password hashing for non-existent accounts to neutralize timing oracle', function () {
        Hash::partialMock()
            ->shouldReceive('check')
            ->once()
            ->withArgs(fn ($password, $hash): bool => $password === 'whatever-password'
                && is_string($hash)
                && password_get_info($hash)['algoName'] === 'bcrypt')
            ->andReturnFalse();

        $this->postJson('/v1/auth/token', [
            'email' => 'absent-token-user-'.Str::uuid().'@secpal.dev',
            'password' => 'whatever-password',
        ])->assertUnprocessable();
    });

    test('unknown-account placeholder hash cache key tracks hashing configuration', function () {
        $controller = app(AuthController::class);
        $method = new ReflectionMethod($controller, 'dummyPasswordHash');
        $method->setAccessible(true);
        $cacheKeys = [];

        Cache::shouldReceive('rememberForever')
            ->twice()
            ->withArgs(function ($key, $callback) use (&$cacheKeys): bool {
                $cacheKeys[] = $key;

                return is_string($key) && is_callable($callback);
            })
            ->andReturn('$2y$12$fAJGA/LIzR7AAtIjg4UYxuj6V0hnGJxYaEB5pvNIjO9CJt6KPU8Hy');

        config(['hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 12]]]);
        $method->invoke($controller);

        config(['hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 13]]]);
        $method->invoke($controller);

        expect($cacheKeys)->toHaveCount(2)
            ->and($cacheKeys[0])->not->toBe($cacheKeys[1]);
    });

    test('unknown-account placeholder hash falls back when cache is unavailable', function () {
        $controller = app(AuthController::class);
        $method = new ReflectionMethod($controller, 'dummyPasswordHash');
        $method->setAccessible(true);

        Cache::shouldReceive('rememberForever')
            ->once()
            ->andThrow(new RuntimeException('cache unavailable'));

        $hash = $method->invoke($controller);

        expect(password_get_info($hash)['algoName'])->toBe('bcrypt')
            ->and(Hash::check('secpal-timing-protection-placeholder', $hash))->toBeTrue();
    });

    test('token login MFA challenges do not consume the wrong-password throttle bucket', function () {
        $user = User::factory()->create([
            'email' => 'mfa-token-limit@secpal.dev',
            'password' => bcrypt('correct-password'),
        ]);

        $user->createTwoFactorAuth();
        expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();

        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/v1/auth/token', [
                'email' => 'mfa-token-limit@secpal.dev',
                'password' => 'correct-password',
                'device_name' => 'android-phone',
            ]);

            $response->assertStatus(202)
                ->assertHeader('X-RateLimit-Limit', '5')
                ->assertHeader('X-RateLimit-Remaining', '5');
        }
    });

    test('token login lockout responses include retry headers', function () {
        User::factory()->create([
            'email' => 'retry-token@secpal.dev',
            'password' => bcrypt('correct-password'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/token', [
                'email' => 'retry-token@secpal.dev',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $response = $this->postJson('/v1/auth/token', [
            'email' => 'retry-token@secpal.dev',
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
            ->and($response->headers->get('X-RateLimit-Reset'))->not->toBeNull();
    });

    test('token login stays rate limited throughout the active cooldown window', function () {
        User::factory()->create([
            'email' => 'stable-token-lockout@secpal.dev',
            'password' => bcrypt('correct-password'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/token', [
                'email' => 'stable-token-lockout@secpal.dev',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $firstLockout = $this->postJson('/v1/auth/token', [
            'email' => 'stable-token-lockout@secpal.dev',
            'password' => 'wrong-password',
        ]);

        $firstLockout->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $firstRetryAfter = (int) $firstLockout->headers->get('Retry-After');
        expect($firstRetryAfter)->toBeGreaterThan(1);

        $this->travel(2)->seconds();

        $secondLockout = $this->postJson('/v1/auth/token', [
            'email' => 'stable-token-lockout@secpal.dev',
            'password' => 'wrong-password',
        ]);

        $secondLockout->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $secondRetryAfter = (int) $secondLockout->headers->get('Retry-After');
        expect($secondRetryAfter)->toBeGreaterThan(0)
            ->and($secondRetryAfter)->toBeLessThan($firstRetryAfter);

        $this->travel(2)->seconds();

        $thirdLockout = $this->postJson('/v1/auth/token', [
            'email' => 'stable-token-lockout@secpal.dev',
            'password' => 'wrong-password',
        ]);

        $thirdLockout->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $thirdRetryAfter = (int) $thirdLockout->headers->get('Retry-After');
        expect($thirdRetryAfter)->toBeGreaterThan(0)
            ->and($thirdRetryAfter)->toBeLessThan($secondRetryAfter);
    });

    test('token login resumes with a fresh counter after the cooldown expires', function () {
        User::factory()->create([
            'email' => 'token-cooldown-reset@secpal.dev',
            'password' => bcrypt('correct-password'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/token', [
                'email' => 'token-cooldown-reset@secpal.dev',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $lockoutResponse = $this->postJson('/v1/auth/token', [
            'email' => 'token-cooldown-reset@secpal.dev',
            'password' => 'wrong-password',
        ]);

        $lockoutResponse->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->travel(301)->seconds();

        $postCooldownAttemptOne = $this->postJson('/v1/auth/token', [
            'email' => 'token-cooldown-reset@secpal.dev',
            'password' => 'wrong-password',
        ]);

        $postCooldownAttemptOne->assertUnprocessable()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '4');

        $postCooldownAttemptTwo = $this->postJson('/v1/auth/token', [
            'email' => 'token-cooldown-reset@secpal.dev',
            'password' => 'wrong-password',
        ]);

        $postCooldownAttemptTwo->assertUnprocessable()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '3');
    });

    test('session and token logins share the same pre-auth account lockout state', function () {
        User::factory()->create([
            'email' => 'cross-mode-lockout@secpal.dev',
            'password' => bcrypt('correct-password'),
        ]);

        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/v1/auth/token', [
                'email' => 'cross-mode-lockout@secpal.dev',
                'password' => 'wrong-password',
            ]);

            $response->assertUnprocessable()
                ->assertHeader('X-RateLimit-Limit', '5')
                ->assertHeader('X-RateLimit-Remaining', (string) (4 - $i));
        }

        $headers = spaCsrfHeaders($this);

        $sessionAttemptOne = $this->withHeaders($headers)
            ->postJson('/v1/auth/login', [
                'email' => 'cross-mode-lockout@secpal.dev',
                'password' => 'wrong-password',
            ]);

        $sessionAttemptOne->assertUnprocessable()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '1');

        $sessionAttemptTwo = $this->withHeaders($headers)
            ->postJson('/v1/auth/login', [
                'email' => 'cross-mode-lockout@secpal.dev',
                'password' => 'wrong-password',
            ]);

        $sessionAttemptTwo->assertUnprocessable()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $tokenLockout = $this->postJson('/v1/auth/token', [
            'email' => 'cross-mode-lockout@secpal.dev',
            'password' => 'wrong-password',
        ]);

        $tokenLockout->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $tokenRetryAfter = (int) $tokenLockout->headers->get('Retry-After');
        expect($tokenRetryAfter)->toBeGreaterThan(0);

        $sessionLockout = $this->withHeaders(spaCsrfHeaders($this))
            ->postJson('/v1/auth/login', [
                'email' => 'cross-mode-lockout@secpal.dev',
                'password' => 'wrong-password',
            ]);

        $sessionLockout->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $sessionRetryAfter = (int) $sessionLockout->headers->get('Retry-After');
        expect($sessionRetryAfter)->toBeGreaterThan(0)
            ->and($sessionRetryAfter)->toBeLessThanOrEqual($tokenRetryAfter);
    });

    test('rate limit is per email and IP combination', function () {
        User::factory()->create([
            'email' => 'user1@secpal.dev',
            'password' => bcrypt('password'),
        ]);
        User::factory()->create([
            'email' => 'user2@secpal.dev',
            'password' => bcrypt('password'),
        ]);

        // Exhaust rate limit for user1
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/token', [
                'email' => 'user1@secpal.dev',
                'password' => 'wrong',
            ]);
        }

        // user1 should be rate limited
        $response = $this->postJson('/v1/auth/token', [
            'email' => 'user1@secpal.dev',
            'password' => 'wrong',
        ]);
        $response->assertTooManyRequests();

        // user2 should NOT be rate limited (different email)
        $response = $this->postJson('/v1/auth/token', [
            'email' => 'user2@secpal.dev',
            'password' => 'wrong',
        ]);
        $response->assertUnprocessable(); // 422, not 429
    });

    test('successful login resets rate limit counter', function () {
        User::factory()->create([
            'email' => 'test@secpal.dev',
            'password' => bcrypt('correct-password'),
        ]);

        // Make 3 failed attempts (not exhausting the limit)
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/v1/auth/token', [
                'email' => 'test@secpal.dev',
                'password' => 'wrong-password',
            ]);
        }

        // Successful login should work
        $response = $this->postJson('/v1/auth/token', [
            'email' => 'test@secpal.dev',
            'password' => 'correct-password',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email', 'roles', 'permissions', 'hasOrganizationalScopes', 'hasCustomerAccess', 'hasSiteAccess'],
            ]);
    });

    test('rate limit applies to email regardless of password', function () {
        User::factory()->create([
            'email' => 'test@secpal.dev',
            'password' => bcrypt('password'),
        ]);

        // Exhaust rate limit with different wrong passwords
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/token', [
                'email' => 'test@secpal.dev',
                'password' => 'wrong'.$i,
            ]);
        }

        // 6th attempt with yet another password should still be blocked
        $response = $this->postJson('/v1/auth/token', [
            'email' => 'test@secpal.dev',
            'password' => 'different-wrong',
        ]);

        $response->assertTooManyRequests();
    });

    test('same email from different IPs still hits the shared pre-auth account lockout', function () {
        User::factory()->create([
            'email' => 'test@secpal.dev',
            'password' => bcrypt('password'),
        ]);

        // Exhaust the shared pre-auth lockout bucket while the apparent client IP changes.
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.'.($i + 1)])
                ->postJson('/v1/auth/token', [
                    'email' => 'test@secpal.dev',
                    'password' => 'wrong',
                ])->assertUnprocessable();
        }

        // A sixth attempt from a brand-new IP must still hit the same temporary lockout.
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.99'])
            ->postJson('/v1/auth/token', [
                'email' => 'test@secpal.dev',
                'password' => 'wrong',
            ]);

        $response->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
            ->and($response->headers->get('X-RateLimit-Reset'))->not->toBeNull();
    });

    test('session login lockout survives fresh csrf cookies and changing ips', function () {
        User::factory()->create([
            'email' => 'session-rotating@secpal.dev',
            'password' => bcrypt('password'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $headers = spaHeaders([
                'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
            ]);

            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.($i + 1)])
                ->withHeaders($headers)
                ->postJson('/v1/auth/login', [
                    'email' => 'session-rotating@secpal.dev',
                    'password' => 'wrong',
                ])->assertUnprocessable();
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->withHeaders(spaHeaders([
                'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
            ]))
            ->postJson('/v1/auth/login', [
                'email' => 'session-rotating@secpal.dev',
                'password' => 'wrong',
            ]);

        $response->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
            ->and($response->headers->get('X-RateLimit-Reset'))->not->toBeNull();
    });

    test('session login endpoint is also rate limited', function () {
        User::factory()->create([
            'email' => 'session-test@secpal.dev',
            'password' => bcrypt('password'),
        ]);

        // Make 5 failed login attempts with a real SPA request context.
        $xsrfToken = issueSpaCsrfToken($this);
        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders(spaHeaders([
                'X-XSRF-TOKEN' => $xsrfToken,
            ]))
                ->postJson('/v1/auth/login', [
                    'email' => 'session-test@secpal.dev',
                    'password' => 'wrong',
                ]);
        }

        // 6th attempt should be rate limited
        $response = $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => $xsrfToken,
        ]))
            ->postJson('/v1/auth/login', [
                'email' => 'session-test@secpal.dev',
                'password' => 'wrong',
            ]);

        $response->assertTooManyRequests();
    });

    test('session login endpoint is also rate limited for a non-existent account', function () {
        $email = 'definitely-no-session-account-'.Str::uuid().'@secpal.dev';
        $xsrfToken = issueSpaCsrfToken($this);

        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders(spaHeaders([
                'X-XSRF-TOKEN' => $xsrfToken,
            ]))
                ->postJson('/v1/auth/login', [
                    'email' => $email,
                    'password' => 'wrong',
                ])->assertUnprocessable()
                ->assertJsonValidationErrors(['email']);
        }

        $response = $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => $xsrfToken,
        ]))
            ->postJson('/v1/auth/login', [
                'email' => $email,
                'password' => 'wrong',
            ]);

        $response->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
            ->and($response->headers->get('X-RateLimit-Reset'))->not->toBeNull();
    });

    test('session login keeps the same error envelope for existing and non-existent accounts', function () {
        User::factory()->create([
            'email' => 'known-session-user@secpal.dev',
            'password' => bcrypt('correct-password'),
        ]);

        $headers = spaCsrfHeaders($this);

        $existingResponse = $this->withHeaders($headers)
            ->postJson('/v1/auth/login', [
                'email' => 'known-session-user@secpal.dev',
                'password' => 'wrong-password',
            ]);

        $unknownResponse = $this->withHeaders($headers)
            ->postJson('/v1/auth/login', [
                'email' => 'unknown-session-user-'.Str::uuid().'@secpal.dev',
                'password' => 'wrong-password',
            ]);

        $existingResponse->assertUnprocessable();
        $unknownResponse->assertUnprocessable();

        expect($unknownResponse->json())->toEqual($existingResponse->json());
    });

    test('session login runs password hashing for non-existent accounts to neutralize timing oracle', function () {
        Hash::partialMock()
            ->shouldReceive('check')
            ->once()
            ->withArgs(fn ($password, $hash): bool => $password === 'whatever-password'
                && is_string($hash)
                && password_get_info($hash)['algoName'] === 'bcrypt')
            ->andReturnFalse();

        $this->withHeaders(spaCsrfHeaders($this))
            ->postJson('/v1/auth/login', [
                'email' => 'absent-session-user-'.Str::uuid().'@secpal.dev',
                'password' => 'whatever-password',
            ])
            ->assertUnprocessable();
    });

    test('session login MFA challenges do not consume the wrong-password throttle bucket', function () {
        $user = User::factory()->create([
            'email' => 'mfa-session-limit@secpal.dev',
            'password' => bcrypt('correct-password'),
        ]);

        $user->createTwoFactorAuth();
        expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();

        $headers = spaCsrfHeaders($this);

        for ($i = 0; $i < 6; $i++) {
            $response = $this->withHeaders($headers)
                ->postJson('/v1/auth/login', [
                    'email' => 'mfa-session-limit@secpal.dev',
                    'password' => 'correct-password',
                ]);

            $response->assertStatus(202)
                ->assertHeader('X-RateLimit-Limit', '5')
                ->assertHeader('X-RateLimit-Remaining', '5');
        }
    });

    test('session login lockout responses include retry headers', function () {
        User::factory()->create([
            'email' => 'retry-session@secpal.dev',
            'password' => bcrypt('correct-password'),
        ]);

        $headers = spaCsrfHeaders($this);

        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($headers)
                ->postJson('/v1/auth/login', [
                    'email' => 'retry-session@secpal.dev',
                    'password' => 'wrong-password',
                ])->assertUnprocessable();
        }

        $response = $this->withHeaders($headers)
            ->postJson('/v1/auth/login', [
                'email' => 'retry-session@secpal.dev',
                'password' => 'wrong-password',
            ]);

        $response->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
            ->and($response->headers->get('X-RateLimit-Reset'))->not->toBeNull();
    });

    test('session login reaches lockout even when attempts are spread over the lockout window', function () {
        User::factory()->create([
            'email' => 'slow-session-lockout@secpal.dev',
            'password' => bcrypt('correct-password'),
        ]);

        $headers = spaCsrfHeaders($this);

        $expectedRemaining = ['4', '3', '2', '1', '0'];
        foreach ($expectedRemaining as $expected) {
            $response = $this->withHeaders($headers)
                ->postJson('/v1/auth/login', [
                    'email' => 'slow-session-lockout@secpal.dev',
                    'password' => 'wrong-password',
                ]);

            $response->assertUnprocessable()
                ->assertHeader('X-RateLimit-Remaining', $expected);

            $this->travel(30)->seconds();
        }

        $lockout = $this->withHeaders($headers)
            ->postJson('/v1/auth/login', [
                'email' => 'slow-session-lockout@secpal.dev',
                'password' => 'wrong-password',
            ]);

        $lockout->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Remaining', '0');

        expect((int) $lockout->headers->get('Retry-After'))->toBeGreaterThan(0);
    });

    test('session login stays rate limited throughout the active cooldown window', function () {
        User::factory()->create([
            'email' => 'stable-session-lockout@secpal.dev',
            'password' => bcrypt('correct-password'),
        ]);

        $headers = spaCsrfHeaders($this);

        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($headers)
                ->postJson('/v1/auth/login', [
                    'email' => 'stable-session-lockout@secpal.dev',
                    'password' => 'wrong-password',
                ])->assertUnprocessable();
        }

        $firstLockout = $this->withHeaders($headers)
            ->postJson('/v1/auth/login', [
                'email' => 'stable-session-lockout@secpal.dev',
                'password' => 'wrong-password',
            ]);

        $firstLockout->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $firstRetryAfter = (int) $firstLockout->headers->get('Retry-After');
        expect($firstRetryAfter)->toBeGreaterThan(1);

        $this->travel(2)->seconds();

        $secondLockout = $this->withHeaders($headers)
            ->postJson('/v1/auth/login', [
                'email' => 'stable-session-lockout@secpal.dev',
                'password' => 'wrong-password',
            ]);

        $secondLockout->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $secondRetryAfter = (int) $secondLockout->headers->get('Retry-After');
        expect($secondRetryAfter)->toBeGreaterThan(0)
            ->and($secondRetryAfter)->toBeLessThan($firstRetryAfter);

        $this->travel(2)->seconds();

        $thirdLockout = $this->withHeaders($headers)
            ->postJson('/v1/auth/login', [
                'email' => 'stable-session-lockout@secpal.dev',
                'password' => 'wrong-password',
            ]);

        $thirdLockout->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $thirdRetryAfter = (int) $thirdLockout->headers->get('Retry-After');
        expect($thirdRetryAfter)->toBeGreaterThan(0)
            ->and($thirdRetryAfter)->toBeLessThan($secondRetryAfter);
    });

    test('session login resumes with a fresh counter after the cooldown expires', function () {
        User::factory()->create([
            'email' => 'session-cooldown-reset@secpal.dev',
            'password' => bcrypt('correct-password'),
        ]);

        $headers = spaCsrfHeaders($this);

        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($headers)
                ->postJson('/v1/auth/login', [
                    'email' => 'session-cooldown-reset@secpal.dev',
                    'password' => 'wrong-password',
                ])->assertUnprocessable();
        }

        $lockoutResponse = $this->withHeaders($headers)
            ->postJson('/v1/auth/login', [
                'email' => 'session-cooldown-reset@secpal.dev',
                'password' => 'wrong-password',
            ]);

        $lockoutResponse->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->travel(301)->seconds();

        $postCooldownAttemptOne = $this->withHeaders(spaCsrfHeaders($this))
            ->postJson('/v1/auth/login', [
                'email' => 'session-cooldown-reset@secpal.dev',
                'password' => 'wrong-password',
            ]);

        $postCooldownAttemptOne->assertUnprocessable()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '4');

        $postCooldownAttemptTwo = $this->withHeaders(spaCsrfHeaders($this))
            ->postJson('/v1/auth/login', [
                'email' => 'session-cooldown-reset@secpal.dev',
                'password' => 'wrong-password',
            ]);

        $postCooldownAttemptTwo->assertUnprocessable()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '3');
    });
});

describe('Unauthenticated Request Handling', function () {
    test('unauthenticated request to protected endpoint returns 401 JSON response', function () {
        // Issue #253: API should return 401 JSON, not 500 "Route [login] not defined"
        $response = $this->getJson('/v1/me');

        $response->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    });

    test('request with invalid token returns 401 JSON response', function () {
        $response = $this->withHeader('Authorization', 'Bearer invalid-token-12345')
            ->getJson('/v1/me');

        $response->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    });
});

describe('Organizational Scopes Authorization', function () {
    beforeEach(function () {
        clearLoginRateLimiter('noscope@secpal.dev');
        clearLoginRateLimiter('withscope@secpal.dev');
    });

    test('hasOrganizationalScopes is false when user has no scopes', function () {
        $user = User::factory()->create([
            'email' => 'noscope@secpal.dev',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/login', [
            'email' => 'noscope@secpal.dev',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJson([
                'user' => [
                    'hasOrganizationalScopes' => false,
                    'hasCustomerAccess' => false,
                    'hasSiteAccess' => false,
                ],
            ]);
    });

    test('hasOrganizationalScopes is true when user has scopes', function () {
        $tenant = App\Models\TenantKey::factory()->create();
        $orgUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'email' => 'withscope@secpal.dev',
            'password' => bcrypt('password123'),
            'tenant_id' => $tenant->id,
        ]);

        // Create organizational scope
        $user->organizationalScopes()->create([
            'organizational_unit_id' => $orgUnit->id,
            'access_level' => 'write',
            'include_descendants' => false,
            'min_viewable_rank' => 0,
            'max_viewable_rank' => 255,
            'allow_self_access' => true,
        ]);

        $response = $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/login', [
            'email' => 'withscope@secpal.dev',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJson([
                'user' => [
                    'hasOrganizationalScopes' => true,
                ],
            ]);
    });

    test('hasCustomerAccess and hasSiteAccess are true when user has scoped customer-site access', function () {
        $tenant = App\Models\TenantKey::factory()->create();
        $orgUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        Site::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'organizational_unit_id' => $orgUnit->id,
        ]);

        $user = User::factory()->create([
            'email' => 'scoped-access@secpal.dev',
            'password' => bcrypt('password123'),
            'tenant_id' => $tenant->id,
        ]);

        CustomerAssignment::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'role' => 'Key Account',
            'valid_from' => now()->subDay(),
            'valid_until' => null,
        ]);

        clearLoginRateLimiter('scoped-access@secpal.dev');

        $response = $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/login', [
            'email' => 'scoped-access@secpal.dev',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJson([
                'user' => [
                    'hasCustomerAccess' => true,
                    'hasSiteAccess' => true,
                ],
            ]);
    });
});

describe('Tenant-Scoped Roles And Permissions In Authorization Data', function () {
    beforeEach(function (): void {
        incrementTestKekCounter();
        App\Models\TenantKey::setKekPath(getTestKekPath());
        App\Models\TenantKey::generateKek();
    });

    afterEach(function (): void {
        cleanupTestKekFile();
        App\Models\TenantKey::setKekPath(null);
    });

    test('me endpoint returns tenant-scoped roles and permissions', function () {
        // Create tenant
        $keys = App\Models\TenantKey::generateEnvelopeKeys();
        $tenant = App\Models\TenantKey::create($keys);

        // Set tenant context and seed roles/permissions
        $registrar = app(Spatie\Permission\PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenant->id);
        Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

        // Create user with tenant-scoped direct permissions
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        givePermissionWithTenant($user, $tenant->id, 'customers.read');

        // Reset team context and flush permission cache — simulates a fresh
        // authentication request where the authenticated user is only set
        // inside the controller action, so tenant-scoped authorization data
        // is resolved before the tenant context is re-established (the root
        // cause of issue SecPal/frontend#822)
        $registrar->setPermissionsTeamId(null);
        $registrar->forgetCachedPermissions();
        $user->unsetRelation('roles')->unsetRelation('permissions');

        // Act: call /v1/me without tenant middleware
        $token = $user->createToken('test-device')->plainTextToken;
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/me');

        $response->assertOk()
            ->assertJsonPath('roles', fn (array $roles) => $roles === [])
            ->assertJsonPath('permissions', fn (array $perms) => in_array('customers.read', $perms, true));
    });

    test('login response returns tenant-scoped roles and permissions', function () {
        // Create tenant
        $keys = App\Models\TenantKey::generateEnvelopeKeys();
        $tenant = App\Models\TenantKey::create($keys);

        // Set tenant context and seed roles/permissions
        $registrar = app(Spatie\Permission\PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenant->id);
        Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

        // Create user with tenant-scoped direct permissions
        $user = User::factory()->create([
            'email' => 'tenant-roles@secpal.dev',
            'password' => bcrypt('password123'),
            'tenant_id' => $tenant->id,
        ]);
        givePermissionWithTenant($user, $tenant->id, 'customers.read');

        // Reset team context and flush permission cache — simulates a fresh
        // authentication request where the authenticated user is only set
        // inside the controller action, so tenant-scoped authorization data
        // is resolved before the tenant context is re-established
        $registrar->setPermissionsTeamId(null);
        $registrar->forgetCachedPermissions();
        $user->unsetRelation('roles')->unsetRelation('permissions');

        clearLoginRateLimiter('tenant-roles@secpal.dev');

        $response = $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/login', [
            'email' => 'tenant-roles@secpal.dev',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.roles', fn (array $roles) => $roles === [])
            ->assertJsonPath('user.permissions', fn (array $perms) => in_array('customers.read', $perms, true));
    });
});
