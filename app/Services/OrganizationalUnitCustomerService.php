<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Services;

use App\Models\OrganizationalUnit;
use App\Repositories\OrganizationalUnitRepository;
use Illuminate\Support\Facades\DB;

final class OrganizationalUnitCustomerService
{
    public function __construct(
        private readonly OrganizationalUnitRepository $organizationalUnits,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(OrganizationalUnit $organizationalUnit, array $attributes): ?OrganizationalUnit
    {
        return DB::transaction(function () use ($organizationalUnit, $attributes): ?OrganizationalUnit {
            $lockedUnit = $this->organizationalUnits->lockUnit($organizationalUnit);

            if ($this->wouldInvalidateLinkedCustomers($lockedUnit, $attributes)) {
                return null;
            }

            return $this->organizationalUnits->update($lockedUnit, $attributes);
        });
    }

    public function delete(OrganizationalUnit $organizationalUnit): bool
    {
        return DB::transaction(function () use ($organizationalUnit): bool {
            $lockedUnit = $this->organizationalUnits->lockUnit($organizationalUnit);

            if ($this->organizationalUnits->hasLinkedCustomers($lockedUnit)) {
                return false;
            }

            $this->organizationalUnits->delete($lockedUnit);

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function wouldInvalidateLinkedCustomers(OrganizationalUnit $organizationalUnit, array $attributes): bool
    {
        $wouldBecomeUnusable = ($attributes['is_active'] ?? true) === false
            || ($attributes['is_legal_entity'] ?? true) === false;

        return $wouldBecomeUnusable
            && $this->organizationalUnits->hasLinkedCustomers($organizationalUnit);
    }
}
