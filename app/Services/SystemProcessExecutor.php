<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ProcessExecutor;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Production process executor using Symfony Process component.
 *
 * Executes external CLI commands for OpenTimestamp verification.
 *
 * @see App\Contracts\ProcessExecutor
 */
class SystemProcessExecutor implements ProcessExecutor
{
    /**
     * {@inheritDoc}
     */
    public function execute(array $command, ?string $stdin = null, int $timeout = 10): array
    {
        $process = new Process($command);
        $process->setTimeout($timeout);

        if ($stdin !== null) {
            $process->setInput($stdin);
        }

        try {
            $process->run();

            return [
                'exitCode' => $process->getExitCode() ?? -1,
                'stdout' => $process->getOutput(),
                'stderr' => $process->getErrorOutput(),
            ];
        } catch (ProcessTimedOutException $e) {
            return [
                'exitCode' => -1,
                'stdout' => '',
                'stderr' => 'Process timed out after '.$timeout.' seconds',
            ];
        } catch (\Throwable $e) {
            return [
                'exitCode' => -1,
                'stdout' => '',
                'stderr' => 'Process failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function commandExists(string $command): bool
    {
        // Check if command is available in PATH
        $process = new Process(['which', $command]);
        $process->run();

        return $process->isSuccessful();
    }
}
