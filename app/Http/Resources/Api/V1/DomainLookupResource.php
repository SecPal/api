<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Model */
final class DomainLookupResource extends JsonResource
{
    /** @return array{id: string, name: string} */
    public function toArray(Request $request): array
    {
        if (! $this->resource instanceof Model) {
            throw new \LogicException('Domain lookup resources require an Eloquent model.');
        }

        $id = $this->resource->getKey();
        $name = $this->resource->getAttribute('name');

        if (! is_string($id) || ! is_string($name)) {
            throw new \LogicException('Domain lookup models require string id and name attributes.');
        }

        return [
            'id' => $id,
            'name' => $name,
        ];
    }
}
