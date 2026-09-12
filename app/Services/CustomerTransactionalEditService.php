<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CustomerTransactionalEditException;
use App\Http\Requests\Api\V1\CustomerTransactionalEditRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerEstablishment;
use App\Models\Establishment;
use App\Models\User;
use App\Repositories\CustomerEstablishmentRepository;
use App\Repositories\CustomerRepository;
use App\Support\CustomerRepresentationETag;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

final class CustomerTransactionalEditService
{
    private const CONTACT_FIELDS = ['contact_name', 'phone', 'email', 'comments'];

    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly CustomerEstablishmentRepository $customerEstablishments,
        private readonly CustomerService $customerService,
        private readonly DomainAccessService $domainAccess,
        private readonly CustomerTransactionalEditRequest $requestContract,
    ) {}

    public function ensureOperationAuthorized(User $user, int $tenantId): void
    {
        if ($user->tenant_id !== $tenantId
            || ! $user->can('customers.update')
            || ! $user->can('customers.read')
            || $user->organizationalScopes()->exists()) {
            throw CustomerTransactionalEditException::forbidden();
        }
    }

    public function edit(
        User $user,
        int $tenantId,
        string $customerId,
        ?string $ifMatch,
        string $requestContent,
    ): Customer {
        $this->ensureOperationAuthorized($user, $tenantId);

        try {
            return DB::transaction(function () use (
                $user,
                $tenantId,
                $customerId,
                $ifMatch,
                $requestContent,
            ): Customer {
                $this->customers->lockRepresentationWriters();
                $this->revalidateOperationAuthorization($user, $tenantId);

                $customer = $this->customers->findLockedForTenant($tenantId, $customerId)
                    ?? throw CustomerTransactionalEditException::notFound();

                $this->assertCurrentIfMatch($ifMatch, $this->currentRepresentationETag($customer));

                $payload = $this->requestContract->validate($requestContent, $customerId);
                $resultingLegalEntityId = $this->resultingLegalEntityId($customer, $payload['customer']);
                $this->validateCustomerDomain(
                    $user,
                    $tenantId,
                    $customer,
                    $resultingLegalEntityId,
                );

                $establishments = $this->validateAndLockEstablishments(
                    $tenantId,
                    $resultingLegalEntityId,
                    $payload['customer_establishments'],
                );
                $links = $this->customerEstablishments
                    ->lockAllIncludingTrashedForCustomer($tenantId, $customerId);

                $this->assertNoDependencyConflicts(
                    $customer,
                    $resultingLegalEntityId,
                    $payload['customer_establishments'],
                    $links,
                );

                $this->customers->lockRepresentationAuthorizationWriters();
                $this->revalidateOperationAuthorization($user, $tenantId);
                $this->assertCurrentIfMatch($ifMatch, $this->currentRepresentationETag($customer));

                $this->applyReplacement(
                    $tenantId,
                    $customer,
                    $payload['customer'],
                    $payload['customer_establishments'],
                    $establishments,
                    $links,
                );

                $this->revalidateOperationAuthorization($user, $tenantId);

                return $this->customerService->loadTransactionalCustomerResult($customer);
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23503', '23505'], true)) {
                throw CustomerTransactionalEditException::conflict($exception);
            }

            throw $exception;
        }
    }

    private function revalidateOperationAuthorization(User $user, int $tenantId): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->unsetRelations();
        $user->refresh();
        $this->ensureOperationAuthorized($user, $tenantId);
    }

    private function assertCurrentIfMatch(?string $ifMatch, string $currentETag): void
    {
        if ($ifMatch === null
            || preg_match('/^"[!#-~\x80-\xFF]*"$/D', $ifMatch) !== 1) {
            throw CustomerTransactionalEditException::badRequest();
        }

        if (! hash_equals($currentETag, $ifMatch)) {
            throw CustomerTransactionalEditException::stale();
        }
    }

    private function currentRepresentationETag(Customer $customer): string
    {
        $currentRepresentation = $this->customerService
            ->loadCompleteCustomerRepresentation($customer);
        $currentBody = [
            'data' => (new CustomerResource($currentRepresentation))->resolve(),
        ];

        return CustomerRepresentationETag::strong($currentBody);
    }

    /**
     * @param  array<string, mixed>  $customerAttributes
     */
    private function resultingLegalEntityId(Customer $customer, array $customerAttributes): string
    {
        $legalEntityId = $customerAttributes['legal_entity_id'] ?? $customer->legal_entity_id;

        if (! is_string($legalEntityId)) {
            throw new \LogicException('Validated legal_entity_id must be a string.');
        }

        return $legalEntityId;
    }

    private function validateCustomerDomain(
        User $user,
        int $tenantId,
        Customer $customer,
        string $resultingLegalEntityId,
    ): void {
        if ($resultingLegalEntityId === $customer->legal_entity_id) {
            return;
        }

        try {
            $this->domainAccess->ensureCustomerLegalEntityWritable(
                $user,
                $tenantId,
                $customer,
                $resultingLegalEntityId,
            );
        } catch (AuthorizationException) {
            throw CustomerTransactionalEditException::forbidden();
        } catch (ValidationException) {
            throw ValidationException::withMessages([
                'customer.legal_entity_id' => ['The customer field is invalid.'],
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     * @return Collection<string, Establishment>
     */
    private function validateAndLockEstablishments(
        int $tenantId,
        string $legalEntityId,
        array $assignments,
    ): Collection {
        $ids = array_values(array_unique(array_map(
            $this->establishmentId(...),
            $assignments,
        )));
        sort($ids);
        $establishments = $this->customerEstablishments
            ->lockEligibleEstablishments($tenantId, $legalEntityId, $ids)
            ->keyBy('id');

        $errors = [];
        foreach ($assignments as $index => $assignment) {
            if (! $establishments->has($this->establishmentId($assignment))) {
                $errors["customer_establishments.{$index}.establishment_id"] = [
                    'The selected establishment is invalid.',
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $establishments;
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     * @param  Collection<int, CustomerEstablishment>  $links
     */
    private function assertNoDependencyConflicts(
        Customer $customer,
        string $resultingLegalEntityId,
        array $assignments,
        Collection $links,
    ): void {
        if ($resultingLegalEntityId !== $customer->legal_entity_id
            && $this->customers->hasSites($customer)) {
            throw CustomerTransactionalEditException::conflict();
        }

        $desiredIds = array_fill_keys(array_map($this->establishmentId(...), $assignments), true);
        foreach ($links as $link) {
            if (! $link->trashed()
                && ! isset($desiredIds[$link->establishment_id])
                && $this->customerEstablishments->hasSites($link)) {
                throw CustomerTransactionalEditException::conflict();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $customerAttributes
     * @param  list<array<string, mixed>>  $assignments
     * @param  Collection<string, Establishment>  $establishments
     * @param  Collection<int, CustomerEstablishment>  $links
     */
    private function applyReplacement(
        int $tenantId,
        Customer $customer,
        array $customerAttributes,
        array $assignments,
        Collection $establishments,
        Collection $links,
    ): void {
        $desiredIds = array_fill_keys(array_map($this->establishmentId(...), $assignments), true);
        foreach ($links as $link) {
            if (! $link->trashed() && ! isset($desiredIds[$link->establishment_id])) {
                $this->customerEstablishments->delete($link);
            }
        }

        if ($customerAttributes !== []) {
            $this->customers->update($customer, $customerAttributes);
        }

        $linksByEstablishment = $links->keyBy('establishment_id');
        foreach ($assignments as $assignment) {
            $establishmentId = $this->establishmentId($assignment);
            $establishment = $establishments->get($establishmentId);
            if (! $establishment instanceof Establishment) {
                throw new \LogicException('Validated establishment must be locked.');
            }
            $existing = $linksByEstablishment->get($establishmentId);
            $contactAttributes = $this->plainContactAttributes(
                $assignment,
                clearMissing: $existing === null || $existing->trashed(),
            );

            if ($existing instanceof CustomerEstablishment) {
                if ($existing->trashed()) {
                    $this->customerEstablishments->restore($existing, [
                        'legal_entity_id' => $establishment->legal_entity_id,
                        ...$contactAttributes,
                    ]);
                } elseif ($contactAttributes !== []) {
                    $this->customerEstablishments->update($existing, $contactAttributes);
                }

                continue;
            }

            $this->customerEstablishments->create([
                'tenant_id' => $tenantId,
                'legal_entity_id' => $establishment->legal_entity_id,
                'customer_id' => $customer->id,
                'establishment_id' => $establishmentId,
                ...$contactAttributes,
            ]);
        }
    }

    /** @param array<string, mixed> $assignment */
    private function establishmentId(array $assignment): string
    {
        $establishmentId = $assignment['establishment_id'] ?? null;
        if (! is_string($establishmentId)) {
            throw new \LogicException('Validated establishment_id must be a string.');
        }

        return $establishmentId;
    }

    /**
     * @param  array<string, mixed>  $assignment
     * @return array<string, mixed>
     */
    private function plainContactAttributes(array $assignment, bool $clearMissing): array
    {
        $attributes = [];
        foreach (self::CONTACT_FIELDS as $field) {
            if ($clearMissing || array_key_exists($field, $assignment)) {
                $attributes["{$field}_plain"] = $assignment[$field] ?? null;
            }
        }

        return $attributes;
    }
}
