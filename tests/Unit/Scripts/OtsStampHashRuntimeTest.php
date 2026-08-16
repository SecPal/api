<?php

/**
 * SPDX-FileCopyrightText: 2026 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use Symfony\Component\Process\Process;

test('OpenTimestamps stamping script merges calendar responses and enforces its threshold', function (): void {
    $process = new Process(
        ['python3', '-B', '-m', 'unittest', 'tests/Unit/Scripts/OtsStampHashTest.py'],
        base_path(),
    );

    $process->run();

    expect($process->getExitCode())
        ->toBe(0, $process->getOutput().$process->getErrorOutput());
});
