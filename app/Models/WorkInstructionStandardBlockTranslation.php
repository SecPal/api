<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentLocale;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $work_instruction_standard_block_id
 * @property ContentLocale $locale
 * @property string $title
 * @property string $body
 * @property-read WorkInstructionStandardBlock $standardBlock
 */
class WorkInstructionStandardBlockTranslation extends Model
{
    /** @use HasFactory<\Database\Factories\WorkInstructionStandardBlockTranslationFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'work_instruction_standard_block_id',
        'locale',
        'title',
        'body',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['locale' => ContentLocale::class];
    }

    /** @return BelongsTo<WorkInstructionStandardBlock, $this> */
    public function standardBlock(): BelongsTo
    {
        return $this->belongsTo(WorkInstructionStandardBlock::class, 'work_instruction_standard_block_id');
    }
}
