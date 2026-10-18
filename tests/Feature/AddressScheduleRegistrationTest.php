<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;

test('retired schedule cannot be enabled by stale configuration', function (): void {
    config(['address_data.schedule_enabled' => true]);
    $schedule = reloadAddressSchedule();
    expect(collect($schedule->events())->map(fn ($event): string => $event->command ?? '')->implode("\n"))
        ->not->toContain('addresses:import');
});

function reloadAddressSchedule(): Schedule
{
    app()->forgetInstance(Schedule::class);
    Facade::clearResolvedInstance(Schedule::class);
    require base_path('routes/console.php');

    /** @var Schedule */
    return app(Schedule::class);
}
