<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OnboardingSubmissionFile extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'onboarding_form_submission_id',
        'uploaded_by',
        'document_type',
        'document_subtype',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
    ];

    /**
     * @return BelongsTo<OnboardingFormSubmission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(OnboardingFormSubmission::class, 'onboarding_form_submission_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
