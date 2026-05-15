<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $import_id
 * @property string $country_code
 * @property string $name
 * @property string $postal_code
 * @property string $locality
 * @property string|null $regional_key
 * @property string|null $borough
 * @property string|null $suburb
 * @property string $name_search
 * @property string $name_search_ascii
 * @property string $locality_search
 * @property string $locality_search_ascii
 */
class AddressStreet extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'import_id',
        'country_code',
        'name',
        'postal_code',
        'locality',
        'regional_key',
        'borough',
        'suburb',
        'name_search',
        'name_search_ascii',
        'locality_search',
        'locality_search_ascii',
    ];

    /**
     * @return BelongsTo<AddressDataImport, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(AddressDataImport::class, 'import_id');
    }
}
