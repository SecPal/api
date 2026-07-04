<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Support\LikePattern;

test('LikePattern escapes backslashes, percent signs, and underscores', function (): void {
    expect(LikePattern::escape('foo\\%_bar'))->toBe('foo\\\\\\%\\_bar');
});
