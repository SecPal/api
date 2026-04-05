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
     * The Polyglot web UI is not used — translation management is performed via
     * CLI commands and direct PO file editing. This gate blocks all access so
     * that the panel is never reachable, regardless of environment. The
     * DisablePolyglotUi middleware additionally returns a 404 in production.
     */
    protected function gate(): void
    {
        Gate::define('viewPolyglot', function ($user = null): bool {
            return false;
        });
    }
}
