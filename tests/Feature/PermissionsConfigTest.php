<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

describe('Spatie Permissions Configuration', function () {
    it('has teams feature enabled', function () {
        expect(config('permission.teams'))->toBe(true);
    });

    it('has team_foreign_key set to tenant_id', function () {
        expect(config('permission.column_names.team_foreign_key'))->toBe('tenant_id');
    });

    it('has correct table names configured', function () {
        expect(config('permission.table_names.roles'))->toBe('roles')
            ->and(config('permission.table_names.permissions'))->toBe('permissions')
            ->and(config('permission.table_names.model_has_permissions'))->toBe('model_has_permissions')
            ->and(config('permission.table_names.model_has_roles'))->toBe('model_has_roles')
            ->and(config('permission.table_names.role_has_permissions'))->toBe('role_has_permissions');
    });

    it('has permission guard configured', function () {
        expect(config('permission.register_permission_check_method'))->toBe(true);
    });
});
