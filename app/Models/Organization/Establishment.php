<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models\Organization;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The stable identity that tenant-local assignments and scopes reference.
 *
 * @property string $id
 * @property int $tenant_id
 */
final class Establishment extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'establishments';

    /** @var list<string> */
    protected $fillable = ['tenant_id'];
}
