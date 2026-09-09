<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentLocale;
use App\Enums\WorkInstructionStatus;
use App\Models\Concerns\EnforcesTenantRouteBinding;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $tenant_id
 * @property string $instruction_number
 * @property string $title
 * @property string $body
 * @property ContentLocale $locale
 * @property WorkInstructionStatus $status
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property string|null $published_by_user_id
 * @property \Illuminate\Support\Carbon|null $archived_at
 * @property string|null $archived_by_user_id
 * @property-read TenantKey $tenant
 * @property-read User|null $publishedBy
 * @property-read User|null $archivedBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WorkInstructionAcknowledgment> $acknowledgments
 */
class WorkInstruction extends Model
{
    /** @use HasFactory<\Database\Factories\WorkInstructionFactory> */
    use EnforcesTenantRouteBinding, HasFactory, HasUuids {
        EnforcesTenantRouteBinding::resolveRouteBindingQuery insteadof HasUuids;
        HasUuids::resolveRouteBindingQuery as resolveUuidRouteBindingQuery;
    }

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'instruction_number',
        'title',
        'body',
        'locale',
        'status',
        'published_at',
        'published_by_user_id',
        'archived_at',
        'archived_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'locale' => ContentLocale::class,
            'status' => WorkInstructionStatus::class,
            'published_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TenantKey, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /** @return BelongsTo<User, $this> */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

    /** @return HasMany<WorkInstructionAcknowledgment, $this> */
    public function acknowledgments(): HasMany
    {
        return $this->hasMany(WorkInstructionAcknowledgment::class);
    }
}
