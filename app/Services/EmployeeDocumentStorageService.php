<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Exceptions\EmployeeDocumentFileNotFoundException;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Stores employee document binaries encrypted at rest.
 */
class EmployeeDocumentStorageService
{
    public function __construct(private readonly PrivateObjectService $objects) {}

    /**
     * Store an uploaded employee document in encrypted form.
     *
     * @return array{file_path: string, file_name: string, mime_type: string, file_size: int, private_object_id: string}
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

        $object = $this->objects->stage($tenant, 'employee-document', $content);

        return [
            'file_path' => $object['object_key'],
            'private_object_id' => $object['id'],
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
        if (! DB::table('private_object_references')->where('employee_document_id', $document->id)->exists()) {
            throw new EmployeeDocumentFileNotFoundException('Employee document not found in storage');
        }
        $tenant = $document->employee?->tenant;
        if ($tenant === null) {
            throw new \RuntimeException('Employee document tenant is unavailable');
        }

        return $this->objects->read($tenant, 'employee-document', $document->id);
    }

    /** @param array{private_object_id: string} $stored */
    public function publish(array $stored, EmployeeDocument $document): void
    {
        $tenant = $document->employee?->tenant;
        if ($tenant === null) {
            throw new \RuntimeException('Employee document tenant is unavailable');
        }
        $this->objects->publish($stored['private_object_id'], $tenant, 'employee-document', $document->id);
    }

    public function delete(EmployeeDocument $document): void
    {
        $tenant = $document->employee?->tenant;
        if ($tenant === null) {
            throw new \RuntimeException('Employee document tenant is unavailable');
        }
        $this->objects->tombstone($tenant, 'employee-document', $document->id);
    }
}
