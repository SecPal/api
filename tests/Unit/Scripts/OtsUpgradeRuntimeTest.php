<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

it('runs the focused OpenTimestamps upgrade helper tests', function (): void {
    $process = new Symfony\Component\Process\Process(
        ['python3', '-B', '-m', 'unittest', 'tests/Unit/Scripts/OtsUpgradeTest.py'],
        base_path(),
    );
    $process->setTimeout(30);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});
