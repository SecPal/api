<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\ContentLocale;
use App\Models\WorkInstructionTemplate;
use Illuminate\Pagination\LengthAwarePaginator;

final class WorkInstructionTemplateRepository
{
    /** @return LengthAwarePaginator<int, WorkInstructionTemplate> */
    public function paginate(int $tenantId, ContentLocale $locale, int $page, int $perPage): LengthAwarePaginator
    {
        return WorkInstructionTemplate::query()
            ->forTenant($tenantId)
            ->with('translations')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->appends(['per_page' => $perPage, 'locale' => $locale->value]);
    }

    public function inspect(int $tenantId, string $id): WorkInstructionTemplate
    {
        return WorkInstructionTemplate::query()
            ->forTenant($tenantId)
            ->with('translations')
            ->whereKey($id)
            ->firstOrFail();
    }

    public function lock(int $tenantId, string $id): WorkInstructionTemplate
    {
        return WorkInstructionTemplate::query()
            ->forTenant($tenantId)
            ->whereKey($id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @param array<string, array{title: string, body: string}> $translations */
    public function create(int $tenantId, array $translations): WorkInstructionTemplate
    {
        $template = WorkInstructionTemplate::query()->create(['tenant_id' => $tenantId]);
        $this->insertTranslations($template, $tenantId, $translations);

        return $this->reload($template);
    }

    /** @param array<string, array{title: string, body: string}> $translations */
    public function replace(WorkInstructionTemplate $template, array $translations): WorkInstructionTemplate
    {
        $template->translations()->delete();
        $this->insertTranslations($template, $template->tenant_id, $translations);
        $template->touch();

        return $this->reload($template);
    }

    /** @param array<string, array{title: string, body: string}> $translations */
    private function insertTranslations(WorkInstructionTemplate $template, int $tenantId, array $translations): void
    {
        foreach ($translations as $locale => $translation) {
            $template->translations()->create([
                'tenant_id' => $tenantId,
                'locale' => $locale,
                'title' => $translation['title'],
                'body' => $translation['body'],
            ]);
        }
    }

    private function reload(WorkInstructionTemplate $template): WorkInstructionTemplate
    {
        return $template->refresh()->load('translations');
    }
}
