<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Relations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Keeps immutable activity records readable after the enrollment subject model removal.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphTo<TRelatedModel, TDeclaringModel>
 */
class HistoricalActivityMorphTo extends MorphTo
{
    private const REMOVED_ENROLLMENT_SUBJECT = 'App\\Models\\AndroidEnrollmentSession';

    /**
     * @return Collection<int, TRelatedModel>
     */
    protected function getResultsByType($type): Collection
    {
        if ($type === self::REMOVED_ENROLLMENT_SUBJECT) {
            return new Collection;
        }

        return parent::getResultsByType($type);
    }
}
