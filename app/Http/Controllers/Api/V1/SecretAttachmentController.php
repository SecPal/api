<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Secret;
use App\Models\SecretAttachment;
use App\Services\AttachmentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Secret attachment API controller.
 *
 * Handles file upload, listing, download, and deletion for secret attachments.
 * All files are encrypted at rest using tenant DEK.
 */
class SecretAttachmentController extends Controller
{
    /**
     * Create controller instance.
     */
    public function __construct(
        private readonly AttachmentStorageService $storageService
    ) {}

    /**
     * Upload attachment to secret.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Secret  $secret
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request, Secret $secret): JsonResponse
    {
        Gate::authorize('create', [SecretAttachment::class, $secret]);

        $maxSize = config('attachments.max_file_size');
        $allowedMimes = config('attachments.allowed_mime_types');

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.(($maxSize / 1024)), // Laravel expects KB
                'mimes:'.implode(',', array_map(fn ($mime) => $this->mimeToExtension($mime), $allowedMimes)),
            ],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        $attachment = $this->storageService->store($file, $secret, $request->user());

        return response()->json([
            'data' => [
                'id' => $attachment->id,
                'filename' => $attachment->getFilenamePlainAttribute(),
                'file_size' => $attachment->file_size,
                'mime_type' => $attachment->mime_type,
                'download_url' => $attachment->download_url,
                'uploaded_by' => $attachment->uploaded_by,
                'created_at' => $attachment->created_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * List attachments for secret.
     *
     * @param  \App\Models\Secret  $secret
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Secret $secret): JsonResponse
    {
        Gate::authorize('viewAny', [SecretAttachment::class, $secret]);

        $attachments = $secret->attachments()->latest()->get();

        return response()->json([
            'data' => $attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'filename' => $attachment->getFilenamePlainAttribute(),
                'file_size' => $attachment->file_size,
                'mime_type' => $attachment->mime_type,
                'download_url' => $attachment->download_url,
                'created_at' => $attachment->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Download attachment.
     *
     * @param  \App\Models\SecretAttachment  $attachment
     * @return \Illuminate\Http\Response
     */
    public function download(SecretAttachment $attachment): Response
    {
        Gate::authorize('view', $attachment);

        $content = $this->storageService->retrieve($attachment);
        $filename = $attachment->getFilenamePlainAttribute();

        return response($content, 200, [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => $attachment->file_size,
        ]);
    }

    /**
     * Delete attachment.
     *
     * @param  \App\Models\SecretAttachment  $attachment
     * @return \Illuminate\Http\Response
     */
    public function destroy(SecretAttachment $attachment): Response
    {
        Gate::authorize('delete', $attachment);

        $this->storageService->delete($attachment);

        return response()->noContent();
    }

    /**
     * Map MIME type to file extension for validation.
     *
     * @param  string  $mime
     * @return string
     */
    private function mimeToExtension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg,jpeg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'text/html' => 'html',
            'text/markdown' => 'md',
            'application/zip' => 'zip',
            'application/x-7z-compressed' => '7z',
            'application/x-rar-compressed' => 'rar',
            'application/json' => 'json',
            'application/xml' => 'xml',
            default => '*',
        };
    }
}
