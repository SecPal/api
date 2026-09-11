<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Models\Activity;
use App\Models\User;
use App\Models\WorkInstruction;
use BackedEnum;
use DateTimeInterface;
use RuntimeException;

class WorkInstructionAuditRecorder
{
    private const int SCHEMA_VERSION = 1;

    /** @var list<string> */
    private const SNAPSHOT_FIELDS = [
        'instruction_number',
        'title',
        'body',
        'locale',
        'status',
        'published_at',
        'published_by_user_id',
        'archived_at',
        'archived_by_user_id',
    ];

    /** @return array<string, scalar|null> */
    public function snapshot(WorkInstruction $workInstruction): array
    {
        $snapshot = [];
        foreach (self::SNAPSHOT_FIELDS as $field) {
            $snapshot[$field] = $this->scalar($workInstruction->getAttribute($field));
        }

        return $snapshot;
    }

    public function recordCreate(User $actor, WorkInstruction $workInstruction): Activity
    {
        return $this->record(
            $actor,
            $workInstruction,
            'create',
            [...WorkInstruction::CREATE_FIELDS, 'status'],
            null,
            $workInstruction->status->value,
        );
    }

    /** @param array<string, scalar|null> $previous */
    public function recordUpdate(User $actor, WorkInstruction $workInstruction, array $previous): Activity
    {
        $current = $this->snapshot($workInstruction);
        $changedFields = array_values(array_filter(
            WorkInstruction::MUTABLE_BUSINESS_FIELDS,
            static fn (string $field): bool => ($previous[$field] ?? null) !== ($current[$field] ?? null),
        ));

        return $this->record(
            $actor,
            $workInstruction,
            'update',
            $changedFields,
            $workInstruction->status->value,
            $workInstruction->status->value,
        );
    }

    /** @param array<string, scalar|null> $previous */
    public function recordSubmitForReview(User $actor, WorkInstruction $workInstruction, array $previous): Activity
    {
        return $this->recordTransition($actor, $workInstruction, 'submit_for_review', ['status'], $previous);
    }

    /** @param array<string, scalar|null> $previous */
    public function recordPublish(User $actor, WorkInstruction $workInstruction, array $previous): Activity
    {
        return $this->recordTransition(
            $actor,
            $workInstruction,
            'publish',
            ['status', 'published_at', 'published_by_user_id'],
            $previous,
        );
    }

    /** @param array<string, scalar|null> $previous */
    public function recordArchive(User $actor, WorkInstruction $workInstruction, array $previous): Activity
    {
        return $this->recordTransition(
            $actor,
            $workInstruction,
            'archive',
            ['status', 'archived_at', 'archived_by_user_id'],
            $previous,
        );
    }

    /** @param list<string> $changedFields
     * @param  array<string, scalar|null>  $previous
     */
    private function recordTransition(
        User $actor,
        WorkInstruction $workInstruction,
        string $operation,
        array $changedFields,
        array $previous,
    ): Activity {
        $fromStatus = $previous['status'] ?? null;

        return $this->record(
            $actor,
            $workInstruction,
            $operation,
            $changedFields,
            is_string($fromStatus) ? $fromStatus : null,
            $workInstruction->status->value,
        );
    }

    /** @param list<string> $changedFields */
    private function record(
        User $actor,
        WorkInstruction $workInstruction,
        string $operation,
        array $changedFields,
        ?string $fromStatus,
        string $toStatus,
    ): Activity {
        $audit = activity('work_instruction_change')
            ->causedBy($actor)
            ->performedOn($workInstruction)
            ->useLog('work_instruction_change')
            ->event('work_instruction.'.$operation)
            ->withProperties([
                'schema_version' => self::SCHEMA_VERSION,
                'operation' => $operation,
                'work_instruction_id' => $workInstruction->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'changed_fields' => $changedFields,
            ])
            ->tap(function ($activity) use ($workInstruction): void {
                /** @var Activity $activity */
                $activity->tenant_id = $workInstruction->tenant_id;
                $activity->suppressRequestOrganizationalUnitCapture();
            })
            ->log('Work Instruction lifecycle mutation');

        if (! $audit instanceof Activity) {
            throw new RuntimeException('Required Work Instruction audit activity was not persisted.');
        }

        return $audit;
    }

    private function scalar(mixed $value): string|int|float|bool|null
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        throw new RuntimeException('Work Instruction audit values must remain bounded scalars.');
    }
}
