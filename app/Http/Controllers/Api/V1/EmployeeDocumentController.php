<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadEmployeeDocumentRequest;
use App\Http\Resources\EmployeeDocumentResource;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * EmployeeDocumentController handles employee document management.
 *
 * Manages document uploads, downloads, and visibility control.
 * All operations are protected by EmployeeDocumentPolicy.
 */
class EmployeeDocumentController extends Controller
{
    /**
     * Display a listing of an employee's documents.
     *
     * GET /api/v1/employees/{employee}/documents
     *
     * Visibility is filtered based on policy (employees see only visible_to_employee = true).
     */
    public function index(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('viewAny', [EmployeeDocument::class, $employee]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        // Check organizational scope access for scoped users
        $hasScopes = $user->organizationalScopes()->exists();

        if ($hasScopes && $employee->organizationalUnit !== null) {
            if (! $user->hasAccessToUnit($employee->organizationalUnit)) {
                abort(Response::HTTP_FORBIDDEN, 'You do not have access to this employee\'s organizational unit');
            }
        }

        $query = $employee->documents();

        // Filter by visible_to_employee if user is the employee themselves
        if ($user->id === $employee->user_id) {
            $query->where('visible_to_employee', true);
        }

        $documents = $query->with('uploader')->get();

        return response()->json([
            'data' => EmployeeDocumentResource::collection($documents),
        ]);
    }

    /**
     * Upload a new document for an employee.
     *
     * POST /api/v1/employees/{employee}/documents
     *
     * Stores file in storage/app/employees/{employee_id}/documents/
     */
    public function store(UploadEmployeeDocumentRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('create', [EmployeeDocument::class, $employee]);

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('file');

        // Generate safe filename
        $filename = time().'_'.$file->getClientOriginalName();
        $path = "employees/{$employee->id}/documents/{$filename}";

        // Store file
        Storage::disk('local')->put($path, $file->getContent());

        /** @var \App\Models\User $user */
        $user = $request->user();

        $document = EmployeeDocument::create([
            'employee_id' => $employee->id,
            'uploaded_by' => $user->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'document_type' => $validated['document_type'],
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'file_size' => $file->getSize(),
            'expiry_date' => $validated['expiry_date'] ?? null,
            'status' => 'valid',
            'visible_to_employee' => $validated['visible_to_employee'],
        ]);

        $document->load('uploader');

        return response()->json([
            'data' => new EmployeeDocumentResource($document),
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified document.
     *
     * GET /api/v1/employees/{employee}/documents/{document}
     */
    public function show(Employee $employee, EmployeeDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        $document->load('uploader');

        return response()->json([
            'data' => new EmployeeDocumentResource($document),
        ]);
    }

    /**
     * Download the specified document file.
     *
     * GET /api/v1/employees/{employee}/documents/{document}/download
     *
     * Returns file stream with appropriate headers.
     */
    public function download(Employee $employee, EmployeeDocument $document): Response
    {
        $this->authorize('view', $document);

        if (! Storage::disk('local')->exists($document->file_path)) {
            abort(Response::HTTP_NOT_FOUND, __('File not found'));
        }

        $fileContent = Storage::disk('local')->get($document->file_path);

        return response($fileContent)
            ->header('Content-Type', $document->mime_type)
            ->header('Content-Disposition', 'attachment; filename="'.$document->file_name.'"');
    }

    /**
     * Remove the specified document.
     *
     * DELETE /api/v1/employees/{employee}/documents/{document}
     *
     * Deletes file from storage and database record (soft delete).
     */
    public function destroy(Employee $employee, EmployeeDocument $document): JsonResponse
    {
        $this->authorize('delete', $document);

        // Delete file from storage
        if (Storage::disk('local')->exists($document->file_path)) {
            Storage::disk('local')->delete($document->file_path);
        }

        // Soft delete database record
        $document->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
