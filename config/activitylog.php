<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

return [

    /*
     * If set to false, no activities will be saved to the database.
     */
    'enabled' => env('ACTIVITY_LOGGER_ENABLED', true),

    /*
     * When the clean-command is executed, all recording activities older than
     * the number of days specified here will be deleted.
     *
     * SecPal retention policy: BewachV §21 Abs. 4 compliance
     * "Die Aufzeichnungen und Belege sind bis zum Schluss des dritten auf den
     * Zeitpunkt ihrer Entstehung folgenden Kalenderjahres [...] aufzubewahren."
     *
     * This means: Activity created on 15. März 2023 must be kept until 31. Dezember 2026.
     * Calculation: End of the Nth calendar year FOLLOWING creation.
     *
     * DEPRECATED: This 'delete_records_older_than_days' setting is no longer used.
     * Use 'security_levels' configuration instead for proper calendar-year-based retention.
     *
     * @deprecated Use 'security_levels' configuration for BewachV-compliant retention
     * @see ADR-010 Section 3: Security Levels Configuration
     * @see BewachV §21 Abs. 4 (https://www.gesetze-im-internet.de/bewachv_2019/__21.html)
     */
    'delete_records_older_than_days' => null, // DEPRECATED - use security_levels instead

    /*
     * Activity Log Security Levels & Retention Policy
     *
     * BewachV § 21 Abs. 4: Records must be kept until the END of the Nth
     * FOLLOWING calendar year after creation.
     *
     * Example for 7 years:
     * - Event created: 15 March 2023
     * - Kept until: 31 December 2030 (end of 7th FOLLOWING year after 2023)
     * - Deletion from: 1 January 2031
     *
     * Three security levels with different retention periods:
     * - Level 1 (Basic): 3 years - Minimum BewachV compliance
     * - Level 2 (Enhanced): 5 years - Extended security
     * - Level 3 (Forensic): 7 years - Maximum forensic integrity
     */
    'security_levels' => [
        'basic' => [
            'delete_records_older_than_years' => 3,
            'enable_hash_chain' => false,
            'enable_merkle_tree' => false,
            'enable_opentimestamp' => false,
        ],
        'enhanced' => [
            'delete_records_older_than_years' => 5,
            'enable_hash_chain' => true,
            'enable_merkle_tree' => false,
            'enable_opentimestamp' => false,
        ],
        'forensic' => [
            'delete_records_older_than_years' => 7,
            'enable_hash_chain' => true,
            'enable_merkle_tree' => true,
            'enable_opentimestamp' => true,
        ],
    ],

    /*
     * Default security level for new activity logs.
     * Can be overridden per log_name or organizational_unit.
     */
    'default_security_level' => 'forensic',

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
    'subject_returns_soft_deleted_models' => false,

    /*
     * This model will be used to log activity.
     * It should implement the Spatie\Activitylog\Contracts\Activity interface
     * and extend Illuminate\Database\Eloquent\Model.
     *
     * SecPal uses a custom Activity model with hash chain, Merkle tree,
     * and OpenTimestamp extensions.
     *
     * @see App\Models\Activity (will be created in PR-2)
     * @see ADR-010 Section 2: Custom Activity Model with Extensions
     * @phpstan-ignore class.notFound (Activity model created in PR-2, Issue #387)
     */
    'activity_model' => \App\Models\Activity::class,

    /*
     * This is the name of the table that will be created by the migration and
     * used by the Activity model shipped with this package.
     */
    'table_name' => env('ACTIVITY_LOGGER_TABLE_NAME', 'activity_log'),

    /*
     * This is the database connection that will be used by the migration and
     * the Activity model shipped with this package. In case it's not set
     * Laravel's database.default will be used instead.
     */
    'database_connection' => env('ACTIVITY_LOGGER_DB_CONNECTION'),
];
