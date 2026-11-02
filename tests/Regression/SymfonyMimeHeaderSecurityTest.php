<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Mime\Header\ParameterizedHeader;

test('MIME attachment headers reject CRLF injection through parameter names', function (): void {
    expect(fn () => new ParameterizedHeader('Content-Disposition', 'attachment', [
        "filename\r\nBcc: injected@secpal.dev" => 'document.pdf',
    ]))->toThrow(RfcComplianceException::class);
});

test('MIME attachment headers preserve valid filename parameters', function (): void {
    $header = new ParameterizedHeader('Content-Disposition', 'attachment', [
        'filename' => 'report 2026.pdf',
    ]);

    expect($header->getParameter('filename'))->toBe('report 2026.pdf')
        ->and($header->getBodyAsString())->toBe('attachment; filename="report 2026.pdf"');
});
