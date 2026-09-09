<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * System-owned standard content, structurally separate from tenant templates.
 *
 * @property string $id
 * @property string $key
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WorkInstructionStandardBlockTranslation> $translations
 */
class WorkInstructionStandardBlock extends Model
{
    /** @use HasFactory<\Database\Factories\WorkInstructionStandardBlockFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = ['key'];

    /** @return HasMany<WorkInstructionStandardBlockTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(WorkInstructionStandardBlockTranslation::class);
    }
}
