<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentLocale;
use App\Models\Concerns\EnforcesTenantRouteBinding;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $tenant_id
 * @property string $work_instruction_template_id
 * @property ContentLocale $locale
 * @property string $title
 * @property string $body
 * @property-read TenantKey $tenant
 * @property-read WorkInstructionTemplate $template
 */
class WorkInstructionTemplateTranslation extends Model
{
    /** @use HasFactory<\Database\Factories\WorkInstructionTemplateTranslationFactory> */
    use EnforcesTenantRouteBinding, HasFactory, HasUuids {
        EnforcesTenantRouteBinding::resolveRouteBindingQuery insteadof HasUuids;
        HasUuids::resolveRouteBindingQuery as resolveUuidRouteBindingQuery;
    }

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'work_instruction_template_id',
        'locale',
        'title',
        'body',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'locale' => ContentLocale::class,
        ];
    }

    /** @return BelongsTo<TenantKey, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /** @return BelongsTo<WorkInstructionTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkInstructionTemplate::class, 'work_instruction_template_id');
    }
}
