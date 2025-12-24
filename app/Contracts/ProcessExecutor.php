<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Contracts;

/**
 * Interface for executing external processes.
 *
 * Provides abstraction for testability of CLI calls.
 * Allows mocking in tests without executing real processes.
 *
 * @see App\Services\SystemProcessExecutor for production implementation
 */
interface ProcessExecutor
{
    /**
     * Execute a command and return exit code and output.
     *
     * @param  array<string>  $command  Command with arguments (e.g., ['ots', 'verify', '-f', '/path/file'])
     * @param  string|null  $stdin  Optional stdin input
     * @param  int  $timeout  Timeout in seconds
     * @return array{exitCode: int, stdout: string, stderr: string} Exit code, stdout, and stderr
     */
    public function execute(array $command, ?string $stdin = null, int $timeout = 10): array;

    /**
     * Check if a command is available in PATH.
     *
     * @param  string  $command  Command name (e.g., 'ots')
     * @return bool True if command exists
     */
    public function commandExists(string $command): bool;
}
