<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property array<string, int|string|list<string>|null> $scope
 * @property string $phase
 * @property string|null $resume_phase
 * @property string|null $cursor
 * @property int $processed
 */
final class GlobalIdentityRotation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['scope' => 'array', 'processed' => 'integer'];
    }
}
