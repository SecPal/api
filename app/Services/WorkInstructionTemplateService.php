<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Enums\ContentLocale;
use App\Exceptions\WorkInstructionContentTargetNotFoundException;
use App\Models\User;
use App\Models\WorkInstructionTemplate;
use App\Repositories\WorkInstructionTemplateRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

final readonly class WorkInstructionTemplateService
{
    public function __construct(
        private WorkInstructionTemplateRepository $templates,
        private WorkInstructionContentLocalizer $localizer,
        private PermissionRegistrar $permissions,
    ) {}

    /** @return LengthAwarePaginator<int, WorkInstructionTemplate> */
    public function list(User $actor, ContentLocale $locale, int $page, int $perPage): LengthAwarePaginator
    {
        $paginator = $this->templates->paginate(
            $this->authorizeActor($actor, 'viewAny'),
            $locale,
            $page,
            $perPage,
        );
        $paginator->setCollection($paginator->getCollection()->map(
            fn (WorkInstructionTemplate $template): WorkInstructionTemplate => $this->present($template, $locale),
        ));

        return $paginator;
    }

    public function inspect(User $actor, string $id, ContentLocale $locale): WorkInstructionTemplate
    {
        $tenantId = $this->authorizeActor($actor, 'viewAny');
        $template = $this->find($tenantId, $id);
        Gate::forUser($actor)->authorize('view', $template);

        return $this->present($template, $locale);
    }

    /** @param array<string, array{title: string, body: string}> $translations */
    public function create(User $actor, array $translations, ContentLocale $locale): WorkInstructionTemplate
    {
        $tenantId = $this->authorizeActor($actor, 'create');

        return $this->present(DB::transaction(
            fn (): WorkInstructionTemplate => $this->templates->create($tenantId, $translations),
        ), $locale);
    }

    /** @param array<string, array{title: string, body: string}> $translations */
    public function replace(User $actor, string $id, array $translations, ContentLocale $locale): WorkInstructionTemplate
    {
        $tenantId = $this->authorizeActor($actor, 'update');

        try {
            $template = DB::transaction(function () use ($actor, $tenantId, $id, $translations): WorkInstructionTemplate {
                $locked = $this->templates->lock($tenantId, $id);
                Gate::forUser($actor)->authorize('update', $locked);

                return $this->templates->replace($locked, $translations);
            });
        } catch (ModelNotFoundException $exception) {
            throw new WorkInstructionContentTargetNotFoundException(previous: $exception);
        }

        return $this->present($template, $locale);
    }

    private function find(int $tenantId, string $id): WorkInstructionTemplate
    {
        try {
            return $this->templates->inspect($tenantId, $id);
        } catch (ModelNotFoundException $exception) {
            throw new WorkInstructionContentTargetNotFoundException(previous: $exception);
        }
    }

    private function present(WorkInstructionTemplate $template, ContentLocale $locale): WorkInstructionTemplate
    {
        /** @var array<string, array{title: string, body: string}> $translations */
        $translations = $template->translations
            ->sortBy(fn ($translation): int => $translation->locale === ContentLocale::German ? 0 : 1)
            ->mapWithKeys(fn ($translation): array => [$translation->locale->value => [
                'title' => $translation->title,
                'body' => $translation->body,
            ]])
            ->all();

        $template->setAttribute('public_translations', $translations);
        $template->setAttribute('localized_content', $this->localizer->select($translations, $locale));

        return $template;
    }

    private function authorizeActor(User $actor, string $ability): int
    {
        Gate::forUser($actor)->authorize($ability, WorkInstructionTemplate::class);
        $tenantId = $this->permissions->getPermissionsTeamId();

        if (! is_int($tenantId) || $actor->tenant_id === null || $actor->tenant_id !== $tenantId) {
            throw new AuthorizationException;
        }

        return $tenantId;
    }
}
