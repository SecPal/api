<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Services;

use App\Models\Establishment;
use App\Models\Site;
use App\Models\SiteAssignment;
use Carbon\CarbonInterface;

class OrganizationalUnitAssignmentService
{
    /** @param array<string, mixed> $validated */
    public function siteUpdateExpandsCoverage(Site $site, array $validated): bool
    {
        $updatedSite = clone $site;
        $updatedSite->fill($validated);

        if (! $this->siteHasCurrentOrFutureCoverage($updatedSite)) {
            return false;
        }

        if (! $this->siteHasCurrentOrFutureCoverage($site)) {
            return true;
        }

        return $this->startsCoverageEarlier($site->valid_from, $updatedSite->valid_from)
            || $this->endsCoverageLater($site->valid_until, $updatedSite->valid_until);
    }

    /** @param array<string, mixed> $validated */
    public function assignmentUpdateExpandsCoverage(SiteAssignment $assignment, array $validated): bool
    {
        $updatedAssignment = clone $assignment;
        $updatedAssignment->fill($validated);

        if (! $this->assignmentHasCurrentOrFutureCoverage($updatedAssignment)) {
            return false;
        }

        return $this->startsCoverageEarlier($assignment->valid_from, $updatedAssignment->valid_from)
            || $this->endsCoverageLater($assignment->valid_until, $updatedAssignment->valid_until)
            || ($assignment->role !== $updatedAssignment->role);
    }

    public function siteAcceptsAssignments(?Site $site): bool
    {
        if ($site === null) {
            return false;
        }

        $establishment = $site->establishment()->withTrashed()->first();

        return $this->establishmentAcceptsAssignments($establishment);
    }

    /** @param array<string, mixed> $validated */
    public function siteTargetAcceptsAssignments(Site $site, array $validated): bool
    {
        $establishmentId = $validated['establishment_id'] ?? $site->establishment_id;
        $establishment = Establishment::withTrashed()
            ->where('tenant_id', $site->tenant_id)
            ->where('legal_entity_id', $validated['legal_entity_id'] ?? $site->legal_entity_id)
            ->whereKey($establishmentId)
            ->first();

        return $this->establishmentAcceptsAssignments($establishment);
    }

    private function startsCoverageEarlier(?CarbonInterface $currentValidFrom, ?CarbonInterface $updatedValidFrom): bool
    {
        $today = now()->startOfDay();
        $currentStart = $currentValidFrom?->greaterThan($today) ? $currentValidFrom : $today;
        $updatedStart = $updatedValidFrom?->greaterThan($today) ? $updatedValidFrom : $today;

        return $updatedStart->lessThan($currentStart);
    }

    private function endsCoverageLater(?CarbonInterface $currentValidUntil, ?CarbonInterface $updatedValidUntil): bool
    {
        if ($currentValidUntil === null) {
            return false;
        }

        if ($updatedValidUntil === null) {
            return true;
        }

        return ! $updatedValidUntil->lessThan(now()->startOfDay())
            && $updatedValidUntil->greaterThan($currentValidUntil);
    }

    private function siteHasCurrentOrFutureCoverage(Site $site): bool
    {
        return $site->is_active
            && ($site->valid_until === null || ! $site->valid_until->lessThan(now()->startOfDay()));
    }

    private function assignmentHasCurrentOrFutureCoverage(SiteAssignment $assignment): bool
    {
        return $assignment->valid_until === null
            || ! $assignment->valid_until->lessThan(now()->startOfDay());
    }

    private function establishmentAcceptsAssignments(?Establishment $establishment): bool
    {
        return $establishment instanceof Establishment
            && ! $establishment->trashed()
            && $establishment->is_active;
    }
}
