<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Models\Activity;
use App\Models\InternalCostCenter;
use App\Models\User;
use App\Support\ApiTimestamp;
use BackedEnum;
use DateTimeInterface;
use RuntimeException;

class InternalCostCenterAuditRecorder
{
    private const int SCHEMA_VERSION = 1;

    /** @var list<string> */
    private const AUDIT_FIELDS = ['code', 'name', 'status', 'inactive_at'];

    /** @return array<string, scalar|null> */
    public function snapshot(InternalCostCenter $internalCostCenter): array
    {
        $snapshot = [];
        foreach (self::AUDIT_FIELDS as $field) {
            $snapshot[$field] = $this->scalar($internalCostCenter->getAttribute($field));
        }

        return $snapshot;
    }

    public function recordCreate(User $actor, InternalCostCenter $internalCostCenter): Activity
    {
        return $this->record($actor, $internalCostCenter, 'create', self::AUDIT_FIELDS, [], $this->snapshot($internalCostCenter));
    }

    /** @param array<string, scalar|null> $previous */
    public function recordUpdate(User $actor, InternalCostCenter $internalCostCenter, array $previous): Activity
    {
        return $this->record($actor, $internalCostCenter, 'update', ['name'], $previous, $this->snapshot($internalCostCenter));
    }

    /** @param array<string, scalar|null> $previous */
    public function recordDeactivate(User $actor, InternalCostCenter $internalCostCenter, array $previous): Activity
    {
        return $this->record(
            $actor,
            $internalCostCenter,
            'deactivate',
            ['status', 'inactive_at'],
            $previous,
            $this->snapshot($internalCostCenter),
        );
    }

    /**
     * @param  list<string>  $changedFields
     * @param  array<string, scalar|null>  $previous
     * @param  array<string, scalar|null>  $current
     */
    private function record(
        User $actor,
        InternalCostCenter $internalCostCenter,
        string $operation,
        array $changedFields,
        array $previous,
        array $current,
    ): Activity {
        $changes = [];
        foreach ($changedFields as $field) {
            $changes[$field] = [
                'previous' => $previous[$field] ?? null,
                'current' => $current[$field] ?? null,
            ];
        }

        $audit = activity('internal_cost_center_change')
            ->causedBy($actor)
            ->performedOn($internalCostCenter)
            ->useLog('internal_cost_center_change')
            ->event('internal_cost_center.'.$operation)
            ->withProperties([
                'schema_version' => self::SCHEMA_VERSION,
                'operation' => $operation,
                'internal_cost_center_id' => $internalCostCenter->id,
                'changed_fields' => $changedFields,
                'changes' => $changes,
            ])
            ->tap(function ($activity) use ($internalCostCenter): void {
                /** @var Activity $activity */
                $activity->tenant_id = $internalCostCenter->tenant_id;
                $activity->suppressRequestOrganizationalUnitCapture();
            })
            ->log('Internal Cost Center financial classification mutation');

        if (! $audit instanceof Activity) {
            throw new RuntimeException('Required Internal Cost Center audit activity was not persisted.');
        }

        return $audit;
    }

    private function scalar(mixed $value): string|int|float|bool|null
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof DateTimeInterface) {
            return ApiTimestamp::format($value);
        }
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        throw new RuntimeException('Internal Cost Center audit values must remain bounded scalars.');
    }
}
