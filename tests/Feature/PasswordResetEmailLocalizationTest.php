<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use Illuminate\Support\Facades\App;

test('password reset translation strings exist in English', function (): void {
    App::setLocale('en');

    expect(__('Reset Your Password'))->toBe('Reset Your Password')
        ->and(__('Hello'))->toBe('Hello')
        ->and(__('Security Notice:'))->toBe('Security Notice:')
        ->and(__('Thanks'))->toBe('Thanks')
        ->and(__('Reset Password'))->toBe('Reset Password');
});

test('password reset translation strings exist in German', function (): void {
    App::setLocale('de');

    expect(__('Reset Your Password'))->toBe('Passwort zurücksetzen')
        ->and(__('Hello'))->toBe('Hallo')
        ->and(__('Security Notice:'))->toBe('Sicherheitshinweis:')
        ->and(__('Thanks'))->toBe('Vielen Dank')
        ->and(__('Reset Password'))->toBe('Passwort zurücksetzen');
});

test('locale changes override an inherited gettext language', function (): void {
    $inheritedLanguage = getenv('LANGUAGE');
    putenv('LANGUAGE=en');

    try {
        App::setLocale('de');

        expect(__('Reset Your Password'))->toBe('Passwort zurücksetzen');
    } finally {
        $inheritedLanguage === false
            ? putenv('LANGUAGE')
            : putenv('LANGUAGE='.$inheritedLanguage);
    }
});

test('password reset translation with parameters works in English', function (): void {
    App::setLocale('en');

    $translated = __('Click the button below to reset your password. This link will expire in :minutes minutes.', ['minutes' => 60]);

    expect($translated)->toContain('60 minutes')
        ->and($translated)->toContain('Click the button');
});

test('password reset translation with parameters works in German', function (): void {
    App::setLocale('de');

    $translated = __('Click the button below to reset your password. This link will expire in :minutes minutes.', ['minutes' => 60]);

    expect($translated)->toContain('60 Minuten')
        ->and($translated)->toContain('Klicken Sie auf die Schaltfläche');
});

test('password reset button text translation with parameters works', function (): void {
    App::setLocale('de');

    $translated = __('If you\'re having trouble clicking the ":button" button, copy and paste the URL below into your web browser:', [
        'button' => __('Reset Password'),
    ]);

    expect($translated)
        ->toContain('Passwort zurücksetzen')
        ->toContain('kopieren Sie die folgende URL');
});
