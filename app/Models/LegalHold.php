<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use App\Enums\LegalHoldStatus;
use App\Models\Concerns\EnforcesTenantRouteBinding;
use App\Models\Concerns\PreventsEvidenceDeletion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $tenant_id
 * @property string $case_reference
 * @property LegalHoldStatus $status
 * @property string $justification
 * @property string|null $created_by_user_id
 * @property string $created_by_identity_id
 * @property \Illuminate\Support\Carbon|null $released_at
 * @property string|null $released_by_user_id
 * @property string|null $released_by_identity_id
 * @property string|null $release_justification
 * @property-read TenantKey $tenant
 * @property-read User|null $createdBy
 * @property-read User|null $releasedBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, LegalHoldActivityAttachment> $attachments
 */
class LegalHold extends Model
{
    /** @use HasFactory<\Database\Factories\LegalHoldFactory> */
    use EnforcesTenantRouteBinding, HasFactory, HasUuids, PreventsEvidenceDeletion {
        EnforcesTenantRouteBinding::resolveRouteBindingQuery insteadof HasUuids;
        HasUuids::resolveRouteBindingQuery as resolveUuidRouteBindingQuery;
    }

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'case_reference',
        'status',
        'justification',
        'created_by_user_id',
        'created_by_identity_id',
        'released_at',
        'released_by_user_id',
        'released_by_identity_id',
        'release_justification',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'status' => LegalHoldStatus::class,
            'released_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TenantKey, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }

    /** @return HasMany<LegalHoldActivityAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(LegalHoldActivityAttachment::class);
    }
}
