<?php

/**
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

declare(strict_types=1);

return [

    /*
     * If set to false, no activities will be saved to the database.
     */
    'enabled' => env('ACTIVITYLOG_ENABLED', true),

    /*
     * If no log name is passed to the activity() helper
     * we use this default log name.
     */
    'default_log_name' => 'default',

    /*
     * You can specify an auth driver here that gets user models.
     * If this is null we'll use the current Laravel auth driver.
     *
     * SecPal uses Sanctum for API authentication.
     */
    'default_auth_driver' => 'sanctum',

    /*
     * If set to true, the subject returns soft deleted models.
     */
    'include_soft_deleted_subjects' => false,

    /*
     * This model will be used to log activity.
     * It should implement the Spatie\Activitylog\Contracts\Activity interface
     * and extend Illuminate\Database\Eloquent\Model.
     *
     * SecPal uses a custom Activity model with hash chain, Merkle tree,
     * and OpenTimestamp extensions.
     *
     * @see App\Models\Activity
     * @see ADR-010 Section 2: Custom Activity Model with Extensions
     */
    'activity_model' => App\Models\Activity::class,

    /*
     * These attributes will be excluded from logging for all models.
     */
    'default_except_attributes' => [],

    /*
     * These action classes can be overridden to customize how activities
     * are logged and cleaned.
     */
    'actions' => [
        'log_activity' => Spatie\Activitylog\Actions\LogActivityAction::class,
        'clean_log' => Spatie\Activitylog\Actions\CleanActivityLogAction::class,
    ],
];
