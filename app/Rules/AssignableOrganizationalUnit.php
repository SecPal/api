<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Rules;

use App\Models\OrganizationalUnit;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AssignableOrganizationalUnit implements ValidationRule
{
    public const MESSAGE = 'The selected organizational unit is not assignable.';

    public function __construct(
        private readonly mixed $tenantId,
        private readonly ?string $currentOrganizationalUnitId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (
            ! is_string($value)
            || $value === $this->currentOrganizationalUnitId
            || (! is_int($this->tenantId) && ! is_string($this->tenantId))
        ) {
            return;
        }

        $unit = OrganizationalUnit::query()
            ->withTrashed()
            ->select(['id', 'is_assignable', 'deleted_at'])
            ->whereKey($value)
            ->where('tenant_id', $this->tenantId)
            ->first();

        if ($unit !== null && ($unit->trashed() || ! $unit->is_assignable)) {
            $fail(self::MESSAGE);
        }
    }
}
