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

FileName: app/Foo.php
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

FileName: app/Foo.php
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

FileName: app/Foo.php
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

FileName: app/Foo.php
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

test('license compatibility script allows documented non agpl exceptions in strict directories', function (): void {
    $result = runLicenseCompatibilityScript(<<<'SPDX'
SPDXVersion: SPDX-2.3
DataLicense: CC0-1.0
SPDXID: SPDXRef-DOCUMENT
DocumentName: sample
DocumentNamespace: https://secpal.dev/spdxdocs/sample

FileName: config/permission.php
SPDXID: SPDXRef-Config
LicenseInfoInFile: CC0-1.0
LicenseConcluded: CC0-1.0

FileName: tests/fixtures/address_data/sample_streets.csv
SPDXID: SPDXRef-Fixture
LicenseInfoInFile: ODbL-1.0
LicenseConcluded: ODbL-1.0
SPDX
    );

    expect($result['exit_code'])->toBe(0)
        ->and($result['stderr'])->toBe('');
});
