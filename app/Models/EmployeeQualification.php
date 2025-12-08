<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EmployeeQualification pivot model.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $qualification_id
 * @property \Illuminate\Support\Carbon $obtained_date
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property string|null $certificate_number
 * @property string|null $issuing_authority
 * @property string|null $notes
 * @property string|null $document_path
 * @property string $status
 */
class EmployeeQualification extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'qualification_id',
        'obtained_date',
        'expiry_date',
        'certificate_number',
        'issuing_authority',
        'notes',
        'document_path',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'obtained_date' => 'date',
            'expiry_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Qualification, $this>
     */
    public function qualification(): BelongsTo
    {
        return $this->belongsTo(Qualification::class);
    }
}
