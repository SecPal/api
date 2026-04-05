<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Providers;

use Codewiser\Polyglot\PolyglotApplicationServiceProvider;
use Illuminate\Support\Facades\Gate;

class PolyglotServiceProvider extends PolyglotApplicationServiceProvider
{
    /**
     * Register the Polyglot gate.
     *
     * This gate determines who can access Polyglot in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewPolyglot', function ($user = null): bool {
            return false;
        });
    }
}
