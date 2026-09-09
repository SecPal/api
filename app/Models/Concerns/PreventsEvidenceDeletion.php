<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;

trait PreventsEvidenceDeletion
{
    protected static function bootPreventsEvidenceDeletion(): void
    {
        static::deleting(function (Model $model): never {
            throw new LogicException(sprintf('%s evidence cannot be deleted.', class_basename($model)));
        });
    }
}
