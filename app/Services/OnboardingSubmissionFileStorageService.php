<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\OnboardingFormSubmission;
use App\Models\TenantKey;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OnboardingSubmissionFileStorageService
{
    /**
     * @return array{file_path: string, file_name: string, mime_type: string, file_size: int, blob: string}
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

        $encrypted = $tenant->encrypt($content);
        $path = sprintf('employees/%s/onboarding-submissions/%s/%s.enc', $employee->id, $submission->id, Str::uuid()->toString());

        $blob = json_encode([
            'ciphertext' => base64_encode($encrypted['ciphertext']),
            'nonce' => base64_encode($encrypted['nonce']),
        ], JSON_THROW_ON_ERROR);

        return [
            'file_path' => $path,
            'file_name' => $this->sanitizedFilename($file),
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'blob' => $blob,
        ];
    }

    /**
     * @param  array{file_path: string, file_name: string, mime_type: string, file_size: int, blob: string}  $preparedFile
     */
    public function persist(array $preparedFile): void
    {
        if (Storage::disk('local')->put($preparedFile['file_path'], $preparedFile['blob']) === false) {
            throw new \RuntimeException('Failed to store encrypted onboarding submission file blob');
        }
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

    private function resolveTenant(OnboardingFormSubmission $submission): TenantKey
    {
        $employee = $submission->employee;
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
