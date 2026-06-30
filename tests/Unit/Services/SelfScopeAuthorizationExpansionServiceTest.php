<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\UserInternalOrganizationalScope;
use App\Services\SelfScopeAuthorizationExpansionService;

describe('SelfScopeAuthorizationExpansionService::doesNotExpandAuthorizationComparedTo', function () {
    beforeEach(function (): void {
        $this->service = app(SelfScopeAuthorizationExpansionService::class);
    });

    it('allows self-scope changes that only narrow effective authorization', function (): void {
        $currentScope = new UserInternalOrganizationalScope([
            'access_level' => 'manage',
            'include_descendants' => true,
            'min_viewable_rank' => 1,
            'max_viewable_rank' => 5,
            'min_assignable_rank' => 1,
            'max_assignable_rank' => 5,
            'allow_self_access' => true,
        ]);

        $narrowedScope = new UserInternalOrganizationalScope([
            'access_level' => 'write',
            'include_descendants' => false,
            'min_viewable_rank' => 2,
            'max_viewable_rank' => 4,
            'min_assignable_rank' => 2,
            'max_assignable_rank' => 3,
            'allow_self_access' => false,
        ]);

        expect($this->service->doesNotExpandAuthorizationComparedTo($narrowedScope, $currentScope))->toBeTrue();
    });

    it('rejects self-scope changes that elevate the access level', function (): void {
        $currentScope = new UserInternalOrganizationalScope([
            'access_level' => 'write',
            'include_descendants' => false,
            'allow_self_access' => false,
        ]);

        $expandedScope = new UserInternalOrganizationalScope([
            'access_level' => 'manage',
            'include_descendants' => false,
            'allow_self_access' => false,
        ]);

        expect($this->service->doesNotExpandAuthorizationComparedTo($expandedScope, $currentScope))->toBeFalse();
    });

    it('rejects self-scope changes that newly include descendants or self access', function (): void {
        $currentScope = new UserInternalOrganizationalScope([
            'access_level' => 'manage',
            'include_descendants' => false,
            'allow_self_access' => false,
        ]);

        $descendantExpansion = new UserInternalOrganizationalScope([
            'access_level' => 'manage',
            'include_descendants' => true,
            'allow_self_access' => false,
        ]);

        $selfAccessExpansion = new UserInternalOrganizationalScope([
            'access_level' => 'manage',
            'include_descendants' => false,
            'allow_self_access' => true,
        ]);

        expect($this->service->doesNotExpandAuthorizationComparedTo($descendantExpansion, $currentScope))->toBeFalse()
            ->and($this->service->doesNotExpandAuthorizationComparedTo($selfAccessExpansion, $currentScope))->toBeFalse();
    });

    it('rejects self-scope changes that widen the viewable rank range', function (): void {
        $currentScope = new UserInternalOrganizationalScope([
            'access_level' => 'manage',
            'include_descendants' => false,
            'min_viewable_rank' => 2,
            'max_viewable_rank' => 3,
            'min_assignable_rank' => 2,
            'max_assignable_rank' => 3,
            'allow_self_access' => false,
        ]);

        $expandedScope = new UserInternalOrganizationalScope([
            'access_level' => 'manage',
            'include_descendants' => false,
            'min_viewable_rank' => 1,
            'max_viewable_rank' => 4,
            'min_assignable_rank' => 2,
            'max_assignable_rank' => 3,
            'allow_self_access' => false,
        ]);

        expect($this->service->doesNotExpandAuthorizationComparedTo($expandedScope, $currentScope))->toBeFalse();
    });

    it('rejects self-scope changes that widen the assignable rank range', function (): void {
        $currentScope = new UserInternalOrganizationalScope([
            'access_level' => 'manage',
            'include_descendants' => false,
            'min_viewable_rank' => 1,
            'max_viewable_rank' => 5,
            'min_assignable_rank' => 2,
            'max_assignable_rank' => 3,
            'allow_self_access' => false,
        ]);

        $expandedScope = new UserInternalOrganizationalScope([
            'access_level' => 'manage',
            'include_descendants' => false,
            'min_viewable_rank' => 1,
            'max_viewable_rank' => 5,
            'min_assignable_rank' => 1,
            'max_assignable_rank' => 4,
            'allow_self_access' => false,
        ]);

        expect($this->service->doesNotExpandAuthorizationComparedTo($expandedScope, $currentScope))->toBeFalse();
    });
});
