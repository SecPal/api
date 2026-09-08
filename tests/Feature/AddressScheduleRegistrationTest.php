<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;

test('scheduler omits address import and performs no network request when disabled', function (): void {
    Http::fake();
    config([
        'address_data.schedule_enabled' => false,
        'address_data.source_url' => null,
        'address_data.expected_sha256' => null,
    ]);

    expect(config('address_data.schedule_enabled'))->toBeFalse()
        ->and(config('address_data.source_url'))->toBeNull()
        ->and(config('address_data.expected_sha256'))->toBeNull();

    $schedule = reloadAddressSchedule();

    $commands = collect($schedule->events())
        ->map(fn ($event): string => $event->command ?? '')
        ->implode("\n");

    expect($commands)->not->toContain('addresses:import');
    Http::assertNothingSent();
});

test('enabled address import schedule retains single-server overlap protection', function (): void {
    config(['address_data.schedule_enabled' => true]);
    $schedule = reloadAddressSchedule();
    $event = collect($schedule->events())
        ->first(fn ($candidate): bool => $candidate->description === 'address-data-update');

    expect($event)->not->toBeNull()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});

function reloadAddressSchedule(): Schedule
{
    app()->forgetInstance(Schedule::class);
    Facade::clearResolvedInstance(Schedule::class);
    require base_path('routes/console.php');

    /** @var Schedule */
    return app(Schedule::class);
}
