<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DomainLookupResource;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\User;
use App\Services\DomainAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class DomainLookupController extends Controller
{
    public function __construct(private readonly DomainAccessService $domainAccess) {}

    public function legalEntities(Request $request): AnonymousResourceCollection
    {
        $this->authorizeDomainWriteLookup($this->user($request));

        return DomainLookupResource::collection(
            $this->domainAccess->writableLegalEntities($this->user($request), $request->integer('tenant_id'))
        );
    }

    public function establishments(Request $request, LegalEntity $legalEntity): AnonymousResourceCollection
    {
        $this->authorizeDomainWriteLookup($this->user($request));

        return DomainLookupResource::collection($this->domainAccess->writableEstablishments(
            $this->user($request),
            $request->integer('tenant_id'),
            $legalEntity->id,
        ));
    }

    public function customers(Request $request, Establishment $establishment): AnonymousResourceCollection
    {
        $user = $this->user($request);
        if (! $user->can('create', Site::class)) {
            $this->authorize('create', Customer::class);
            $this->authorize('viewAny', Customer::class);
        }

        return DomainLookupResource::collection($this->domainAccess->visibleCustomersForEstablishment(
            $user,
            $request->integer('tenant_id'),
            $establishment->id,
        ));
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function authorizeDomainWriteLookup(User $user): void
    {
        if ($user->can('create', Customer::class)
            || $user->can('create', Employee::class)
            || $user->can('create', Site::class)) {
            return;
        }

        throw new AuthorizationException;
    }
}
