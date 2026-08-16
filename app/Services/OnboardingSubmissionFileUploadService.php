<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingSubmissionFile;
use App\Repositories\OnboardingSubmissionFileRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class OnboardingSubmissionFileUploadService
{
    public function __construct(
        private readonly OnboardingSubmissionFileStorageService $storageService,
        private readonly OnboardingSubmissionFileRepository $submissionFileRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{file: OnboardingSubmissionFile|null, replayed: bool, conflict: bool}
     */
    public function upload(
        UploadedFile $file,
        OnboardingFormSubmission $submission,
        string|int $uploadedBy,
        array $validated,
    ): array {
        $idempotencyKey = $validated['idempotency_key'] ?? null;
        $tenantId = $this->submissionFileRepository->tenantId($submission);
        $contentFingerprint = is_string($idempotencyKey)
            ? $this->storageService->fingerprint($file, $submission, $idempotencyKey)
            : null;
        $fileName = is_string($idempotencyKey)
            ? $this->storageService->sanitizedFilename($file)
            : null;

        if (is_string($idempotencyKey)) {
            $existingUpload = $this->submissionFileRepository
                ->findForTenantAndIdempotencyKey($tenantId, $idempotencyKey);
            if ($existingUpload !== null) {
                return $this->resolveExistingUpload(
                    $existingUpload,
                    $submission,
                    $validated,
                    $contentFingerprint,
                    $fileName,
                );
            }
        }

        if (! in_array($submission->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'status' => __('Files can only be uploaded while the onboarding submission is editable'),
            ]);
        }

        $storedFilePath = null;

        try {
            $preparedFile = $this->storageService->prepare($file, $submission);

            $uploadedFile = DB::transaction(function () use (
                $contentFingerprint,
                $idempotencyKey,
                $preparedFile,
                $submission,
                $tenantId,
                $uploadedBy,
                $validated,
                &$storedFilePath,
            ): OnboardingSubmissionFile {
                $uploadedFile = $this->submissionFileRepository->create([
                    'tenant_id' => $tenantId,
                    'onboarding_form_submission_id' => $submission->id,
                    'uploaded_by' => $uploadedBy,
                    'document_type' => $validated['document_type'],
                    'document_subtype' => $validated['document_subtype'] ?? null,
                    'idempotency_key' => $idempotencyKey,
                    'content_fingerprint' => $contentFingerprint,
                    'file_path' => $preparedFile['file_path'],
                    'file_name' => $preparedFile['file_name'],
                    'mime_type' => $preparedFile['mime_type'],
                    'file_size' => $preparedFile['file_size'],
                ]);

                $storedFilePath = $preparedFile['file_path'];
                $this->storageService->persist($preparedFile);

                return $uploadedFile;
            });
        } catch (QueryException $exception) {
            $this->deleteStoredFile($storedFilePath, $exception);

            if (
                is_string($idempotencyKey)
                && $this->isIdempotencyUniqueViolation($exception)
            ) {
                $existingUpload = $this->submissionFileRepository
                    ->findForTenantAndIdempotencyKey($tenantId, $idempotencyKey);
                if ($existingUpload !== null) {
                    return $this->resolveExistingUpload(
                        $existingUpload,
                        $submission,
                        $validated,
                        $contentFingerprint,
                        $fileName,
                    );
                }
            }

            throw $exception;
        } catch (\Throwable $exception) {
            $this->deleteStoredFile($storedFilePath, $exception);

            throw $exception;
        }

        return ['file' => $uploadedFile, 'replayed' => false, 'conflict' => false];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{file: OnboardingSubmissionFile|null, replayed: bool, conflict: bool}
     */
    private function resolveExistingUpload(
        OnboardingSubmissionFile $uploadedFile,
        OnboardingFormSubmission $submission,
        array $validated,
        ?string $contentFingerprint,
        ?string $fileName,
    ): array {
        $conflict = $contentFingerprint === null
            || $fileName === null
            || $uploadedFile->trashed()
            || $uploadedFile->onboarding_form_submission_id !== $submission->id
            || ! hash_equals((string) $uploadedFile->content_fingerprint, $contentFingerprint)
            || ! hash_equals((string) $uploadedFile->file_name, $fileName)
            || $uploadedFile->document_type !== $validated['document_type']
            || $uploadedFile->document_subtype !== ($validated['document_subtype'] ?? null);

        return $conflict
            ? ['file' => null, 'replayed' => false, 'conflict' => true]
            : ['file' => $uploadedFile, 'replayed' => true, 'conflict' => false];
    }

    private function deleteStoredFile(?string $storedFilePath, \Throwable $previous): void
    {
        if (
            $storedFilePath !== null
            && Storage::disk('local')->delete($storedFilePath) === false
        ) {
            throw new \RuntimeException(
                'Failed to delete stored onboarding submission file after upload failure',
                previous: $previous,
            );
        }
    }

    private function isIdempotencyUniqueViolation(QueryException $exception): bool
    {
        return (string) $exception->getCode() === '23505'
            && str_contains(
                $exception->getMessage(),
                'onboarding_submission_files_idempotency_unique',
            );
    }
}
