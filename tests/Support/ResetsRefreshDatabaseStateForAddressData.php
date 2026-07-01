<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

trait ResetsRefreshDatabaseStateForAddressData
{
    use RefreshDatabase {
        refreshDatabase as private runRefreshDatabase;
    }

    public function refreshDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;

        $this->runRefreshDatabase();
    }
}
