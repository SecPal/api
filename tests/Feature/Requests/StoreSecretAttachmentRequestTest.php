<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Requests\StoreSecretAttachmentRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

test('form request validates file is required', function () {
    $request = new StoreSecretAttachmentRequest;

    $validator = Validator::make([], $request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('file'))->toBeTrue();
});

test('form request validates file must be a file', function () {
    $request = new StoreSecretAttachmentRequest;

    $validator = Validator::make(['file' => 'not-a-file'], $request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('file'))->toBeTrue();
});

test('form request validates file size limit from config', function () {
    $request = new StoreSecretAttachmentRequest;

    // Create file larger than max size (default 10MB = 10240KB)
    $file = UploadedFile::fake()->create('large-file.pdf', 10241); // 10241KB > 10240KB

    $validator = Validator::make(['file' => $file], $request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('file'))->toBeTrue();
});

test('form request validates allowed mime types from config', function () {
    $request = new StoreSecretAttachmentRequest;

    // Create file with disallowed mime type (assume .exe is not allowed)
    $file = UploadedFile::fake()->create('virus.exe', 100);

    $validator = Validator::make(['file' => $file], $request->rules());

    // This test assumes config has mime type restrictions
    // If config allows all types, this test will fail - that's intentional
    // to catch security issues
    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('file'))->toBeTrue();
});

test('form request accepts valid file with allowed mime type', function () {
    $request = new StoreSecretAttachmentRequest;

    // Create valid PDF file (should be in allowed types)
    $file = UploadedFile::fake()->create('document.pdf', 100);

    $validator = Validator::make(['file' => $file], $request->rules());

    expect($validator->passes())->toBeTrue();
});

test('form request has custom error messages', function () {
    $request = new StoreSecretAttachmentRequest;

    expect($request->messages())->toBeArray();
    expect($request->messages())->not->toBeEmpty();
});

test('form request authorization always returns true for authenticated users', function () {
    $request = new StoreSecretAttachmentRequest;

    // Authorization is handled by Policy, not Form Request
    expect($request->authorize())->toBeTrue();
});
