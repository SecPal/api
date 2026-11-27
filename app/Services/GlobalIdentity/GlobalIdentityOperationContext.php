<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityKeyException;
use Illuminate\Support\Str;

final readonly class GlobalIdentityOperationContext
{
    public function __construct(
        public GlobalIdentityOperationType $type,
        public string $id,
        public ?string $userId,
        public ?int $emailVersion,
        public ?string $invitationId = null,
        public ?int $tenantId = null,
    ) {
        if (! self::isCanonicalId($id) || ($userId !== null && ! self::isCanonicalId($userId))) {
            throw new GlobalIdentityKeyException('Invalid global identity operation context.');
        }

        if ($type === GlobalIdentityOperationType::Invitation) {
            if ($invitationId !== $id || $tenantId === null || $tenantId < 1 || $emailVersion !== null) {
                throw new GlobalIdentityKeyException('Invalid global identity operation context.');
            }
        } elseif ($invitationId !== null || $tenantId !== null || $userId === null || $emailVersion === null || $emailVersion < 1) {
            throw new GlobalIdentityKeyException('Invalid global identity operation context.');
        }
    }

    public static function isCanonicalId(string $id): bool
    {
        return Str::isUuid($id) && $id === strtolower($id);
    }

    /** @return list<string> */
    public function bindingFields(): array
    {
        return [
            $this->type->value,
            $this->id,
            self::optional($this->userId),
            self::optional($this->emailVersion),
            self::optional($this->invitationId),
            self::optional($this->tenantId),
        ];
    }

    private static function optional(string|int|null $value): string
    {
        return $value === null ? "\x00" : "\x01".$value;
    }
}
