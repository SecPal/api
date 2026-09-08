<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Services\AddressData\AddressDataDownloader;

test('local sources are copied to the immutable snapshot that is hashed and imported', function (): void {
    $sourcePath = tempnam(sys_get_temp_dir(), 'address-data-source-');
    expect($sourcePath)->not->toBeFalse();

    $authorizedBytes = file_get_contents(base_path('tests/fixtures/address_data/sample_streets.csv'));
    expect($authorizedBytes)->not->toBeFalse();
    file_put_contents($sourcePath, $authorizedBytes);

    $downloaded = (new AddressDataDownloader)->download('', $sourcePath);

    try {
        expect($downloaded['path'])->not->toBe($sourcePath)
            ->and($downloaded['sha256'])->toBe(hash('sha256', $authorizedBytes));

        file_put_contents($sourcePath, "Wrong,Header\nmutated,after-admission\n");

        expect(file_get_contents($downloaded['path']))->toBe($authorizedBytes)
            ->and(hash_file('sha256', $downloaded['path']))->toBe($downloaded['sha256']);
    } finally {
        @unlink($sourcePath);
        @unlink($downloaded['path']);
    }
});
