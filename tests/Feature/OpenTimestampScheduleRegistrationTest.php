<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

test('normal scheduler runs OpenTimestamp operational health without package update discovery', function (): void {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $commands = collect($schedule->events())
        ->map(fn ($event): string => $event->command ?? '')
        ->filter(fn (string $command): bool => str_contains($command, 'ots:'));

    expect($commands->contains(fn (string $command): bool => str_contains($command, 'ots:monitor')))->toBeTrue()
        ->and($commands->contains(fn (string $command): bool => str_contains($command, 'ots:check --update-check')))->toBeFalse();
});
