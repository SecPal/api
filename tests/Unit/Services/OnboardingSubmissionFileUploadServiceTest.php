<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingSubmissionFile;
use App\Repositories\OnboardingSubmissionFileRepository;
use App\Services\OnboardingSubmissionFileStorageService;
use App\Services\OnboardingSubmissionFileUploadService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses()->group('unit', 'services', 'onboarding');

function onboardingUploadQueryException(string $sqlState, string $constraint): QueryException
{
    $previous = new PDOException("SQLSTATE[{$sqlState}]: {$constraint}", (int) $sqlState);
    $previous->errorInfo = [$sqlState, 7, $constraint];

    return new QueryException('pgsql', 'insert into onboarding_submission_files', [], $previous);
}

test('resolves the winning upload when the tenant idempotency constraint loses a race', function (): void {
    $submission = new OnboardingFormSubmission([
        'status' => 'draft',
    ]);
    $submission->id = 'submission-1';
    $file = UploadedFile::fake()->createWithContent('contract.pdf', 'same-contract');
    $existingUpload = new OnboardingSubmissionFile([
        'tenant_id' => 7,
        'onboarding_form_submission_id' => $submission->id,
        'document_type' => 'contract',
        'document_subtype' => null,
        'idempotency_key' => str_repeat('a', 32),
        'content_fingerprint' => str_repeat('f', 64),
        'file_name' => 'contract.pdf',
    ]);

    $storageService = Mockery::mock(OnboardingSubmissionFileStorageService::class);
    $storageService->shouldReceive('fingerprint')
        ->once()
        ->with($file, $submission, str_repeat('a', 32))
        ->andReturn(str_repeat('f', 64));
    $storageService->shouldReceive('sanitizedFilename')->once()->andReturn('contract.pdf');
    $storageService->shouldReceive('prepare')->once()->andReturn([
        'file_path' => 'employees/employee-1/onboarding-submissions/submission-1/file.enc',
        'file_name' => 'contract.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 13,
        'blob' => 'encrypted',
    ]);
    $storageService->shouldNotReceive('persist');

    $repository = Mockery::mock(OnboardingSubmissionFileRepository::class);
    $repository->shouldReceive('tenantId')->once()->with($submission)->andReturn(7);
    $repository->shouldReceive('findForTenantAndIdempotencyKey')
        ->twice()
        ->with(7, str_repeat('a', 32))
        ->andReturn(null, $existingUpload);
    $repository->shouldReceive('create')
        ->once()
        ->andThrow(onboardingUploadQueryException(
            '23505',
            'onboarding_submission_files_idempotency_unique',
        ));

    $result = (new OnboardingSubmissionFileUploadService($storageService, $repository))->upload(
        $file,
        $submission,
        'user-1',
        [
            'document_type' => 'contract',
            'idempotency_key' => str_repeat('a', 32),
        ],
    );

    expect($result)->toMatchArray([
        'file' => $existingUpload,
        'replayed' => true,
        'conflict' => false,
    ]);
});

test('fails instead of reporting success when a persisted blob cannot be cleaned up', function (): void {
    $submission = new OnboardingFormSubmission(['status' => 'draft']);
    $submission->id = 'submission-1';
    $file = UploadedFile::fake()->createWithContent('contract.pdf', 'same-contract');
    $storedPath = 'employees/employee-1/onboarding-submissions/submission-1/file.enc';

    $storageService = Mockery::mock(OnboardingSubmissionFileStorageService::class);
    $storageService->shouldReceive('fingerprint')
        ->once()
        ->with($file, $submission, str_repeat('a', 32))
        ->andReturn(str_repeat('f', 64));
    $storageService->shouldReceive('sanitizedFilename')->once()->andReturn('contract.pdf');
    $storageService->shouldReceive('prepare')->once()->andReturn([
        'file_path' => $storedPath,
        'file_name' => 'contract.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 13,
        'blob' => 'encrypted',
    ]);
    $storageService->shouldReceive('persist')->once();

    $uploadedFile = new OnboardingSubmissionFile;
    $repository = Mockery::mock(OnboardingSubmissionFileRepository::class);
    $repository->shouldReceive('tenantId')->once()->andReturn(7);
    $repository->shouldReceive('findForTenantAndIdempotencyKey')->once()->andReturnNull();
    $repository->shouldReceive('create')->once()->andReturn($uploadedFile);

    DB::shouldReceive('transaction')->once()->andReturnUsing(function (callable $callback): never {
        $callback();

        throw onboardingUploadQueryException('40001', 'serialization_failure');
    });
    $disk = Mockery::mock();
    $disk->shouldReceive('delete')->once()->with($storedPath)->andReturnFalse();
    Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);

    expect(fn () => (new OnboardingSubmissionFileUploadService($storageService, $repository))->upload(
        $file,
        $submission,
        'user-1',
        [
            'document_type' => 'contract',
            'idempotency_key' => str_repeat('a', 32),
        ],
    ))->toThrow(RuntimeException::class, 'Failed to delete stored onboarding submission file');
});

test('does not treat unrelated database failures as upload races', function (): void {
    $submission = new OnboardingFormSubmission(['status' => 'draft']);
    $submission->id = 'submission-1';
    $file = UploadedFile::fake()->createWithContent('contract.pdf', 'same-contract');
    $exception = onboardingUploadQueryException('23505', 'other_unique_constraint');

    $storageService = Mockery::mock(OnboardingSubmissionFileStorageService::class);
    $storageService->shouldReceive('fingerprint')
        ->once()
        ->with($file, $submission, str_repeat('a', 32))
        ->andReturn(str_repeat('f', 64));
    $storageService->shouldReceive('sanitizedFilename')->once()->andReturn('contract.pdf');
    $storageService->shouldReceive('prepare')->once()->andReturn([
        'file_path' => 'employees/employee-1/onboarding-submissions/submission-1/file.enc',
        'file_name' => 'contract.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 13,
        'blob' => 'encrypted',
    ]);
    $storageService->shouldNotReceive('persist');

    $repository = Mockery::mock(OnboardingSubmissionFileRepository::class);
    $repository->shouldReceive('tenantId')->once()->andReturn(7);
    $repository->shouldReceive('findForTenantAndIdempotencyKey')->once()->andReturnNull();
    $repository->shouldReceive('create')->once()->andThrow($exception);

    expect(fn () => (new OnboardingSubmissionFileUploadService($storageService, $repository))->upload(
        $file,
        $submission,
        'user-1',
        [
            'document_type' => 'contract',
            'idempotency_key' => str_repeat('a', 32),
        ],
    ))->toThrow($exception);
});
