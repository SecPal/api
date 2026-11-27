<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityKeyException;
use App\Jobs\DeliverGlobalIdentityOperation;
use App\Models\GlobalIdentityOperation;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

final readonly class IdentityLifecycle
{
    public function __construct(
        private GlobalIdentityOperationService $operations,
        private GlobalEmailRepository $email,
        private GlobalIdentityAccounts $accounts,
        private IdentityMailDelivery $mail,
        private ActivityLogService $activityLog,
    ) {}

    public function request(User $user, GlobalIdentityOperationType $type, #[SensitiveParameter] ?string $candidate = null): void
    {
        $this->operations->forAccount($user->id, function () use ($user, $type, $candidate): void {
            GlobalIdentityOperation::query()->where('user_id', $user->id)->where('operation_type', $type->value)
                ->orderBy('id')->lockForUpdate()->get(['id']);
            $current = User::query()->lockForUpdate()->find($user->id);
            if ($current === null || $current->email_version !== $user->email_version
                || ! hash_equals($current->password, $user->password)
                || ($type === GlobalIdentityOperationType::EmailVerification && $current->hasVerifiedEmail())) {
                return;
            }
            $this->invalidate($user->id, [$type], GlobalIdentityTerminalReason::Superseded);

            $id = (string) Str::uuid();
            if ($candidate !== null) {
                $this->accounts->withLookupVersions(
                    fn (GlobalEmailLookupVersions $versions) => $this->email->reserve($id, $candidate, $versions),
                );
            }
            $context = new GlobalIdentityOperationContext($type, $id, $user->id, $user->email_version);
            $this->operations->create($context, now()->toImmutable()->addHour(), $candidate);
            DeliverGlobalIdentityOperation::dispatch($id);
        });
    }

    public function consume(
        string $id,
        GlobalIdentityOperationType $type,
        #[SensitiveParameter] string $proof,
        #[SensitiveParameter] ?string $password = null,
    ): bool {
        if (! GlobalIdentityOperationContext::isCanonicalId($id)) {
            return false;
        }
        $userId = GlobalIdentityOperation::query()->whereKey($id)->where('operation_type', $type->value)->value('user_id');
        if (! is_string($userId)) {
            return false;
        }

        return $this->operations->forAccount($userId, function () use ($id, $proof, $type, $userId, $password): bool {
            // Lock operations before users, in the same order as the dispatch fence.
            GlobalIdentityOperation::query()->where('user_id', $userId)->orderBy('id')->lockForUpdate()->get(['id']);

            return $this->operations->consume($id, $proof, function (GlobalIdentityOperation $operation) use ($type, $userId, $password): void {
                $user = User::query()->findOrFail($userId);
                if ($type === GlobalIdentityOperationType::EmailVerification) {
                    if ($user->markEmailAsVerified()) {
                        DB::afterCommit(static fn () => event(new Verified($user)));
                    }

                    return;
                }
                if ($type === GlobalIdentityOperationType::PendingEmailChange) {
                    $this->activateEmail($operation, $user);
                } else {
                    if ($password === null) {
                        throw new GlobalIdentityKeyException('Password reset material unavailable.');
                    }
                    $user->forceFill(['password' => Hash::make($password)])->save();
                }
                $user->forceFill(['remember_token' => null])->save();
                $user->tokens()->delete();
                DB::table('sessions')->where('user_id', $userId)->delete();
                $this->invalidate($userId, $type === GlobalIdentityOperationType::PasswordReset
                    ? [$type, GlobalIdentityOperationType::PendingEmailChange]
                    : [GlobalIdentityOperationType::PasswordReset, GlobalIdentityOperationType::EmailVerification, $type],
                    GlobalIdentityTerminalReason::Revoked,
                    $operation->id);
                if ($type === GlobalIdentityOperationType::PasswordReset) {
                    $this->activityLog->logPasswordReset($user);
                }
            });
        });
    }

    public function revokeForDeletedAccount(string $userId): void
    {
        $this->operations->forAccount($userId, fn () => $this->invalidate(
            $userId,
            [GlobalIdentityOperationType::PasswordReset, GlobalIdentityOperationType::EmailVerification, GlobalIdentityOperationType::PendingEmailChange],
            GlobalIdentityTerminalReason::Revoked,
        ));
    }

    public function replaceProvenAddress(User $user, #[SensitiveParameter] string $address): void
    {
        $this->operations->forAccount($user->id, function () use ($user, $address): void {
            $expected = $user->email_version;
            if ($this->accounts->find($address)?->id === $user->id) {
                return;
            }

            $this->revokeForDeletedAccount($user->id);
            $operationId = (string) Str::uuid();
            $this->accounts->withLookupVersions(function (GlobalEmailLookupVersions $versions) use ($user, $expected, $operationId, $address): void {
                $this->email->reserve($operationId, $address, $versions);
                $this->email->activate($user->id, $expected, $operationId, $address, $versions);
            });
            $user->refresh();
            $user->forceFill(['remember_token' => null])->save();
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
    }

    /** @param list<GlobalIdentityOperationType> $types */
    private function invalidate(string $userId, array $types, GlobalIdentityTerminalReason $reason, ?string $except = null): void
    {
        $operations = GlobalIdentityOperation::query()->where('user_id', $userId)
            ->whereIn('operation_type', array_column($types, 'value'))
            ->outstanding()
            ->when($except !== null, fn ($query) => $query->where('id', '!=', $except))
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($operations as $operation) {
            $this->operations->invalidate($operation->id, $reason);
        }
    }

    private function activateEmail(GlobalIdentityOperation $operation, User $user): void
    {
        $candidate = $this->operations->openRecipient($operation)
            ?? throw new GlobalIdentityKeyException('Pending email change is unavailable.');
        $this->email->withEmail($user->id, $user->email_version, true, function (#[SensitiveParameter] string $previous) use ($operation, $user, $candidate): void {
            $this->accounts->withLookupVersions(
                fn (GlobalEmailLookupVersions $versions): int => $this->email->activate($user->id, $user->email_version, $operation->id, $candidate->original(), $versions),
            );
            DB::afterCommit(function () use ($previous, $user): void {
                try {
                    $this->mail->send($previous, __('The email address for your SecPal account was changed.'), null);
                } catch (Throwable) {
                    Log::warning('Identity change notification failed.', ['user_id' => $user->id]);
                }
            });
        });
    }
}
