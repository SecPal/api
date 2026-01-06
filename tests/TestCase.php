<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Setup method runs before each test.
     * Laravel's ParallelTesting automatically handles database separation.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Laravel's RefreshDatabase trait automatically uses parallel test databases
        // when running with --parallel flag. Database naming convention:
        // Base: "testing" → Parallel workers: "testing_test_1", "testing_test_2", etc.
        // No additional configuration needed - it's handled by Laravel automatically!
    }
}
