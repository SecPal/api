<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('User Language Preference API', function () {
    test('user can update their language preference to German', function () {
        /** @var User $user */
        $user = User::factory()->create(['preferred_locale' => null]);

        $response = $this->actingAs($user)
            ->patchJson('/v1/me/language', [
                'locale' => 'de',
            ]);

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'preferred_locale' => 'de',
                ],
            ]);

        expect($user->fresh()->preferred_locale)->toBe('de');
    });

    test('user can update their language preference to English', function () {
        /** @var User $user */
        $user = User::factory()->create(['preferred_locale' => 'de']);

        $response = $this->actingAs($user)
            ->patchJson('/v1/me/language', [
                'locale' => 'en',
            ]);

        $response->assertOk();
        expect($user->fresh()->preferred_locale)->toBe('en');
    });

    test('user can reset language preference to null', function () {
        /** @var User $user */
        $user = User::factory()->create(['preferred_locale' => 'de']);

        $response = $this->actingAs($user)
            ->patchJson('/v1/me/language', [
                'locale' => null,
            ]);

        $response->assertOk();
        expect($user->fresh()->preferred_locale)->toBeNull();
    });

    test('validation rejects invalid locale codes', function () {
        /** @var User $user */
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->patchJson('/v1/me/language', [
                'locale' => 'fr', // French not supported
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['locale']);
    });

    test('empty string is treated as null and accepted', function () {
        /** @var User $user */
        $user = User::factory()->create(['preferred_locale' => 'de']);

        $response = $this->actingAs($user)
            ->patchJson('/v1/me/language', [
                'locale' => '',
            ]);

        $response->assertOk();
        expect($user->fresh()->preferred_locale)->toBeNull();
    });

    test('validation requires locale field', function () {
        /** @var User $user */
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->patchJson('/v1/me/language', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['locale']);
    });

    test('unauthenticated users cannot update language preference', function () {
        $response = $this->patchJson('/v1/me/language', [
            'locale' => 'de',
        ]);

        $response->assertStatus(401);
    });

    test('endpoint only affects authenticated user', function () {
        /** @var User $user1 */
        $user1 = User::factory()->create(['preferred_locale' => null]);
        /** @var User $user2 */
        $user2 = User::factory()->create(['preferred_locale' => null]);

        $response = $this->actingAs($user1)
            ->patchJson('/v1/me/language', [
                'locale' => 'de',
            ]);

        $response->assertOk();
        expect($user1->fresh()->preferred_locale)->toBe('de');
        expect($user2->fresh()->preferred_locale)->toBeNull();
    });
});
