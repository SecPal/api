<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EmployeeDocument model.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $uploaded_by
 * @property string $title
 * @property string|null $description
 * @property string $document_type
 * @property string $file_path
 * @property string $file_name
 * @property string $mime_type
 * @property int $file_size
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property string $status
 * @property bool $visible_to_employee
 */
class EmployeeDocument extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'uploaded_by',
        'title',
        'description',
        'document_type',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'expiry_date',
        'status',
        'visible_to_employee',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'file_size' => 'integer',
            'visible_to_employee' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
