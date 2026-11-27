<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class IdentityDisplay
{
    /** @var array<string, string> */
    private array $addresses = [];

    public function __construct(private readonly GlobalEmailRepository $email) {}

    public function address(string $userId, int $version): string
    {
        return $this->addresses[$userId.':'.$version] ??= $this->email->withEmail(
            $userId,
            $version,
            false,
            static fn (#[\SensitiveParameter] string $email): string => $email,
        );
    }

    /**
     * Prepare the user projections named by an authorized response. Only
     * traverse relationships already loaded for that response.
     *
     * @param  Model|iterable<Model>|null  $result
     */
    public function prepare(Model|iterable|null $result, string ...$paths): void
    {
        $paths = array_map(static fn (string $path): array => explode('.', $path), $paths);
        foreach ($result instanceof Model ? [$result] : ($result ?? []) as $model) {
            foreach ($paths as $path) {
                $this->preparePath($model, $path);
            }
        }
    }

    /** @param list<string> $path */
    private function preparePath(Model $model, array $path): void
    {
        $relation = array_shift($path);
        if ($relation === null) {
            if ($model instanceof User) {
                $model->prepareDisplayEmail($this->address($model->id, $model->email_version));
            }

            return;
        }
        if (! $model->relationLoaded($relation)) {
            return;
        }

        $related = $model->getRelation($relation);
        if ($related instanceof Model) {
            $this->preparePath($related, $path);
        } elseif (is_iterable($related)) {
            foreach ($related as $item) {
                if ($item instanceof Model) {
                    $this->preparePath($item, $path);
                }
            }
        }
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }
}
