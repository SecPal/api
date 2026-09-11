<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Enums\ContentLocale;
use App\Exceptions\WorkInstructionContentTargetNotFoundException;
use App\Models\User;
use App\Models\WorkInstructionStandardBlock;
use App\Repositories\WorkInstructionStandardBlockRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

final readonly class WorkInstructionStandardBlockService
{
    public function __construct(
        private WorkInstructionStandardBlockRepository $blocks,
        private WorkInstructionContentLocalizer $localizer,
        private PermissionRegistrar $permissions,
    ) {}

    /** @return LengthAwarePaginator<int, WorkInstructionStandardBlock> */
    public function list(User $actor, ContentLocale $locale, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorizeActor($actor);
        $paginator = $this->blocks->paginate($locale, $page, $perPage);
        $paginator->setCollection($paginator->getCollection()->map(
            fn (WorkInstructionStandardBlock $block): WorkInstructionStandardBlock => $this->present($block, $locale),
        ));

        return $paginator;
    }

    public function inspect(User $actor, string $id, ContentLocale $locale): WorkInstructionStandardBlock
    {
        $this->authorizeActor($actor);
        try {
            $block = $this->blocks->inspect($id);
        } catch (ModelNotFoundException $exception) {
            throw new WorkInstructionContentTargetNotFoundException(previous: $exception);
        }
        Gate::forUser($actor)->authorize('view', $block);

        return $this->present($block, $locale);
    }

    private function present(WorkInstructionStandardBlock $block, ContentLocale $locale): WorkInstructionStandardBlock
    {
        /** @var array<string, array{title: string, body: string}> $translations */
        $translations = $block->translations->mapWithKeys(fn ($translation): array => [
            $translation->locale->value => ['title' => $translation->title, 'body' => $translation->body],
        ])->all();
        $block->setAttribute('localized_content', $this->localizer->select($translations, $locale));

        return $block;
    }

    private function authorizeActor(User $actor): void
    {
        Gate::forUser($actor)->authorize('viewAny', WorkInstructionStandardBlock::class);
        $tenantId = $this->permissions->getPermissionsTeamId();

        if (! is_int($tenantId) || $actor->tenant_id === null || $actor->tenant_id !== $tenantId) {
            throw new AuthorizationException;
        }
    }
}
