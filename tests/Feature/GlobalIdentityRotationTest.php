<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Contracts\GlobalIdentityRotationAuthority;
use App\Exceptions\GlobalIdentityKeyException;
use App\Jobs\DeliverGlobalIdentityOperation;
use App\Models\GlobalIdentityOperation;
use App\Models\User;
use App\Services\GlobalIdentity\GlobalEmailCodec;
use App\Services\GlobalIdentity\GlobalEmailLookupVersions;
use App\Services\GlobalIdentity\GlobalEmailRepository;
use App\Services\GlobalIdentity\GlobalEmailSchema;
use App\Services\GlobalIdentity\GlobalIdentityAccounts;
use App\Services\GlobalIdentity\GlobalIdentityDeliveryOutcome;
use App\Services\GlobalIdentity\GlobalIdentityDeliveryResult;
use App\Services\GlobalIdentity\GlobalIdentityDeliveryState;
use App\Services\GlobalIdentity\GlobalIdentityEnvelopeCodec;
use App\Services\GlobalIdentity\GlobalIdentityInvitationReissue;
use App\Services\GlobalIdentity\GlobalIdentityKeyReference;
use App\Services\GlobalIdentity\GlobalIdentityKeyReferences;
use App\Services\GlobalIdentity\GlobalIdentityOperationContext;
use App\Services\GlobalIdentity\GlobalIdentityOperationService;
use App\Services\GlobalIdentity\GlobalIdentityOperationSubject;
use App\Services\GlobalIdentity\GlobalIdentityOperationType;
use App\Services\GlobalIdentity\GlobalIdentityRotationCoordinator;
use App\Services\GlobalIdentity\GlobalIdentityRotationKind;
use App\Services\GlobalIdentity\GlobalIdentityRotationPlan;
use App\Services\GlobalIdentity\GlobalIdentityTerminalJournalEntry;
use App\Services\GlobalIdentity\GlobalIdentityTerminalReason;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyPurpose;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyRegistry;
use App\Services\GlobalIdentity\IdentityLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('re-encrypts a user at the exact active version without rotating address authority', function (): void {
    $address = 'Display+Case@secpal.dev';
    $user = User::factory()->create(['email' => $address]);
    $row = DB::table('users')->where('id', $user->id);
    $before = $row->selectRaw("email_enc, email_version, encode(email_idx, 'hex') AS email_index")->sole();
    $registry = app(GlobalIdentityWorkingKeyRegistry::class);
    $references = app(GlobalIdentityKeyReferences::class);
    $codec = app(GlobalEmailCodec::class);
    $sourceEnvelope = json_decode($before->email_enc, true, flags: JSON_THROW_ON_ERROR);
    $source = $codec->reference($sourceEnvelope);
    $target = $registry->stage(GlobalIdentityWorkingKeyPurpose::EmailEncryption);
    $registry->activate($target);
    expect(fn () => $registry->retire($source, fn (): bool => $references->hasLiveReference($source)))
        ->toThrow(GlobalIdentityKeyException::class);

    $repository = app(GlobalEmailRepository::class);
    expect($repository->reencrypt($user->id))->toBeTrue()
        ->and($repository->reencrypt($user->id))->toBeFalse();
    $after = $row->sole();
    $targetEnvelope = json_decode($after->email_enc, true, flags: JSON_THROW_ON_ERROR);

    expect(app(GlobalEmailCodec::class)->reference($targetEnvelope))->toEqual($target)
        ->and($targetEnvelope['nonce'])->not->toBe($sourceEnvelope['nonce'])
        ->and($after->email_index)->toBe($before->email_index)
        ->and($after->email_version)->toBe($before->email_version)
        ->and(app(GlobalIdentityAccounts::class)->find($address)?->id)->toBe($user->id)
        ->and($user->emailForDisplay())->toBe($address);
    $registry->retire($source, fn (): bool => $references->hasLiveReference($source));
    expect(DB::table('global_identity_working_keys')->where('key_id', $source->reference->id)
        ->where('key_version', $source->reference->version)->value('state'))->toBe('retired')
        ->and(fn () => $registry->withKey($source, fn (): bool => true))->toThrow(GlobalIdentityKeyException::class);
});

it('blocks retirement when a stored encryption reference cannot be reconciled', function (bool $retainId): void {
    $user = User::factory()->create();
    $registry = app(GlobalIdentityWorkingKeyRegistry::class);
    $purpose = GlobalIdentityWorkingKeyPurpose::EmailEncryption;
    $source = $registry->withActiveKey($purpose, static fn (#[SensitiveParameter] string $key, $context) => $context);
    $registry->activate($registry->stage($purpose));
    $row = DB::table('users')->where('id', $user->id);
    $envelope = json_decode($row->value('email_enc'), true, flags: JSON_THROW_ON_ERROR);
    if ($retainId) {
        $envelope['key_version'] = 99;
    } else {
        unset($envelope['key_id']);
    }
    $row->update(['email_enc' => json_encode($envelope, JSON_THROW_ON_ERROR)]);

    $references = app(GlobalIdentityKeyReferences::class);
    expect(fn () => $registry->retire($source, fn (): bool => $references->hasLiveReference($source)))
        ->toThrow(GlobalIdentityKeyException::class)
        ->and($registry->withKey($source, static fn (): bool => true))->toBeTrue();
})->with([false, true]);

it('keeps a paused checkpoint fenced to its approved exact target after another rotation', function (): void {
    $purpose = GlobalIdentityWorkingKeyPurpose::EmailEncryption;
    $registry = app(GlobalIdentityWorkingKeyRegistry::class);
    $from = $registry->activeReference($purpose);
    $initial = (string) Str::uuid();
    $later = (string) Str::uuid();
    $firstTarget = new GlobalIdentityKeyReference($from->id, $from->version + 1);
    $laterTarget = new GlobalIdentityKeyReference($from->id, $from->version + 2);
    $authority = Mockery::mock(GlobalIdentityRotationAuthority::class);
    $authority->shouldReceive('approvedPlan')->with($initial, Mockery::type('string'))
        ->andReturn(new GlobalIdentityRotationPlan($initial, GlobalIdentityRotationKind::Working, $from, $firstTarget, $purpose));
    $authority->shouldReceive('approvedPlan')->with($later, Mockery::type('string'))
        ->andReturn(new GlobalIdentityRotationPlan($later, GlobalIdentityRotationKind::Working, $firstTarget, $laterTarget, $purpose));
    app()->instance(GlobalIdentityRotationAuthority::class, $authority);
    $rotation = app(GlobalIdentityRotationCoordinator::class);
    $rotation->execute($initial, 'start', 10);
    $rotation->execute($initial, 'pause', 10);
    $rotation->execute($later, 'start', 10);
    $rotation->execute($later, 'advance', 10);
    expect($rotation->execute($later, 'complete', 10)['phase'])->toBe('complete')
        ->and(fn () => $rotation->execute($initial, 'resume', 10))->toThrow(GlobalIdentityKeyException::class)
        ->and($rotation->execute($initial, 'status', 10)['phase'])->toBe('paused');
});

it('reads both lookup versions and migrates a verified index without changing the identity', function (): void {
    $email = 'Existing+Index@secpal.dev';
    $accounts = app(GlobalIdentityAccounts::class);
    $existing = User::factory()->create(['email' => $email]);
    $before = DB::table('users')->where('id', $existing->id)->sole(['email_enc', 'email_version']);
    $registry = app(GlobalIdentityWorkingKeyRegistry::class);
    $target = $registry->stage(GlobalIdentityWorkingKeyPurpose::UserEmailLookup);
    $registry->activateLookupRotation($target);

    expect($accounts->find($email)?->id)->toBe($existing->id)
        ->and(fn () => User::factory()->create(['email' => strtolower($email)]))->toThrow(GlobalIdentityKeyException::class);

    $new = User::factory()->create(['email' => 'New+Index@secpal.dev']);
    expect(DB::table('users')->where('id', $new->id)->value('email_idx_key_version'))->toBe(2);

    $repository = app(GlobalEmailRepository::class);
    expect($repository->rotateIndex($existing->id, promote: false))->toBeTrue()
        ->and($repository->rotateIndex($existing->id, promote: true))->toBeTrue()
        ->and($repository->rotateIndex($existing->id, promote: true))->toBeFalse()
        ->and($accounts->find($email)?->id)->toBe($existing->id);
    $after = DB::table('users')->where('id', $existing->id)->sole();
    expect($after->email_idx_key_version)->toBe(2)
        ->and($after->email_enc)->toBe($before->email_enc)
        ->and($after->email_version)->toBe($before->email_version);
});

it('extends a previous reservation and removes old lookup references in bounded batches', function (): void {
    $repo = app(GlobalEmailRepository::class);
    $accounts = app(GlobalIdentityAccounts::class);
    $registry = app(GlobalIdentityWorkingKeyRegistry::class);
    $user = User::factory()->create();
    $operationId = (string) Str::uuid();
    $address = 'Reserved+Rotation@secpal.dev';
    $accounts->withLookupVersions(fn (GlobalEmailLookupVersions $versions) => $repo->reserve($operationId, $address, $versions));
    $target = $registry->stage(GlobalIdentityWorkingKeyPurpose::UserEmailLookup);
    $registry->activateLookupRotation($target);

    $repo->rotateIndex($user->id, promote: false);
    $repo->rotateIndex($user->id, promote: true);
    expect(fn () => $repo->completeLookupRotation($target, 1))->toThrow(GlobalIdentityKeyException::class);

    $accounts->withLookupVersions(fn (GlobalEmailLookupVersions $versions) => $repo->reserve($operationId, $address, $versions));
    expect($repo->completeLookupRotation($target, 1))->toBeFalse();
    $remaining = (string) Str::uuid();
    $accounts->withLookupVersions(function (GlobalEmailLookupVersions $versions) use ($repo, $remaining): void {
        expect($versions->accepted)->toBe([2]);
        $repo->reserve($remaining, 'After+Cutover@secpal.dev', $versions);
    });
    User::factory()->create(['email' => 'During+Cleanup@secpal.dev']);
    expect(DB::table(GlobalEmailSchema::RESERVATIONS)->where('operation_id', $remaining)->pluck('index_key_version')->all())->toBe([2])
        ->and(DB::table(GlobalEmailSchema::ROTATION_INDEXES)->count())->toBe(0);
    expect($repo->completeLookupRotation($target, 1))->toBeTrue()
        ->and(DB::table(GlobalEmailSchema::ROTATION_INDEXES)->count())->toBe(0)
        ->and(DB::table(GlobalEmailSchema::RESERVATIONS)->distinct()->pluck('index_key_version')->all())->toBe([2]);
    expect(fn () => User::factory()->create(['email' => $address]))->toThrow(GlobalIdentityKeyException::class);
});

it('immediately fences a compromised delivery version, including already delivered proofs', function (): void {
    $user = User::factory()->create();
    $registry = app(GlobalIdentityWorkingKeyRegistry::class);
    $purpose = GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret;
    $source = $registry->activeReference($purpose);
    $operations = app(GlobalIdentityOperationService::class);
    $context = new GlobalIdentityOperationContext(
        GlobalIdentityOperationType::PasswordReset,
        (string) Str::uuid(),
        $user->id,
        $user->email_version,
    );
    $operations->create($context, now()->toImmutable()->addMinutes(10));
    $claim = $operations->claim($context->id, 'test');
    expect($claim)->not->toBeNull();
    $token = $operations->openToken($claim);
    expect($operations->commitDispatch($claim))->toBeTrue();
    $operations->recordResult($claim, new GlobalIdentityDeliveryResult(GlobalIdentityDeliveryOutcome::Accepted));

    $registry->blockDeliveryVersion($source, GlobalIdentityTerminalReason::SecurityRevoked);
    expect($operations->consume($context->id, $token))->toBeFalse()
        ->and($registry->activeReference($purpose))->toEqual($source)
        ->and(fn () => $registry->withActiveKey($purpose, fn (): bool => true))->toThrow(GlobalIdentityKeyException::class);

    $operations->invalidate($context->id, GlobalIdentityTerminalReason::SecurityRevoked);
    $row = GlobalIdentityOperation::query()->findOrFail($context->id);
    expect($row->terminal_reason)->toBe('security_revoked')
        ->and($row->token_hash)->toBeNull()
        ->and($row->delivery_token_enc)->toBeNull();

    $next = $registry->stageBlockedDeliveryReplacement($source);
    expect(fn () => $registry->activate($next))->toThrow(GlobalIdentityKeyException::class);
    $registry->activateDeliveryReplacement($next, fn (): bool => ! GlobalIdentityOperation::query()->whereNotNull('token_hash')->exists());
    expect($registry->activeReference($purpose))->toEqual($next->reference)
        ->and($operations->consume($context->id, $token))->toBeFalse();
});

it('revokes an approved operation before activation and replaces it with a new incident-bound proof', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $operations = app(GlobalIdentityOperationService::class);
    $registry = app(GlobalIdentityWorkingKeyRegistry::class);
    $purpose = GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret;
    $source = $registry->activeReference($purpose);
    $target = new GlobalIdentityKeyReference($source->id, $source->version + 1);
    $operation = new GlobalIdentityOperationContext(
        GlobalIdentityOperationType::PasswordReset, (string) Str::uuid(), $user->id, $user->email_version,
    );
    $operations->create($operation, now()->toImmutable()->addMinutes(10));
    $old = GlobalIdentityOperation::query()->findOrFail($operation->id);
    $token = $operations->openToken($old);
    $verifier = $old->token_hash;
    $id = (string) Str::uuid();
    $plan = new GlobalIdentityRotationPlan($id, GlobalIdentityRotationKind::DeliveryCompromise, $source, $target, $purpose, [$old->id]);
    $authority = Mockery::mock(GlobalIdentityRotationAuthority::class);
    $authority->shouldReceive('approvedPlan')->with($id, Mockery::type('string'))->andReturn($plan);
    app()->instance(GlobalIdentityRotationAuthority::class, $authority);
    $rotation = app(GlobalIdentityRotationCoordinator::class);

    expect($rotation->execute($id, 'start', 10)['phase'])->toBe('revoke')
        ->and($operations->consume($old->id, $token))->toBeFalse()
        ->and($rotation->execute($id, 'advance', 10)['phase'])->toBe('activate')
        ->and($registry->activeReference($purpose))->toEqual($source)
        ->and($old->refresh()->token_hash)->toBeNull()
        ->and($old->delivery_token_enc)->toBeNull()
        ->and($rotation->execute($id, 'advance', 10)['phase'])->toBe('replace')
        ->and($registry->activeReference($purpose))->toEqual($target)
        ->and($rotation->execute($id, 'advance', 10)['phase'])->toBe('verify')
        ->and($rotation->execute($id, 'complete', 10)['phase'])->toBe('complete');

    $new = GlobalIdentityOperation::query()->where('incident_id', $id)->sole();
    expect($new->replaces_operation_id)->toBe($old->id)
        ->and($new->token_hash)->not->toBe($verifier)
        ->and($new->delivery_key_version)->toBe($target->version)
        ->and($operations->openToken($new))->not->toBe($token)
        ->and($operations->consume($new->id, $token))->toBeFalse();
    Queue::assertPushed(DeliverGlobalIdentityOperation::class, fn (DeliverGlobalIdentityOperation $job): bool => $job->operationId === $new->id);
});

it('keeps the approved email-change reservation fenced until its incident replacement takes ownership', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $address = 'Reissue+Reserved@secpal.dev';
    $type = GlobalIdentityOperationType::PendingEmailChange;
    $lifecycle = app(IdentityLifecycle::class);
    $lifecycle->request($user, $type, $address);
    $old = GlobalIdentityOperation::query()->where('user_id', $user->id)->sole();
    $purpose = GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret;
    $source = app(GlobalIdentityWorkingKeyRegistry::class)->activeReference($purpose);
    $target = new GlobalIdentityKeyReference($source->id, $source->version + 1);
    $id = (string) Str::uuid();
    $plan = new GlobalIdentityRotationPlan($id, GlobalIdentityRotationKind::DeliveryCompromise, $source, $target, $purpose, [$old->id]);
    $authority = Mockery::mock(GlobalIdentityRotationAuthority::class);
    $authority->shouldReceive('approvedPlan')->with($id, Mockery::type('string'))->andReturn($plan);
    app()->instance(GlobalIdentityRotationAuthority::class, $authority);
    $rotation = app(GlobalIdentityRotationCoordinator::class);
    $rotation->execute($id, 'start', 10);
    $rotation->execute($id, 'advance', 10);

    expect($old->refresh()->token_hash)->toBeNull()
        ->and(fn () => User::factory()->create(['email' => $address]))->toThrow(GlobalIdentityKeyException::class);

    $rotation->execute($id, 'advance', 10);
    $rotation->execute($id, 'advance', 10);
    $new = GlobalIdentityOperation::query()->where('incident_id', $id)->sole();
    expect(DB::table(GlobalEmailSchema::RESERVATIONS)->pluck('operation_id')->all())->toBe([$new->id])
        ->and(app(GlobalIdentityOperationService::class)->openRecipient($new)?->original())->toBe($address);
});

it('preserves password reset revocation in either commit order with incident replacement', function (bool $replaceFirst): void {
    Queue::fake();
    $user = User::factory()->create();
    $lifecycle = app(IdentityLifecycle::class);
    $operations = app(GlobalIdentityOperationService::class);
    $pending = GlobalIdentityOperationType::PendingEmailChange;
    $reset = GlobalIdentityOperationType::PasswordReset;
    $address = 'Reset+Incident@secpal.dev';
    $lifecycle->request($user, $pending, $address);
    $old = GlobalIdentityOperation::query()->sole();
    [$rotation, $incident] = prepareIdentityIncident([$old->id]);
    $lifecycle->request($user, $reset);
    $resetOperation = GlobalIdentityOperation::query()->where('operation_type', $reset)->sole();
    $proof = $operations->openToken($resetOperation);
    if ($replaceFirst) {
        $rotation->execute($incident, 'advance', 10);
    }
    expect($lifecycle->consume($resetOperation->id, $reset, $proof, 'Synthetic-reset-password-641!'))->toBeTrue();
    expect(DB::table('global_identity_rotation_reissues')->where('operation_id', $old->id)->exists())->toBeFalse()
        ->and(DB::table(GlobalEmailSchema::RESERVATIONS)->count())->toBe(0);

    $rotation->execute($incident, 'advance', 10);
    $rotation->execute($incident, 'complete', 10);
    $replacements = GlobalIdentityOperation::query()->where('replaces_operation_id', $old->id)->get();
    expect($replacements)->toHaveCount($replaceFirst ? 1 : 0)
        ->and($old->refresh()->terminal_reason)->toBe('security_revoked')
        ->and($user->refresh()->email_version)->toBe($old->email_version);
    if ($replaceFirst) {
        $replacement = $replacements->sole();
        expect($replacement->terminal_reason)->toBe('revoked')
            ->and($replacement->token_hash)->toBeNull()
            ->and($operations->claim($replacement->id, 'fixture'))->toBeNull();
    }
    expect(User::factory()->create(['email' => $address])->id)->not->toBe($user->id);
})->with([false, true]);

it('cancels a retained incident reservation when the authoritative lifecycle invalidates its source', function (string $transition): void {
    Queue::fake();
    $user = User::factory()->create();
    $lifecycle = app(IdentityLifecycle::class);
    $pending = GlobalIdentityOperationType::PendingEmailChange;
    $address = 'Cancel+Incident@secpal.dev';
    $lifecycle->request($user, $pending, $address);
    $old = GlobalIdentityOperation::query()->sole();
    [$rotation, $incident] = prepareIdentityIncident([$old->id]);
    match ($transition) {
        'supersede' => $lifecycle->request($user, $pending, $address),
        'delete' => $lifecycle->revokeForDeletedAccount($user->id),
        'replace_address' => $lifecycle->replaceProvenAddress($user, $address),
        'expire' => $this->travel(2)->hours(),
        'restore_tombstone' => replayIncidentTombstone($old),
    };
    if ($transition === 'expire') {
        $this->artisan('identity:expire-operations')->assertSuccessful();
    }
    expect(DB::table('global_identity_rotation_reissues')->count())->toBe(0)
        ->and(DB::table(GlobalEmailSchema::RESERVATIONS)->where('operation_id', $old->id)->exists())->toBeFalse();
    $rotation->execute($incident, 'advance', 10);
    expect(GlobalIdentityOperation::query()->where('replaces_operation_id', $old->id)->exists())->toBeFalse()
        ->and($old->refresh()->terminal_reason)->toBe('security_revoked');
})->with(['supersede', 'delete', 'replace_address', 'expire', 'restore_tombstone']);

function replayIncidentTombstone(GlobalIdentityOperation $source): void
{
    $operations = app(GlobalIdentityOperationService::class);
    $operations->quarantineRestoredOperations();
    $operations->applyRestoreTombstones([new GlobalIdentityTerminalJournalEntry(
        1, $source->id, GlobalIdentityDeliveryState::Terminal,
        new GlobalIdentityKeyReference($source->delivery_key_id, $source->delivery_key_version), now()->timestamp,
    )]);
}

it('rolls back replacement and dispatch on checkpoint interruption and safely resumes once', function (): void {
    config(['queue.default' => 'database']);
    $user = User::factory()->create();
    app(IdentityLifecycle::class)->request($user, GlobalIdentityOperationType::PendingEmailChange, 'Interrupted+Incident@secpal.dev');
    $old = GlobalIdentityOperation::query()->sole();
    [$rotation, $incident] = prepareIdentityIncident([$old->id]);
    DB::table('jobs')->delete();
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION pg_temp.stop_reissue_delete() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            RAISE EXCEPTION 'Synthetic checkpoint interruption' USING ERRCODE = '23514';
        END
        $$;
        CREATE TRIGGER interrupt_reissue BEFORE DELETE ON global_identity_rotation_reissues
        FOR EACH ROW EXECUTE FUNCTION pg_temp.stop_reissue_delete();
        SQL);
    try {
        expect(fn () => $rotation->execute($incident, 'advance', 10))->toThrow(GlobalIdentityKeyException::class)
            ->and($rotation->execute($incident, 'status', 10)['phase'])->toBe('replace')
            ->and(GlobalIdentityOperation::query()->where('replaces_operation_id', $old->id)->count())->toBe(0)
            ->and(DB::table('global_identity_rotation_reissues')->where('operation_id', $old->id)->whereNotNull('recipient_enc')->exists())->toBeTrue()
            ->and(DB::table(GlobalEmailSchema::RESERVATIONS)->pluck('operation_id')->all())->toBe([$old->id])
            ->and(DB::table('jobs')->count())->toBe(0);
    } finally {
        DB::statement('DROP TRIGGER interrupt_reissue ON global_identity_rotation_reissues');
    }

    $rotation->execute($incident, 'advance', 10);
    $rotation->execute($incident, 'advance', 10);
    $replacement = GlobalIdentityOperation::query()->where('replaces_operation_id', $old->id)->sole();
    expect(DB::table('global_identity_rotation_reissues')->count())->toBe(0)
        ->and(DB::table(GlobalEmailSchema::RESERVATIONS)->pluck('operation_id')->all())->toBe([$replacement->id])
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('delegates current invitation replacements independently to the tenant domain', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $other = User::factory()->create();
    $statuses = [];
    $subject = Mockery::mock(GlobalIdentityInvitationReissue::class);
    $subject->shouldReceive('isCurrent')->andReturnUsing(function (GlobalIdentityOperationContext $context) use (&$statuses): bool {
        return ($statuses[$context->id] ?? null) === 'current';
    });
    $subject->shouldReceive('authorizeReplacement')->twice()->andReturnUsing(function (GlobalIdentityOperationContext $prior, GlobalIdentityOperationContext $next) use (&$statuses): ?string {
        expect($statuses[$prior->id])->toBe('current')
            ->and($next->tenantId)->toBe($prior->tenantId)
            ->and($next->userId)->toBe($prior->userId)
            ->and($next->invitationId)->not->toBe($prior->invitationId);

        return null;
    });
    app()->instance(GlobalIdentityOperationSubject::class, $subject);
    $operations = app(GlobalIdentityOperationService::class);
    foreach ([[$user->tenant_id, 'current'], [$other->tenant_id, 'current'], [$user->tenant_id, 'revoked'],
        [$other->tenant_id, 'superseded'], [$other->tenant_id, 'expired'], [$other->tenant_id, 'proof_expired']] as [$tenantId, $status]) {
        $source = (string) Str::uuid();
        $statuses[$source] = $status;
        $context = new GlobalIdentityOperationContext(GlobalIdentityOperationType::Invitation, $source, $user->id, null, $source, $tenantId);
        $operations->create($context, now()->toImmutable()->addMinutes($status === 'proof_expired' ? 1 : 10));
    }
    [$rotation, $incident] = prepareIdentityIncident(array_keys($statuses));
    $this->travel(2)->minutes();
    $rotation->execute($incident, 'advance', 10);
    $rotation->execute($incident, 'advance', 10);
    $replacements = GlobalIdentityOperation::query()->where('incident_id', $incident)->get();
    expect($replacements->pluck('tenant_id')->all())->toEqualCanonicalizing([$user->tenant_id, $other->tenant_id])
        ->and($replacements->pluck('replaces_operation_id')->all())->toEqualCanonicalizing(array_keys($statuses, 'current'))
        ->and(DB::table('global_identity_rotation_reissues')->count())->toBe(0);
    Queue::assertPushed(DeliverGlobalIdentityOperation::class, 2);
});

it('preserves the invitation checkpoint when the owning domain is unavailable', function (): void {
    Queue::fake();
    $tenant = User::factory()->create()->tenant_id;
    $id = (string) Str::uuid();
    $context = new GlobalIdentityOperationContext(GlobalIdentityOperationType::Invitation, $id, null, null, $id, $tenant);
    app(GlobalIdentityOperationService::class)->create($context, now()->toImmutable()->addMinutes(10), 'Pending+Domain@secpal.dev');
    [$rotation, $incident] = prepareIdentityIncident([$id]);
    expect(fn () => $rotation->execute($incident, 'advance', 10))->toThrow(GlobalIdentityKeyException::class)
        ->and($rotation->execute($incident, 'status', 10)['phase'])->toBe('replace')
        ->and(DB::table('global_identity_rotation_reissues')->where('operation_id', $id)->exists())->toBeTrue()
        ->and(GlobalIdentityOperation::query()->where('incident_id', $incident)->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

it('admits the exact active replacement for a historical incident and rejects an invalid active-key plan before fencing', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $operations = app(GlobalIdentityOperationService::class);
    $keys = app(GlobalIdentityWorkingKeyRegistry::class);
    $purpose = GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret;
    $historical = $keys->activeReference($purpose);
    $source = (string) Str::uuid();
    $context = new GlobalIdentityOperationContext(GlobalIdentityOperationType::PasswordReset, $source, $user->id, $user->email_version);
    $operations->create($context, now()->toImmutable()->addMinutes(10));
    $keys->activate($keys->stage($purpose));
    $keys->activate($keys->stage($purpose));
    $active = $keys->activeReference($purpose);
    $badId = (string) Str::uuid();
    $incident = (string) Str::uuid();
    expect(fn () => app(GlobalIdentityRotationCoordinator::class)->execute($badId, 'start', 10))
        ->toThrow(GlobalIdentityKeyException::class, 'authority_unavailable')
        ->and(DB::table('global_identity_rotations')->count())->toBe(0);
    $invalid = new GlobalIdentityRotationPlan($badId, GlobalIdentityRotationKind::DeliveryCompromise, $active,
        new GlobalIdentityKeyReference($active->id, $active->version + 2), $purpose);
    $valid = new GlobalIdentityRotationPlan($incident, GlobalIdentityRotationKind::DeliveryCompromise, $historical, $active, $purpose, [$source]);
    $authority = Mockery::mock(GlobalIdentityRotationAuthority::class);
    $authority->shouldReceive('approvedPlan')->with($badId, 'start')->once()->andReturn($invalid);
    $authority->shouldReceive('approvedPlan')->with($incident, Mockery::type('string'))->andReturn($valid);
    app()->instance(GlobalIdentityRotationAuthority::class, $authority);
    $rotation = app(GlobalIdentityRotationCoordinator::class);
    expect(fn () => $rotation->execute($badId, 'start', 10))->toThrow(GlobalIdentityKeyException::class)
        ->and(DB::table('global_identity_rotations')->count())->toBe(0)
        ->and($keys->deliveryBlockReason($active->id, $active->version))->toBeNull();

    expect($rotation->execute($incident, 'start', 10)['phase'])->toBe('revoke')
        ->and($rotation->execute($incident, 'advance', 10)['phase'])->toBe('activate')
        ->and($rotation->execute($incident, 'advance', 10)['phase'])->toBe('replace')
        ->and($rotation->execute($incident, 'advance', 10)['phase'])->toBe('verify')
        ->and($rotation->execute($incident, 'complete', 10)['phase'])->toBe('complete');
    $replacement = GlobalIdentityOperation::query()->where('replaces_operation_id', $source)->sole();
    expect($replacement->delivery_key_version)->toBe($active->version)
        ->and($keys->activeReference($purpose))->toEqual($active);
});

/** @param list<string> $approved */
function prepareIdentityIncident(array $approved): array
{
    $purpose = GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret;
    $from = app(GlobalIdentityWorkingKeyRegistry::class)->activeReference($purpose);
    $id = (string) Str::uuid();
    $plan = new GlobalIdentityRotationPlan($id, GlobalIdentityRotationKind::DeliveryCompromise, $from,
        new GlobalIdentityKeyReference($from->id, $from->version + 1), $purpose, $approved);
    $authority = Mockery::mock(GlobalIdentityRotationAuthority::class);
    $authority->shouldReceive('approvedPlan')->with($id, Mockery::type('string'))->andReturn($plan);
    app()->instance(GlobalIdentityRotationAuthority::class, $authority);
    $rotation = app(GlobalIdentityRotationCoordinator::class);
    expect($rotation->execute($id, 'start', 10)['phase'])->toBe('revoke')
        ->and($rotation->execute($id, 'advance', 10)['phase'])->toBe('activate')
        ->and($rotation->execute($id, 'advance', 10)['phase'])->toBe('replace');

    return [$rotation, $id];
}

it('fences an MFA write prepared before activation and preserves the enrolled credential', function (): void {
    $user = User::factory()->create();
    $user->createTwoFactorAuth();
    $mfa = $user->twoFactorAuth;
    $secret = $mfa->shared_secret;
    $mfa->shared_secret = $secret;
    $registry = app(GlobalIdentityWorkingKeyRegistry::class);
    $purpose = GlobalIdentityWorkingKeyPurpose::MfaCredentials;
    $target = $registry->stage($purpose);
    $registry->activate($target);

    $mfa->save();
    $fresh = $mfa->refresh();
    $envelope = json_decode($fresh->getRawOriginal('shared_secret'), true, flags: JSON_THROW_ON_ERROR);
    expect(app(GlobalIdentityEnvelopeCodec::class)->reference($envelope, $purpose))->toEqual($target)
        ->and($fresh->shared_secret)->toBe($secret);
});

it('allows a delivered verifier after confirmed key loss while fencing undelivered proofs', function (): void {
    $user = User::factory()->create();
    $registry = app(GlobalIdentityWorkingKeyRegistry::class);
    $purpose = GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret;
    $source = $registry->activeReference($purpose);
    $operations = app(GlobalIdentityOperationService::class);
    $delivered = new GlobalIdentityOperationContext(GlobalIdentityOperationType::EmailVerification, (string) Str::uuid(), $user->id, $user->email_version);
    $pending = new GlobalIdentityOperationContext(GlobalIdentityOperationType::PasswordReset, (string) Str::uuid(), $user->id, $user->email_version);
    $operations->create($delivered, now()->toImmutable()->addMinutes(10));
    $operations->create($pending, now()->toImmutable()->addMinutes(10));
    $claim = $operations->claim($delivered->id, 'test');
    expect($claim)->not->toBeNull();
    $deliveredToken = $operations->openToken($claim);
    $pendingToken = $operations->openToken(GlobalIdentityOperation::query()->findOrFail($pending->id));
    expect($operations->commitDispatch($claim))->toBeTrue();
    $operations->recordResult($claim, new GlobalIdentityDeliveryResult(GlobalIdentityDeliveryOutcome::Accepted));

    DB::table('global_identity_working_keys')->where('key_id', $source->id)->where('key_version', $source->version)
        ->update(['wrapped_key' => '\\x'.str_repeat('00', 48)]);
    $registry->blockDeliveryVersion($source, GlobalIdentityTerminalReason::KeyUnavailable);
    expect($operations->claim($pending->id, 'test'))->toBeNull()
        ->and($operations->consume($pending->id, $pendingToken))->toBeFalse()
        ->and($operations->consume($delivered->id, $deliveredToken))->toBeTrue();

    $operations->invalidate($pending->id, GlobalIdentityTerminalReason::KeyUnavailable);
    $target = $registry->stageBlockedDeliveryReplacement($source);
    $registry->activateDeliveryReplacement($target, fn (): bool => ! GlobalIdentityOperation::query()->whereNotNull('delivery_token_enc')->exists());
    expect($registry->activeReference($purpose))->toEqual($target->reference);
});

it('obtains a delivered invitation replacement from its owning domain', function (): void {
    Queue::fake();
    $tenantId = User::factory()->create()->tenant_id;
    $oldId = (string) Str::uuid();
    $oldContext = new GlobalIdentityOperationContext(GlobalIdentityOperationType::Invitation, $oldId, null, null, $oldId, $tenantId);
    $address = 'Invitation@secpal.dev';
    $subject = Mockery::mock(GlobalIdentityInvitationReissue::class);
    $subject->shouldReceive('isCurrent')->withArgs(fn (GlobalIdentityOperationContext $context): bool => $context->id === $oldId)->andReturnTrue();
    $subject->shouldReceive('authorizeReplacement')->once()->andReturnUsing(function (GlobalIdentityOperationContext $prior, GlobalIdentityOperationContext $next) use ($oldContext, $address): string {
        expect($prior)->toEqual($oldContext)
            ->and($next->id)->not->toBe($prior->id)
            ->and($next->invitationId)->toBe($next->id);

        return $address;
    });
    app()->instance(GlobalIdentityOperationSubject::class, $subject);
    $ops = app(GlobalIdentityOperationService::class);
    $ops->create($oldContext, now()->toImmutable()->addMinutes(10), $address);
    $claim = $ops->claim($oldId, 'fixture');
    expect($claim)->not->toBeNull()->and($ops->commitDispatch($claim))->toBeTrue();
    $ops->recordResult($claim, new GlobalIdentityDeliveryResult(GlobalIdentityDeliveryOutcome::Accepted));
    expect(GlobalIdentityOperation::query()->findOrFail($oldId)->recipient_enc)->toBeNull();

    $registry = app(GlobalIdentityWorkingKeyRegistry::class);
    $purpose = GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret;
    $from = $registry->activeReference($purpose);
    $to = new GlobalIdentityKeyReference($from->id, $from->version + 1);
    $id = (string) Str::uuid();
    $plan = new GlobalIdentityRotationPlan($id, GlobalIdentityRotationKind::DeliveryCompromise, $from, $to, $purpose, [$oldId]);
    $authority = Mockery::mock(GlobalIdentityRotationAuthority::class);
    $authority->shouldReceive('approvedPlan')->with($id, Mockery::type('string'))->andReturn($plan);
    app()->instance(GlobalIdentityRotationAuthority::class, $authority);
    $rotation = app(GlobalIdentityRotationCoordinator::class);

    expect($rotation->execute($id, 'start', 10)['phase'])->toBe('revoke')
        ->and($rotation->execute($id, 'advance', 10)['phase'])->toBe('activate')
        ->and($rotation->execute($id, 'advance', 10)['phase'])->toBe('replace')
        ->and($rotation->execute($id, 'advance', 10)['phase'])->toBe('verify');
    $new = GlobalIdentityOperation::query()->where('replaces_operation_id', $oldId)->sole();
    expect($ops->openRecipient($new)?->original())->toBe($address)
        ->and($new->incident_id)->toBe($id);
});
