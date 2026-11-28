<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\GlobalIdentityKeyException;
use App\Services\GlobalIdentity\GlobalIdentityRotationCoordinator;
use Illuminate\Console\Command;

final class RotateGlobalIdentity extends Command
{
    protected $signature = 'identity:rotation {operation : Approved operation UUID} {action : start, advance, complete, pause, resume, retire or status} {--batch=100 : Maximum records per batch}';

    protected $description = 'Advance or inspect an approved Global Identity rotation';

    public function handle(GlobalIdentityRotationCoordinator $rotation): int
    {
        $operation = $this->argument('operation');
        $action = $this->argument('action');
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        if (! is_string($operation) || ! is_string($action) || $batch === false) {
            $this->error('Invalid rotation request.');

            return self::FAILURE;
        }
        try {
            $result = $rotation->execute($operation, $action, $batch);
        } catch (GlobalIdentityKeyException) {
            $this->error('Global Identity rotation unavailable. Check the restricted operational events.');

            return self::FAILURE;
        }
        $this->line(sprintf('Rotation phase %s. Processed records %d.', $result['phase'], $result['processed']));

        return self::SUCCESS;
    }
}
