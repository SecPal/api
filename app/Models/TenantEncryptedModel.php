<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use App\Services\TenantKekRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Keeps the DEK used by encrypted attributes consistent with their persistence. */
abstract class TenantEncryptedModel extends Model
{
    /** @var array{tenant: int, version: int}|null */
    private ?array $tenantDekWrite = null;

    public function rememberTenantDekWrite(int $tenantId, int $version): void
    {
        $reference = ['tenant' => $tenantId, 'version' => $version];
        if ($this->tenantDekWrite !== null && $this->tenantDekWrite !== $reference) {
            throw new RuntimeException('Tenant DEK changed before persistence.');
        }

        $this->tenantDekWrite = $reference;
    }

    /** @param Builder<static> $query */
    protected function performInsert(Builder $query): bool
    {
        return $this->persistWithTenantDek(fn (): bool => parent::performInsert($query));
    }

    /**
     * @param  Builder<static>  $query
     * @param  array<string>|string|null  $uniqueBy
     */
    protected function performInsertOrIgnore(Builder $query, array|string|null $uniqueBy): bool
    {
        return $this->persistWithTenantDek(fn (): bool => parent::performInsertOrIgnore($query, $uniqueBy));
    }

    /** @param Builder<static> $query */
    protected function performUpdate(Builder $query): bool
    {
        return $this->persistWithTenantDek(fn (): bool => parent::performUpdate($query));
    }

    /** @param callable(): bool $persist */
    private function persistWithTenantDek(callable $persist): bool
    {
        $saved = app(TenantKekRegistry::class)->withLease(function () use ($persist): bool {
            if ($this->tenantDekWrite !== null) {
                $tenant = Tenant::parseId($this->getAttribute('tenant_id'));
                if ($tenant !== $this->tenantDekWrite['tenant']
                    || TenantKey::forTenant($tenant)->key_version !== $this->tenantDekWrite['version']) {
                    throw new RuntimeException('Tenant DEK changed before persistence.');
                }
            }

            return $persist();
        });
        if ($saved) {
            $this->tenantDekWrite = null;
        }

        return $saved;
    }
}
