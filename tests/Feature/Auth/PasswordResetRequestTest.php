<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Jobs\DeliverGlobalIdentityOperation;
use App\Models\GlobalIdentityOperation;
use App\Models\User;
use App\Services\GlobalIdentity\GlobalIdentityDeliveryState;
use App\Services\GlobalIdentity\GlobalIdentityOperationService;
use App\Services\GlobalIdentity\IdentityLinkRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('returns identical neutral responses and only queues an ID for an existing user', function (): void {
    Queue::fake();
    $user = User::factory()->create(['email' => 'reset-member@secpal.dev']);
    $path = '/v1/auth/password-reset-requests';
    $known = $this->postJson($path, ['email' => 'RESET-MEMBER@SECPAL.dev'])->assertAccepted();
    $unknown = $this->postJson($path, ['email' => 'missing-member@secpal.dev'])->assertAccepted();
    $known->assertExactJson(['message' => 'Request accepted.']);
    expect($known->getContent())->toBe($unknown->getContent())
        ->and($known->headers->get('Cache-Control'))->toContain('no-store');
    $operation = GlobalIdentityOperation::query()->sole();
    expect($operation->user_id)->toBe($user->id)->and($operation->email_version)->toBe($user->email_version);
    Queue::assertPushed(DeliverGlobalIdentityOperation::class, 1);
    Queue::assertPushed(DeliverGlobalIdentityOperation::class, fn ($job): bool => $job->operationId === $operation->id
        && ! str_contains(serialize($job), 'reset-member@secpal.dev'));
    config(['app.frontend_url' => 'https://app.secpal.dev']);
    $token = app(GlobalIdentityOperationService::class)->openToken($operation);
    expect(app(IdentityLinkRenderer::class)->render($operation->context(), $token))
        ->toContain('/reset-password#operationId='.$operation->id, 'token='.$token)
        ->not->toContain('reset-member@secpal.dev');
});

it('supersedes outstanding proof and removes the former envelope before queuing a new attempt', function (): void {
    Queue::fake();
    $user = User::factory()->create(['email' => 'reset-member@secpal.dev']);
    $path = '/v1/auth/password-reset-requests';
    $this->postJson($path, ['email' => 'reset-member@secpal.dev'])->assertAccepted();
    $original = GlobalIdentityOperation::query()->sole();
    $this->postJson($path, ['email' => 'reset-member@secpal.dev'])->assertAccepted();

    expect($original->fresh()->delivery_state)->toBe(GlobalIdentityDeliveryState::Terminal)
        ->and($original->fresh()->delivery_token_enc)->toBeNull()
        ->and($original->fresh()->token_hash)->toBeNull()
        ->and(GlobalIdentityOperation::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('keeps the accepted response and rolls back when writing an operation fails', function (): void {
    Queue::fake();
    User::factory()->create(['email' => 'reset-member@secpal.dev']);
    GlobalIdentityOperation::creating(static fn (): bool => false);
    try {
        $this->postJson('/v1/auth/password-reset-requests', ['email' => 'reset-member@secpal.dev'])
            ->assertAccepted()->assertExactJson(['message' => 'Request accepted.']);
        expect(GlobalIdentityOperation::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    } finally {
        GlobalIdentityOperation::flushEventListeners();
    }
});

it('rejects malformed input and keeps a source rate limit', function (): void {
    Queue::fake();
    User::factory()->create();
    $path = '/v1/auth/password-reset-requests';
    $this->postJson($path, [])->assertUnprocessable()->assertJsonValidationErrors('email');
    for ($i = 0; $i < 4; $i++) {
        $this->postJson($path, ['email' => 'unmatched@secpal.dev'])->assertAccepted();
    }
    $this->postJson($path, ['email' => 'unmatched@secpal.dev'])->assertTooManyRequests();
});
