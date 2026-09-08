<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Services\AddressData\AddressDataSourceAdmission;

test('expected sha256 accepts only the canonical lowercase representation', function (?string $digest): void {
    expect(fn (): string => app(AddressDataSourceAdmission::class)->expectedSha256($digest))
        ->toThrow(RuntimeException::class, 'exactly 64 lowercase hexadecimal characters');
})->with([
    'missing' => [null],
    'short' => [str_repeat('a', 63)],
    'non hexadecimal' => [str_repeat('g', 64)],
    'uppercase' => [str_repeat('A', 64)],
    'surrounding whitespace' => [' '.str_repeat('a', 64)],
    'algorithm prefix' => ['sha256:'.str_repeat('a', 64)],
]);

test('remote source accepts only commit-pinned GitHub raw identities', function (string $url): void {
    expect(fn (): string => app(AddressDataSourceAdmission::class)->remoteSourceUrl($url))
        ->toThrow(RuntimeException::class, 'immutable commit-pinned GitHub raw URL');
})->with([
    'branch ref' => ['https://raw.githubusercontent.com/openpotato/openplzapi.data/refs/heads/main/src/de/osm/streets.updated.csv'],
    'github branch ref' => ['https://github.com/openpotato/openplzapi.data/raw/refs/heads/main/src/de/osm/streets.updated.csv'],
    'latest alias' => ['https://raw.githubusercontent.com/openpotato/openplzapi.data/latest/src/de/osm/streets.updated.csv'],
    'tag' => ['https://raw.githubusercontent.com/openpotato/openplzapi.data/v1.0.0/src/de/osm/streets.updated.csv'],
    'different host' => ['https://example.com/'.str_repeat('a', 40).'/streets.updated.csv'],
    'userinfo' => ['https://operator@raw.githubusercontent.com/openpotato/openplzapi.data/'.str_repeat('a', 40).'/src/de/osm/streets.updated.csv'],
    'query' => ['https://raw.githubusercontent.com/openpotato/openplzapi.data/'.str_repeat('a', 40).'/src/de/osm/streets.updated.csv?download=1'],
    'fragment' => ['https://raw.githubusercontent.com/openpotato/openplzapi.data/'.str_repeat('a', 40).'/src/de/osm/streets.updated.csv#reviewed'],
    'port' => ['https://raw.githubusercontent.com:443/openpotato/openplzapi.data/'.str_repeat('a', 40).'/src/de/osm/streets.updated.csv'],
]);

test('commit-pinned GitHub raw identity is accepted', function (): void {
    $url = 'https://raw.githubusercontent.com/openpotato/openplzapi.data/'.str_repeat('a', 40).'/src/de/osm/streets.updated.csv';

    expect(app(AddressDataSourceAdmission::class)->remoteSourceUrl($url))->toBe($url);
});
