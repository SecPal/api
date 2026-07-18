#!/usr/bin/env php
<?php

/*
 * SPDX-FileCopyrightText: 2026 SecPal Contributors
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    fwrite(STDOUT, <<<'HELP'
Starts the SecPal API development services together:
  server  Laravel development server
  queue   database queue worker
  logs    Laravel Pail log viewer

Stopping one service stops the others.
HELP);
    fwrite(STDOUT, "\n");

    exit(0);
}

$php = (new PhpExecutableFinder)->find(false);

if (! is_string($php) || $php === '') {
    fwrite(STDERR, "Unable to locate the PHP executable.\n");

    exit(1);
}

$workingDirectory = dirname(__DIR__);
$commands = [
    'server' => [$php, 'artisan', 'serve'],
    'queue' => [$php, 'artisan', 'queue:listen', '--tries=1'],
    'logs' => [$php, 'artisan', 'pail', '--timeout=0'],
];
$processes = [];
$requestedSignal = null;
$interruptSignal = defined('SIGINT') ? SIGINT : 2;
$terminationSignal = defined('SIGTERM') ? SIGTERM : 15;
$exitCode = 0;

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);

    foreach ([$interruptSignal, $terminationSignal] as $signal) {
        pcntl_signal($signal, static function (int $receivedSignal) use (&$requestedSignal): void {
            $requestedSignal = $receivedSignal;
        });
    }
}

try {
    foreach ($commands as $name => $command) {
        $process = new Process($command, $workingDirectory);
        $process->setTimeout(null);
        $process->start(static function (string $type, string $buffer) use ($name): void {
            $stream = $type === Process::ERR ? STDERR : STDOUT;
            fwrite($stream, sprintf('[%s] %s', $name, $buffer));
        });
        $processes[$name] = $process;
    }

    while ($requestedSignal === null) {
        foreach ($processes as $name => $process) {
            if ($process->isRunning()) {
                continue;
            }

            $exitCode = $process->getExitCode() ?? 1;
            fwrite(STDERR, sprintf("[%s] exited with code %d; stopping development services.\n", $name, $exitCode));

            break 2;
        }

        usleep(100_000);
    }
} finally {
    foreach (array_reverse($processes) as $process) {
        if ($process->isRunning()) {
            $process->stop(3);
        }
    }
}

if ($requestedSignal !== null) {
    exit($requestedSignal === $interruptSignal ? 130 : 143);
}

exit($exitCode);
