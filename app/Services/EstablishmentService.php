<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization\Establishment;
use App\Models\Organization\EstablishmentVersion;
use App\Repositories\EstablishmentRepository;
use DateTimeInterface;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Persistence for callers that have already established tenant and action authority. */
final readonly class EstablishmentService
{
    public function __construct(private EstablishmentRepository $establishments) {}

    /** @param array<string, mixed> $address */
    public function create(int $tenantId, string $name, ?string $code, array $address): EstablishmentVersion
    {
        $details = $this->validateDetails($name, $code, $address);

        return DB::transaction(fn (): EstablishmentVersion => $this->establishments->append(
            $this->establishments->create($tenantId),
            $details,
            false,
        ));
    }

    public function current(int $tenantId, string $id): EstablishmentVersion
    {
        return $this->establishments->latest($tenantId, $id)
            ?? throw (new ModelNotFoundException)->setModel(Establishment::class);
    }

    /** A version applies from its database timestamp up to the next version. */
    public function at(int $tenantId, string $id, DateTimeInterface $effectiveAt): ?EstablishmentVersion
    {
        return $this->establishments->latest($tenantId, $id, $effectiveAt);
    }

    /**
     * Validate a same-tenant assignable target under the caller's transaction.
     * Keep the lock through the dependent write. For multiple targets, lock in
     * ID order. Authorization belongs to the caller.
     */
    public function lockAssignable(int $tenantId, string $id): EstablishmentVersion
    {
        $this->establishments->lock($tenantId, $id);
        $current = $this->current($tenantId, $id);
        $this->requireOpen($current);

        return $current;
    }

    /** @param array<string, mixed> $address */
    public function revise(int $tenantId, string $id, string $name, ?string $code, array $address): EstablishmentVersion
    {
        $details = $this->validateDetails($name, $code, $address);

        return DB::transaction(function () use ($tenantId, $id, $details): EstablishmentVersion {
            $establishment = $this->establishments->lock($tenantId, $id);
            $current = $this->current($tenantId, $id);
            $this->requireOpen($current);

            return $this->establishments->append($establishment, $details, false);
        });
    }

    public function close(int $tenantId, string $id): EstablishmentVersion
    {
        return DB::transaction(function () use ($tenantId, $id): EstablishmentVersion {
            $establishment = $this->establishments->lock($tenantId, $id);
            $current = $this->current($tenantId, $id);
            if ($current->is_closed) {
                return $current;
            }

            return $this->establishments->append($establishment, [
                'name' => $current->name,
                'code' => $current->code,
                'address' => $current->address,
            ], true);
        });
    }

    private function requireOpen(EstablishmentVersion $version): void
    {
        if ($version->is_closed) {
            throw new DomainException('The establishment is closed.');
        }
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array{name: string, code: ?string, address: array<string, mixed>}
     */
    private function validateDetails(string $name, ?string $code, array $address): array
    {
        foreach (['latitude', 'longitude'] as $coordinate) {
            $value = $address[$coordinate] ?? null;
            if (is_string($value) && trim($value) === '') {
                $address[$coordinate] = null;
            }
        }

        Validator::make(['name' => $name, 'code' => $code, 'address' => $address], [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            'address' => ['required', 'array:street,house_number,city,postal_code,country,latitude,longitude'],
            'address.street' => ['required', 'string', 'max:255'],
            'address.house_number' => ['nullable', 'string', 'max:10'],
            'address.city' => ['required', 'string', 'max:100'],
            'address.postal_code' => ['required', 'string', 'max:20'],
            'address.country' => ['required', 'string', 'size:2'],
            'address.latitude' => ['nullable', 'numeric:strict', 'between:-90,90'],
            'address.longitude' => ['nullable', 'numeric:strict', 'between:-180,180'],
        ])->validate();

        return ['name' => $name, 'code' => $code, 'address' => $address];
    }
}
