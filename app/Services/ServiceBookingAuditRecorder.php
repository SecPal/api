<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Models\Activity;
use App\Models\ServiceBooking;
use App\Models\User;
use App\Support\ApiTimestamp;
use BackedEnum;
use DateTimeInterface;
use RuntimeException;

class ServiceBookingAuditRecorder
{
    private const int SCHEMA_VERSION = 1;

    /** @var list<string> */
    private const AUDIT_FIELDS = [
        'contract_id',
        'service_date',
        'quantity',
        'billing_unit',
        'unit_price',
        'currency_code',
        'total',
        'invoice_state',
        'invoiced_at',
        'status',
        'retired_at',
    ];

    /** @return array<string, scalar|null> */
    public function snapshot(ServiceBooking $serviceBooking): array
    {
        $snapshot = [];

        foreach (self::AUDIT_FIELDS as $field) {
            $snapshot[$field] = $this->scalar($field, $serviceBooking->getAttribute($field));
        }

        return $snapshot;
    }

    public function recordCreate(User $actor, ServiceBooking $serviceBooking): Activity
    {
        return $this->record(
            $actor,
            $serviceBooking,
            'create',
            self::AUDIT_FIELDS,
            [],
            $this->snapshot($serviceBooking),
        );
    }

    /** @param array<string, scalar|null> $previous */
    public function recordUpdate(User $actor, ServiceBooking $serviceBooking, array $previous): Activity
    {
        $current = $this->snapshot($serviceBooking);
        $changedFields = array_values(array_filter(
            ServiceBooking::MUTABLE_BUSINESS_FIELDS,
            static fn (string $field): bool => ($previous[$field] ?? null) !== ($current[$field] ?? null),
        ));

        return $this->record($actor, $serviceBooking, 'update', $changedFields, $previous, $current);
    }

    /** @param array<string, scalar|null> $previous */
    public function recordRetire(User $actor, ServiceBooking $serviceBooking, array $previous): Activity
    {
        return $this->record(
            $actor,
            $serviceBooking,
            'retire',
            ['status', 'retired_at'],
            $previous,
            $this->snapshot($serviceBooking),
        );
    }

    /**
     * @param  list<string>  $changedFields
     * @param  array<string, scalar|null>  $previous
     * @param  array<string, scalar|null>  $current
     */
    private function record(
        User $actor,
        ServiceBooking $serviceBooking,
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

        $audit = activity('service_booking_change')
            ->causedBy($actor)
            ->performedOn($serviceBooking)
            ->useLog('service_booking_change')
            ->event('service_booking.'.$operation)
            ->withProperties([
                'schema_version' => self::SCHEMA_VERSION,
                'operation' => $operation,
                'service_booking_id' => $serviceBooking->id,
                'changed_fields' => $changedFields,
                'changes' => $changes,
            ])
            ->tap(function ($activity) use ($serviceBooking): void {
                /** @var Activity $activity */
                $activity->tenant_id = $serviceBooking->tenant_id;
                $activity->suppressRequestOrganizationalUnitCapture();
            })
            ->log('Service Booking financial mutation');

        if (! $audit instanceof Activity) {
            throw new RuntimeException('Required Service Booking audit activity was not persisted.');
        }

        return $audit;
    }

    private function scalar(string $field, mixed $value): string|int|float|bool|null
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $field === 'service_date'
                ? $value->format('Y-m-d')
                : ApiTimestamp::format($value);
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        throw new RuntimeException('Service Booking audit values must remain bounded scalars.');
    }
}
