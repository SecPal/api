<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support;

use App\Models\GlobalIdentityWorkingKey;
use App\Services\GlobalIdentity\FileGlobalIdentityRootProvider;
use App\Services\GlobalIdentity\GlobalIdentityKeyReference;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyRegistry;

final class GlobalIdentityTestFixture
{
    public static function prepare(): void
    {
        if (config()->string('global_identity.root_directory') !== '') {
            return;
        }

        static $directory = null;
        $root = new GlobalIdentityKeyReference('synthetic-fixture', 1);
        if ($directory === null) {
            $directory = sys_get_temp_dir().'/secpal-identity-fixture-'.bin2hex(random_bytes(12));
            mkdir($directory, 0700);
            (new FileGlobalIdentityRootProvider($directory))->provision($root);
            $ownerPid = getmypid();
            register_shutdown_function(static function () use ($directory, $ownerPid): void {
                if (getmypid() === $ownerPid) {
                    unlink($directory.'/synthetic-fixture.1.key');
                    rmdir($directory);
                }
            });
        }
        config(['global_identity.root_directory' => $directory]);
        if (! GlobalIdentityWorkingKey::query()->exists()) {
            app(GlobalIdentityWorkingKeyRegistry::class)->initialize($root);
        }
    }
}
