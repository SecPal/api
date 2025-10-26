<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Unit;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Test that application environment is set correctly.
     */
    public function test_environment_is_testing(): void
    {
        $this->assertEquals('testing', config('app.env'));
    }
}
