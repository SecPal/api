<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Exact-owned subprocess fixture: synthetic test DB inputs arrive only on stdin.
require __DIR__.'/../../vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
putenv('APP_ENV=testing');
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing.bootstrap');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config([
    'database.connections.pgsql' => $input['database'],
    'private_objects' => $input['objects'],
    'filesystems.disks.private_objects' => $input['disk'],
    'queue.default' => 'database',
    'queue.connections.database.after_commit' => true,
]);
Illuminate\Support\Facades\DB::purge('pgsql');
App\Models\TenantKey::setKekPath($input['kek_path']);
try {
    if ($input['operation'] === 'publish') {
        touch($input['barrier'].'.'.$input['participant']);
        $deadline = microtime(true) + 10;
        while ((! file_exists($input['barrier'].'.1') || ! file_exists($input['barrier'].'.2')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Native publisher barrier expired');
        }
        Illuminate\Support\Facades\DB::transaction(fn () => app(App\Services\PrivateObjectService::class)->publish(
            $input['object_id'], App\Models\TenantKey::findOrFail($input['tenant_id']),
            'employee-document', $input['business_id'],
        ));
    } elseif ($input['operation'] === 'worker') {
        config(['private_objects.purpose' => 'worker']);
        $app->make('queue.worker')->runNextJob(
            'database', 'private-object-test', new Illuminate\Queue\WorkerOptions(sleep: 0, maxTries: 1),
        );
    } else {
        throw new RuntimeException('Unknown native fixture operation');
    }
    echo json_encode(['result' => 'accepted']);
} catch (Throwable $exception) {
    $reason = match ($exception->getMessage()) {
        'Private Object namespace differs from its reservation' => 'namespace-mismatch',
        'Private Object already has another owner' => 'already-published',
        'Private Object runtime purpose is not authorized' => 'runtime-purpose',
        'Private Object read or integrity verification failed' => 'integrity',
        default => 'native-child-rejected',
    };
    echo json_encode(['result' => 'rejected', 'diagnostic' => [
        'reason' => $reason, 'class' => get_class($exception),
        'sqlstate' => $exception instanceof PDOException ? substr((string) $exception->getCode(), 0, 5) : null,
    ]]);
    exit(1);
}
