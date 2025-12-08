<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * OnboardingFormSubmission model.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $form_template_id
 * @property array $form_data
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property string|null $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property string|null $review_notes
 */
class OnboardingFormSubmission extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'form_template_id',
        'form_data',
        'status',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'form_data' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function formTemplate(): BelongsTo
    {
        return $this->belongsTo(OnboardingFormTemplate::class, 'form_template_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
