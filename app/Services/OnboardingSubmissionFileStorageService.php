<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingSubmissionFile;
use App\Models\TenantKey;
use Illuminate\Http\UploadedFile;

class OnboardingSubmissionFileStorageService
{
    public function __construct(private readonly PrivateObjectService $objects) {}

    /**
     * @return array{file_path: string, file_name: string, mime_type: string, file_size: int, private_object_id: string}
     */
    public function prepare(UploadedFile $file, OnboardingFormSubmission $submission): array
    {
        $content = $this->readContent($file);
        $tenant = $this->resolveTenant($submission);
        $employee = $submission->employee;
        if ($employee === null) {
            throw new \RuntimeException('Onboarding submission is missing its employee relation');
        }

        $mimeType = $file->getMimeType();
        if ($mimeType === null) {
            throw new \RuntimeException('Failed to determine onboarding submission file MIME type');
        }

        $fileSize = $file->getSize();
        if ($fileSize === false) {
            throw new \RuntimeException('Failed to determine onboarding submission file size');
        }

        $object = $this->objects->stage($tenant, 'onboarding-file', $content);

        return [
            'file_path' => $object['object_key'],
            'private_object_id' => $object['id'],
            'file_name' => $this->sanitizedFilename($file),
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
        ];
    }

    /**
     * @param  array{file_path: string, file_name: string, mime_type: string, file_size: int, private_object_id: string}  $preparedFile
     */
    public function persist(array $preparedFile): void
    {
        $record = OnboardingSubmissionFile::query()->where('file_path', $preparedFile['file_path'])->sole();
        $tenant = $this->resolveTenant($record->submission);
        $this->objects->publish($preparedFile['private_object_id'], $tenant, 'onboarding-file', $record->id);
    }

    public function retrieve(OnboardingSubmissionFile $file): string
    {
        return $this->objects->read($this->resolveTenant($file->submission), 'onboarding-file', $file->id);
    }

    public function delete(OnboardingSubmissionFile $file): void
    {
        $this->objects->tombstone($this->resolveTenant($file->submission), 'onboarding-file', $file->id);
    }

    public function fingerprint(
        UploadedFile $file,
        OnboardingFormSubmission $submission,
        string $idempotencyKey,
    ): string {
        $fingerprintPayload = $this->encodeFingerprintComponents([
            'onboarding-submission-file:v1',
            (string) $submission->id,
            $idempotencyKey,
            $file->getClientOriginalName(),
            $this->readContent($file),
        ]);
        $tenant = $this->resolveTenant($submission);

        return bin2hex($tenant->generateBlindIndex($fingerprintPayload));
    }

    public function sanitizedFilename(UploadedFile $file): string
    {
        return $this->sanitizeFilename($file->getClientOriginalName());
    }

    private function readContent(UploadedFile $file): string
    {
        $content = file_get_contents($file->getRealPath());
        if ($content === false) {
            throw new \RuntimeException('Failed to read uploaded onboarding submission file');
        }

        return $content;
    }

    /**
     * @param  list<string>  $components
     */
    private function encodeFingerprintComponents(array $components): string
    {
        $payload = '';

        foreach ($components as $component) {
            $payload .= strlen($component).':'.$component;
        }

        return $payload;
    }

    private function resolveTenant(?OnboardingFormSubmission $submission): TenantKey
    {
        $employee = $submission?->employee;
        if ($employee === null) {
            throw new \RuntimeException('Onboarding submission is missing its employee relation');
        }

        $tenant = $employee->tenant;
        if ($tenant === null) {
            throw new \RuntimeException('Onboarding submission employee must belong to a tenant');
        }

        return $tenant;
    }

    private function sanitizeFilename(string $fileName): string
    {
        $sanitized = preg_replace('/[\x00-\x1F\x7F]+/', '', $fileName);
        $sanitized = is_string($sanitized) ? $sanitized : '';
        $sanitized = str_replace(['\\', '/', '"', ';'], '_', $sanitized);
        $sanitized = trim($sanitized);

        if ($sanitized === '') {
            return 'document';
        }

        // Enforce the DB column limit while preserving extension when possible.
        if (mb_strlen($sanitized) > 255) {
            $ext = pathinfo($sanitized, PATHINFO_EXTENSION);
            $base = mb_substr($sanitized, 0, $ext !== '' ? 254 - mb_strlen($ext) : 255);
            $sanitized = $ext !== '' ? $base.'.'.$ext : $base;
        }

        return $sanitized;
    }
}
