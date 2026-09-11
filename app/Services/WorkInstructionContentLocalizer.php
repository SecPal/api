<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Enums\ContentLocale;
use App\Exceptions\WorkInstructionContentIntegrityException;

final class WorkInstructionContentLocalizer
{
    /**
     * @param  array<string, array{title: string, body: string}>  $translations
     * @return array{locale: string, title: string, body: string, fallback_used: bool}
     */
    public function select(array $translations, ContentLocale $requested): array
    {
        $actual = $this->isUsable($translations[$requested->value] ?? null)
            ? $requested
            : $requested->fallback();
        $translation = $translations[$actual->value] ?? null;

        if (! $this->isUsable($translation)) {
            throw new WorkInstructionContentIntegrityException('Reusable content has no usable translation.');
        }
        assert($translation !== null);

        return [
            'locale' => $actual->value,
            'title' => $translation['title'],
            'body' => $translation['body'],
            'fallback_used' => $actual !== $requested,
        ];
    }

    /** @param array{title: string, body: string}|null $translation */
    private function isUsable(?array $translation): bool
    {
        return $translation !== null
            && trim($translation['title']) !== ''
            && mb_strlen($translation['title']) <= 255
            && trim($translation['body']) !== '';
    }
}
