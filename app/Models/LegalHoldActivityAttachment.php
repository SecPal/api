<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\EnforcesTenantRouteBinding;
use App\Models\Concerns\PreventsEvidenceDeletion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $tenant_id
 * @property string $legal_hold_id
 * @property int|null $activity_id
 * @property int $activity_identity_id
 * @property string|null $attached_by_user_id
 * @property string $attached_by_identity_id
 * @property \Illuminate\Support\Carbon $attached_at
 * @property \Illuminate\Support\Carbon|null $detached_at
 * @property string|null $detached_by_user_id
 * @property string|null $detached_by_identity_id
 * @property string|null $detachment_justification
 * @property-read TenantKey $tenant
 * @property-read LegalHold $legalHold
 * @property-read Activity|null $activity
 * @property-read User|null $attachedBy
 * @property-read User|null $detachedBy
 */
class LegalHoldActivityAttachment extends Model
{
    /** @use HasFactory<\Database\Factories\LegalHoldActivityAttachmentFactory> */
    use EnforcesTenantRouteBinding, HasFactory, HasUuids, PreventsEvidenceDeletion {
        EnforcesTenantRouteBinding::resolveRouteBindingQuery insteadof HasUuids;
        HasUuids::resolveRouteBindingQuery as resolveUuidRouteBindingQuery;
    }

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'legal_hold_id',
        'activity_id',
        'activity_identity_id',
        'attached_by_user_id',
        'attached_by_identity_id',
        'attached_at',
        'detached_at',
        'detached_by_user_id',
        'detached_by_identity_id',
        'detachment_justification',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'activity_id' => 'integer',
            'activity_identity_id' => 'integer',
            'attached_at' => 'immutable_datetime',
            'detached_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TenantKey, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /** @return BelongsTo<LegalHold, $this> */
    public function legalHold(): BelongsTo
    {
        return $this->belongsTo(LegalHold::class);
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return BelongsTo<User, $this> */
    public function attachedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attached_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function detachedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'detached_by_user_id');
    }
}
