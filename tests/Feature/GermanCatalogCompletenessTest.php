<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

test('german gettext catalog has no empty translations', function () {
    $catalog = file_get_contents(lang_path('de/LC_MESSAGES/messages.po'));

    expect($catalog)->toBeString()->not->toBeFalse();

    $catalogSections = preg_split('/\n\n(?=#:\s)/', $catalog, 2);
    $catalogWithoutHeader = $catalogSections[1] ?? null;

    expect($catalogWithoutHeader)->toBeString();

    preg_match_all('/^msgstr(?:\[[0-9]+\])? ""$/m', $catalogWithoutHeader, $matches);

    expect($matches[0])->toBeArray()->toHaveCount(0);
});
