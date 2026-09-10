<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Models\Activity;
use App\Models\Contract;
use App\Models\User;
use App\Support\ApiTimestamp;
use BackedEnum;
use DateTimeInterface;
use RuntimeException;

class ContractAuditRecorder
{
    private const int SCHEMA_VERSION = 1;

    /** @return array<string, scalar|null> */
    public function snapshot(Contract $contract): array
    {
        $snapshot = [];

        foreach (array_merge(Contract::MUTABLE_BUSINESS_FIELDS, ['status', 'retired_at']) as $field) {
            $snapshot[$field] = $this->scalar($field, $contract->getAttribute($field));
        }

        return $snapshot;
    }

    public function recordCreate(User $actor, Contract $contract): Activity
    {
        $current = $this->snapshot($contract);

        return $this->record(
            $actor,
            $contract,
            'create',
            Contract::MUTABLE_BUSINESS_FIELDS,
            [],
            $current,
        );
    }

    /** @param array<string, scalar|null> $previous */
    public function recordUpdate(User $actor, Contract $contract, array $previous): Activity
    {
        $current = $this->snapshot($contract);
        $changedFields = array_values(array_filter(
            Contract::MUTABLE_BUSINESS_FIELDS,
            static fn (string $field): bool => ($previous[$field] ?? null) !== ($current[$field] ?? null),
        ));

        return $this->record($actor, $contract, 'update', $changedFields, $previous, $current);
    }

    /** @param array<string, scalar|null> $previous */
    public function recordRetire(User $actor, Contract $contract, array $previous): Activity
    {
        return $this->record(
            $actor,
            $contract,
            'retire',
            ['status', 'retired_at'],
            $previous,
            $this->snapshot($contract),
        );
    }

    /**
     * @param  list<string>  $changedFields
     * @param  array<string, scalar|null>  $previous
     * @param  array<string, scalar|null>  $current
     */
    private function record(
        User $actor,
        Contract $contract,
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

        $audit = activity('contract_change')
            ->causedBy($actor)
            ->performedOn($contract)
            ->useLog('contract_change')
            ->event('contract.'.$operation)
            ->withProperties([
                'schema_version' => self::SCHEMA_VERSION,
                'operation' => $operation,
                'contract_id' => $contract->id,
                'changed_fields' => $changedFields,
                'changes' => $changes,
            ])
            ->tap(function ($activity) use ($contract): void {
                /** @var Activity $activity */
                $activity->tenant_id = $contract->tenant_id;
                $activity->suppressRequestOrganizationalUnitCapture();
            })
            ->log('Contract financial mutation');

        if (! $audit instanceof Activity) {
            throw new RuntimeException('Required Contract audit activity was not persisted.');
        }

        return $audit;
    }

    private function scalar(string $field, mixed $value): string|int|float|bool|null
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return in_array($field, ['starts_on', 'ends_on'], true)
                ? $value->format('Y-m-d')
                : ApiTimestamp::format($value);
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        throw new RuntimeException('Contract audit values must remain bounded scalars.');
    }
}
