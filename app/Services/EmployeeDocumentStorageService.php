<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Services;

use App\Exceptions\EmployeeDocumentFileNotFoundException;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores employee document binaries encrypted at rest.
 */
class EmployeeDocumentStorageService
{
    /**
     * Store an uploaded employee document in encrypted form.
     *
     * @return array{file_path: string, file_name: string, mime_type: string, file_size: int}
     */
    public function store(UploadedFile $file, Employee $employee): array
    {
        $content = file_get_contents($file->getRealPath());
        if ($content === false) {
            throw new \RuntimeException('Failed to read uploaded employee document');
        }

        $tenant = $employee->tenant;
        if ($tenant === null) {
            throw new \RuntimeException('Employee must belong to a tenant');
        }

        $mimeType = $file->getMimeType();
        if ($mimeType === null) {
            throw new \RuntimeException('Failed to determine employee document MIME type');
        }

        $fileSize = $file->getSize();
        if ($fileSize === false) {
            throw new \RuntimeException('Failed to determine employee document size');
        }

        $encrypted = $tenant->encrypt($content);
        $path = sprintf('employees/%s/documents/%s.enc', $employee->id, Str::uuid()->toString());

        $blob = json_encode([
            'ciphertext' => base64_encode($encrypted['ciphertext']),
            'nonce' => base64_encode($encrypted['nonce']),
        ], JSON_THROW_ON_ERROR);

        Storage::disk('local')->put($path, $blob);

        return [
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
        ];
    }

    /**
     * Retrieve and decrypt an employee document.
     */
    public function retrieve(EmployeeDocument $document): string
    {
        $storage = Storage::disk('local');

        if (! $storage->exists($document->file_path)) {
            throw new EmployeeDocumentFileNotFoundException('Employee document not found in storage');
        }

        $encryptedBlob = $storage->get($document->file_path);
        if (! is_string($encryptedBlob)) {
            throw new \RuntimeException('Failed to read encrypted employee document blob');
        }

        $decoded = json_decode($encryptedBlob, true);
        if (! is_array($decoded) || ! isset($decoded['ciphertext'], $decoded['nonce'])) {
            throw new \RuntimeException('Invalid encrypted employee document blob');
        }

        if (! is_string($decoded['ciphertext']) || ! is_string($decoded['nonce'])) {
            throw new \RuntimeException('Invalid encrypted employee document data types');
        }

        $employee = $document->employee;
        if ($employee === null) {
            throw new \RuntimeException('Employee document is missing its employee relation');
        }

        $tenant = $employee->tenant;
        if ($tenant === null) {
            throw new \RuntimeException('Employee document employee must belong to a tenant');
        }

        $ciphertext = base64_decode($decoded['ciphertext'], true);
        $nonce = base64_decode($decoded['nonce'], true);
        if ($ciphertext === false || $nonce === false) {
            throw new \RuntimeException('Employee document blob is not valid base64 data');
        }

        return $tenant->decrypt($ciphertext, $nonce);
    }

    /**
     * Delete the stored employee document binary.
     */
    public function delete(EmployeeDocument $document): void
    {
        $storage = Storage::disk('local');

        if ($storage->exists($document->file_path)) {
            $storage->delete($document->file_path);
        }
    }
}
