<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Repositories;

use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingSubmissionFile;

class OnboardingSubmissionFileRepository
{
    public function tenantId(OnboardingFormSubmission $submission): int
    {
        $employee = $submission->employee;
        if ($employee === null) {
            throw new \RuntimeException('Onboarding submission is missing its employee relation');
        }

        return (int) $employee->tenant_id;
    }

    public function findForTenantAndIdempotencyKey(
        int $tenantId,
        string $idempotencyKey,
    ): ?OnboardingSubmissionFile {
        return OnboardingSubmissionFile::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): OnboardingSubmissionFile
    {
        return OnboardingSubmissionFile::query()->create($attributes);
    }
}
