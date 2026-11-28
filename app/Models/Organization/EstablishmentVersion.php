<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models\Organization;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $establishment_id
 * @property CarbonImmutable $effective_at
 * @property string $name
 * @property string|null $code
 * @property array<string, mixed> $address
 * @property bool $is_closed
 */
final class EstablishmentVersion extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'establishment_id', 'name', 'code', 'address', 'is_closed'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_at' => 'immutable_datetime', 'address' => 'array', 'is_closed' => 'boolean'];
    }

    public function closedAt(): ?CarbonImmutable
    {
        return $this->is_closed ? $this->effective_at : null;
    }
}
