<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use InvalidArgumentException;

final readonly class GlobalEmailLookupVersions
{
    /** @var non-empty-list<positive-int> */
    public array $accepted;

    /**
     * V1 accepts pairs with normalization version 1 and explicitly selected
     * key versions. A different normalization requires its own accepted policy.
     *
     * @param  array<mixed>  $accepted
     */
    public function __construct(public int $write, array $accepted)
    {
        if (! array_is_list($accepted) || ! in_array($write, $accepted, true)) {
            throw new InvalidArgumentException('Invalid global email lookup versions.');
        }
        $versions = [];
        foreach ($accepted as $version) {
            if (! is_int($version) || $version < 1 || in_array($version, $versions, true)) {
                throw new InvalidArgumentException('Invalid global email lookup versions.');
            }
            $versions[] = $version;
        }
        $this->accepted = $versions;
    }
}
