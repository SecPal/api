<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use Illuminate\Support\Str;

function runLicenseCompatibilityScript(string $spdxContents): array
{
    if (! function_exists('proc_open')) {
        test()->markTestSkipped('proc_open is required for license compatibility script coverage.');
    }

    $spdxPath = storage_path('framework/testing/'.Str::uuid().'.spdx');
    file_put_contents($spdxPath, $spdxContents);

    $process = proc_open(
        ['bash', 'scripts/check-license-compatibility.sh', $spdxPath],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        base_path(),
        [
            'LC_ALL' => 'C',
        ],
    );

    expect($process)->toBeResource();

    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    unlink($spdxPath);

    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

test('license compatibility script accepts the secpal attribution expression', function (): void {
    $result = runLicenseCompatibilityScript(<<<'SPDX'
SPDXVersion: SPDX-2.3
DataLicense: CC0-1.0
SPDXID: SPDXRef-DOCUMENT
DocumentName: sample
DocumentNamespace: https://secpal.dev/spdxdocs/sample

FileName: app/Models/User.php
SPDXID: SPDXRef-File
LicenseInfoInFile: AGPL-3.0-or-later
LicenseInfoInFile: LicenseRef-SecPal-Attribution
LicenseConcluded: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
SPDX
    );

    expect($result['exit_code'])->toBe(0)
        ->and($result['stderr'])->not->toContain('incompatible');
});

test('license compatibility script rejects permissive or-licensing on application code', function (): void {
    $result = runLicenseCompatibilityScript(<<<'SPDX'
SPDXVersion: SPDX-2.3
DataLicense: CC0-1.0
SPDXID: SPDXRef-DOCUMENT
DocumentName: sample
DocumentNamespace: https://secpal.dev/spdxdocs/sample

FileName: app/Models/User.php
SPDXID: SPDXRef-File
LicenseInfoInFile: AGPL-3.0-or-later
LicenseInfoInFile: MIT
LicenseConcluded: AGPL-3.0-or-later OR MIT
SPDX
    );

    expect($result['exit_code'])->toBe(1)
        ->and($result['stderr'])->toContain('incompatible license expression');
});

test('license compatibility script rejects strict-path files that omit the secpal attribution pair', function (): void {
    $result = runLicenseCompatibilityScript(<<<'SPDX'
SPDXVersion: SPDX-2.3
DataLicense: CC0-1.0
SPDXID: SPDXRef-DOCUMENT
DocumentName: sample
DocumentNamespace: https://secpal.dev/spdxdocs/sample

FileName: app/Models/User.php
SPDXID: SPDXRef-File
LicenseInfoInFile: MIT
LicenseConcluded: MIT
SPDX
    );

    expect($result['exit_code'])->toBe(1)
        ->and($result['stderr'])->toContain('strict-path files must use exactly AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution');
});

test('license compatibility script rejects strict-path or-licensing when license concluded is noassertion', function (): void {
    $result = runLicenseCompatibilityScript(<<<'SPDX'
SPDXVersion: SPDX-2.3
DataLicense: CC0-1.0
SPDXID: SPDXRef-DOCUMENT
DocumentName: sample
DocumentNamespace: https://secpal.dev/spdxdocs/sample

FileName: app/Models/User.php
SPDXID: SPDXRef-File
LicenseInfoInFile: AGPL-3.0-or-later
LicenseInfoInFile: MIT
LicenseConcluded: NOASSERTION
SPDX
    );

    expect($result['exit_code'])->toBe(1)
        ->and($result['stderr'])->toContain('strict-path files must use exactly AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution');
});

test('license compatibility script allows documented public cc0 assets', function (): void {
    $result = runLicenseCompatibilityScript(<<<'SPDX'
SPDXVersion: SPDX-2.3
DataLicense: CC0-1.0
SPDXID: SPDXRef-DOCUMENT
DocumentName: sample
DocumentNamespace: https://secpal.dev/spdxdocs/sample

FileName: public/robots.txt
SPDXID: SPDXRef-File
LicenseInfoInFile: CC0-1.0
LicenseConcluded: CC0-1.0
SPDX
    );

    expect($result['exit_code'])->toBe(0)
        ->and($result['stderr'])->toBe('');
});

test('license compatibility script allows documented third-party configuration templates in strict directories', function (): void {
    $laravelTemplateFiles = [
        'config/app.php',
        'config/auth.php',
        'config/cache.php',
        'config/cors.php',
        'config/database.php',
        'config/filesystems.php',
        'config/logging.php',
        'config/mail.php',
        'config/queue.php',
        'config/sanctum.php',
        'config/services.php',
        'config/session.php',
    ];

    $laravelTemplateSpdx = implode("\n\n", array_map(
        static function (string $path): string {
            $spdxId = str_replace(['/', '.'], '-', $path);

            return <<<SPDX
FileName: {$path}
SPDXID: SPDXRef-{$spdxId}
LicenseInfoInFile: MIT
LicenseConcluded: MIT
SPDX;
        },
        $laravelTemplateFiles,
    ));

    $result = runLicenseCompatibilityScript(<<<SPDX
SPDXVersion: SPDX-2.3
DataLicense: CC0-1.0
SPDXID: SPDXRef-DOCUMENT
DocumentName: sample
DocumentNamespace: https://secpal.dev/spdxdocs/sample

FileName: config/permission.php
SPDXID: SPDXRef-Config
LicenseInfoInFile: MIT
LicenseConcluded: MIT

{$laravelTemplateSpdx}

FileName: tests/fixtures/address_data/sample_streets.csv
SPDXID: SPDXRef-Fixture
LicenseInfoInFile: ODbL-1.0
LicenseConcluded: ODbL-1.0
SPDX
    );

    expect($result['exit_code'])->toBe(0)
        ->and($result['stderr'])->toBe('');
});

test('license compatibility script rejects a non mit license for a third-party configuration template', function (): void {
    $result = runLicenseCompatibilityScript(<<<'SPDX'
SPDXVersion: SPDX-2.3
DataLicense: CC0-1.0
SPDXID: SPDXRef-DOCUMENT
DocumentName: sample
DocumentNamespace: https://secpal.dev/spdxdocs/sample

FileName: config/app.php
SPDXID: SPDXRef-ConfigApp
LicenseInfoInFile: CC0-1.0
LicenseConcluded: CC0-1.0
SPDX
    );

    expect($result['exit_code'])->toBe(1)
        ->and($result['stderr'])->toContain('documented exception must use exactly MIT');
});

test('license compatibility script allows the Contributor Covenant third-party notice', function (): void {
    $result = runLicenseCompatibilityScript(<<<'SPDX'
SPDXVersion: SPDX-2.3
DataLicense: CC0-1.0
SPDXID: SPDXRef-DOCUMENT
DocumentName: sample
DocumentNamespace: https://secpal.dev/spdxdocs/sample

FileName: CODE_OF_CONDUCT.md
SPDXID: SPDXRef-File
LicenseInfoInFile: CC-BY-4.0
LicenseConcluded: CC-BY-4.0
SPDX
    );

    expect($result['exit_code'])->toBe(0)
        ->and($result['stderr'])->toBe('');
});

test('license compatibility script rejects the attribution addendum in documentation', function (): void {
    $result = runLicenseCompatibilityScript(<<<'SPDX'
SPDXVersion: SPDX-2.3
DataLicense: CC0-1.0
SPDXID: SPDXRef-DOCUMENT
DocumentName: sample
DocumentNamespace: https://secpal.dev/spdxdocs/sample

FileName: docs/rbac-architecture.md
SPDXID: SPDXRef-File
LicenseInfoInFile: AGPL-3.0-or-later
LicenseInfoInFile: LicenseRef-SecPal-Attribution
LicenseConcluded: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
SPDX
    );

    expect($result['exit_code'])->toBe(1)
        ->and($result['stderr'])->toContain('attribution addendum is only permitted for SecPal-owned AGPL code and assets');
});

test('license compatibility script rejects concluded documentation attribution despite a cc0 example', function (): void {
    $result = runLicenseCompatibilityScript(<<<'SPDX'
SPDXVersion: SPDX-2.3
DataLicense: CC0-1.0
SPDXID: SPDXRef-DOCUMENT
DocumentName: sample
DocumentNamespace: https://secpal.dev/spdxdocs/sample

FileName: docs/rbac-architecture.md
SPDXID: SPDXRef-File
LicenseInfoInFile: AGPL-3.0-or-later
LicenseInfoInFile: LicenseRef-SecPal-Attribution
LicenseInfoInFile: CC0-1.0
LicenseConcluded: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
SPDX
    );

    expect($result['exit_code'])->toBe(1)
        ->and($result['stderr'])->toContain('attribution addendum is only permitted for SecPal-owned AGPL code and assets');
});

test('license compatibility script rejects concluded documentation attribution paired with cc0', function (): void {
    $result = runLicenseCompatibilityScript(<<<'SPDX'
SPDXVersion: SPDX-2.3
DataLicense: CC0-1.0
SPDXID: SPDXRef-DOCUMENT
DocumentName: sample
DocumentNamespace: https://secpal.dev/spdxdocs/sample

FileName: docs/rbac-architecture.md
SPDXID: SPDXRef-File
LicenseInfoInFile: CC0-1.0
LicenseInfoInFile: LicenseRef-SecPal-Attribution
LicenseConcluded: CC0-1.0 AND LicenseRef-SecPal-Attribution
SPDX
    );

    expect($result['exit_code'])->toBe(1)
        ->and($result['stderr'])->toContain('attribution addendum is only permitted for SecPal-owned AGPL code and assets');
});

test('license compatibility script rejects documentation attribution when concluded is noassertion', function (): void {
    $result = runLicenseCompatibilityScript(<<<'SPDX'
SPDXVersion: SPDX-2.3
DataLicense: CC0-1.0
SPDXID: SPDXRef-DOCUMENT
DocumentName: sample
DocumentNamespace: https://secpal.dev/spdxdocs/sample

FileName: docs/rbac-architecture.md
SPDXID: SPDXRef-File
LicenseInfoInFile: CC0-1.0
LicenseInfoInFile: AGPL-3.0-or-later
LicenseInfoInFile: LicenseRef-SecPal-Attribution
LicenseConcluded: NOASSERTION
SPDX
    );

    expect($result['exit_code'])->toBe(1)
        ->and($result['stderr'])->toContain('attribution addendum is only permitted for SecPal-owned AGPL code and assets');
});

test('license compatibility script allows ci concluded documentation after ignoring attribution examples', function (): void {
    $result = runLicenseCompatibilityScript(<<<'SPDX'
SPDXVersion: SPDX-2.3
DataLicense: CC0-1.0
SPDXID: SPDXRef-DOCUMENT
DocumentName: sample
DocumentNamespace: https://secpal.dev/spdxdocs/sample

FileName: CONTRIBUTING.md
SPDXID: SPDXRef-File
LicenseInfoInFile: CC0-1.0
LicenseConcluded: CC0-1.0
SPDX
    );

    expect($result['exit_code'])->toBe(0)
        ->and($result['stderr'])->toBe('');
});
