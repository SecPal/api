<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Activity;

$activity = Activity::find(2);

if (!$activity || !$activity->ots_proof) {
    echo "No activity found!\n";
    exit(1);
}

$tempFile = tempnam(sys_get_temp_dir(), 'ots_');
file_put_contents($tempFile, $activity->ots_proof);

echo "=== Manual OTS Verification Test ===\n\n";
echo "Activity ID: {$activity->id}\n";
echo "Merkle Root: {$activity->merkle_root}\n";
echo "Proof file: {$tempFile}\n";
echo "Proof size: " . strlen($activity->ots_proof) . " bytes\n\n";

echo "Running: ots info {$tempFile}\n";
echo str_repeat("-", 80) . "\n";
passthru("ots info {$tempFile} 2>&1");

echo "\n\n";
echo "Running: ots verify {$tempFile} -d {$activity->merkle_root}\n";
echo str_repeat("-", 80) . "\n";
passthru("ots verify {$tempFile} -d {$activity->merkle_root} 2>&1");

echo "\n\n";

unlink($tempFile);
