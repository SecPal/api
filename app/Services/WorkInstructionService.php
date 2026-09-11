<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Enums\WorkInstructionStatus;
use App\Exceptions\WorkInstructionConflictException;
use App\Exceptions\WorkInstructionTargetNotFoundException;
use App\Models\Activity;
use App\Models\User;
use App\Models\WorkInstruction;
use App\Repositories\WorkInstructionRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

final readonly class WorkInstructionService
{
    public function __construct(
        private WorkInstructionRepository $workInstructions,
        private PermissionRegistrar $permissions,
        private WorkInstructionAuditRecorder $audits,
    ) {}

    /** @return LengthAwarePaginator<int, WorkInstruction> */
    public function list(User $actor, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        return $this->workInstructions->paginate(
            $this->authorizeActor($actor, 'viewAny'),
            $page,
            $perPage,
        );
    }

    public function inspect(User $actor, string $workInstructionId): WorkInstruction
    {
        $tenantId = $this->authorizeActor($actor, 'viewAny');
        $workInstruction = $this->inspectForTenant($tenantId, $workInstructionId);
        Gate::forUser($actor)->authorize('view', $workInstruction);

        return $workInstruction;
    }

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): WorkInstruction
    {
        $this->requireAttributes($attributes, false);
        $tenantId = $this->authorizeActor($actor, 'create');

        try {
            return DB::transaction(function () use ($actor, $tenantId, $attributes): WorkInstruction {
                Activity::acquireHashChainLock($tenantId);
                $workInstruction = $this->workInstructions->create([
                    'tenant_id' => $tenantId,
                    'instruction_number' => $attributes['instruction_number'],
                    'title' => $attributes['title'],
                    'body' => $attributes['body'],
                    'locale' => $attributes['locale'],
                    'status' => WorkInstructionStatus::Draft,
                    'published_at' => null,
                    'published_by_user_id' => null,
                    'archived_at' => null,
                    'archived_by_user_id' => null,
                ]);
                $this->audits->recordCreate($actor, $workInstruction);

                return $workInstruction;
            });
        } catch (QueryException $exception) {
            if (! $this->workInstructions->isDuplicateInstructionNumber($exception)) {
                throw $exception;
            }

            throw new WorkInstructionConflictException(
                'The instruction number is already in use.',
                previous: $exception,
            );
        }
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, string $workInstructionId, array $attributes): WorkInstruction
    {
        $this->requireAttributes($attributes, true);
        $tenantId = $this->authorizeActor($actor, 'update');
        $initial = $this->inspectAuthorized($actor, $tenantId, $workInstructionId, 'update');

        return $this->lockedMutation(
            $actor,
            $tenantId,
            $workInstructionId,
            $initial->status,
            'update',
            function (WorkInstruction $workInstruction) use ($actor, $attributes): WorkInstruction {
                $this->requireEditable($workInstruction);
                $previous = $this->audits->snapshot($workInstruction);
                $workInstruction = $this->workInstructions->update($workInstruction, $attributes);
                $this->audits->recordUpdate($actor, $workInstruction, $previous);

                return $workInstruction;
            },
        );
    }

    public function submitForReview(User $actor, string $workInstructionId): WorkInstruction
    {
        return $this->transition(
            $actor,
            $workInstructionId,
            'update',
            WorkInstructionStatus::Draft,
            WorkInstructionStatus::InReview,
            'The Work Instruction is not a draft.',
        );
    }

    public function publish(User $actor, string $workInstructionId): WorkInstruction
    {
        return $this->transition(
            $actor,
            $workInstructionId,
            'publish',
            WorkInstructionStatus::InReview,
            WorkInstructionStatus::Published,
            'The Work Instruction is not in review.',
        );
    }

    public function archive(User $actor, string $workInstructionId): WorkInstruction
    {
        return $this->transition(
            $actor,
            $workInstructionId,
            'archive',
            WorkInstructionStatus::Published,
            WorkInstructionStatus::Archived,
            'The Work Instruction is not published.',
        );
    }

    private function transition(
        User $actor,
        string $workInstructionId,
        string $ability,
        WorkInstructionStatus $expected,
        WorkInstructionStatus $target,
        string $stateMessage,
    ): WorkInstruction {
        $tenantId = $this->authorizeActor($actor, $ability);
        $initial = $this->inspectAuthorized($actor, $tenantId, $workInstructionId, $ability);

        return $this->lockedMutation(
            $actor,
            $tenantId,
            $workInstructionId,
            $initial->status,
            $ability,
            function (WorkInstruction $workInstruction) use (
                $actor,
                $expected,
                $target,
                $stateMessage,
            ): WorkInstruction {
                if ($workInstruction->status !== $expected) {
                    throw new WorkInstructionConflictException($stateMessage);
                }

                $previous = $this->audits->snapshot($workInstruction);
                $evidence = match ($target) {
                    WorkInstructionStatus::Published => [
                        'published_at' => now(),
                        'published_by_user_id' => $actor->id,
                    ],
                    WorkInstructionStatus::Archived => [
                        'archived_at' => now(),
                        'archived_by_user_id' => $actor->id,
                    ],
                    default => [],
                };
                $workInstruction = $this->workInstructions->update($workInstruction, [
                    'status' => $target,
                    ...$evidence,
                ]);
                match ($target) {
                    WorkInstructionStatus::InReview => $this->audits->recordSubmitForReview($actor, $workInstruction, $previous),
                    WorkInstructionStatus::Published => $this->audits->recordPublish($actor, $workInstruction, $previous),
                    WorkInstructionStatus::Archived => $this->audits->recordArchive($actor, $workInstruction, $previous),
                    default => throw new \LogicException('Unsupported Work Instruction lifecycle target.'),
                };

                return $workInstruction;
            },
        );
    }

    /** @param callable(WorkInstruction): WorkInstruction $mutation */
    private function lockedMutation(
        User $actor,
        int $tenantId,
        string $workInstructionId,
        WorkInstructionStatus $initialStatus,
        string $ability,
        callable $mutation,
    ): WorkInstruction {
        try {
            return DB::transaction(function () use (
                $actor,
                $tenantId,
                $workInstructionId,
                $initialStatus,
                $ability,
                $mutation,
            ): WorkInstruction {
                Activity::acquireHashChainLock($tenantId);
                $workInstruction = $this->workInstructions->lock($tenantId, $workInstructionId);
                Gate::forUser($actor)->authorize($ability, $workInstruction);

                if ($workInstruction->status !== $initialStatus) {
                    throw new WorkInstructionConflictException(
                        'The Work Instruction changed in a concurrent state transition.',
                    );
                }

                return $mutation($workInstruction);
            });
        } catch (ModelNotFoundException $exception) {
            throw new WorkInstructionTargetNotFoundException(previous: $exception);
        } catch (QueryException $exception) {
            if (! $this->workInstructions->isRecognizedStateConflict($exception)) {
                throw $exception;
            }

            throw new WorkInstructionConflictException(
                'The Work Instruction changed in a concurrent state transition.',
                previous: $exception,
            );
        }
    }

    private function inspectAuthorized(
        User $actor,
        int $tenantId,
        string $workInstructionId,
        string $ability,
    ): WorkInstruction {
        $workInstruction = $this->inspectForTenant($tenantId, $workInstructionId);
        Gate::forUser($actor)->authorize($ability, $workInstruction);

        return $workInstruction;
    }

    private function inspectForTenant(int $tenantId, string $workInstructionId): WorkInstruction
    {
        try {
            return $this->workInstructions->inspect($tenantId, $workInstructionId);
        } catch (ModelNotFoundException $exception) {
            throw new WorkInstructionTargetNotFoundException(previous: $exception);
        }
    }

    private function requireEditable(WorkInstruction $workInstruction): void
    {
        if (! in_array($workInstruction->status, [
            WorkInstructionStatus::Draft,
            WorkInstructionStatus::InReview,
        ], true)) {
            throw new WorkInstructionConflictException('The Work Instruction is not editable.');
        }
    }

    private function authorizeActor(User $actor, string $ability): int
    {
        Gate::forUser($actor)->authorize($ability, WorkInstruction::class);
        $tenantId = $this->permissions->getPermissionsTeamId();

        if (! is_int($tenantId) || $actor->tenant_id === null || $actor->tenant_id !== $tenantId) {
            throw new AuthorizationException;
        }

        return $tenantId;
    }

    /** @param array<string, mixed> $attributes */
    private function requireAttributes(array $attributes, bool $partial): void
    {
        $keys = array_keys($attributes);
        $allowed = $partial ? WorkInstruction::MUTABLE_BUSINESS_FIELDS : WorkInstruction::CREATE_FIELDS;

        if (array_diff($keys, $allowed) !== []) {
            throw new \InvalidArgumentException('Work Instruction attributes contain a server-owned or unknown field.');
        }
        if (($partial && $keys === []) || (! $partial && array_diff($allowed, $keys) !== [])) {
            throw new \InvalidArgumentException('Work Instruction attributes are incomplete.');
        }
    }
}
