<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use Illuminate\Console\Scheduling\Schedule;

test('scheduler registers addresses:import', function (): void {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $commands = collect($schedule->events())
        ->map(fn ($event): string => $event->command ?? '')
        ->implode("\n");

    expect($commands)->toContain('addresses:import');
});
