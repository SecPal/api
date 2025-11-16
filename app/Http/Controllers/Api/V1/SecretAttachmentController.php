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
use App\Models\User;
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

        /** @var int<1, max> $maxSize */
        $maxSize = config('attachments.max_file_size');
        /** @var array<int, string> $allowedMimes */
        $allowedMimes = config('attachments.allowed_mime_types');

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.((int) ($maxSize / 1024)), // Laravel expects KB
                'mimetypes:'.implode(',', $allowedMimes),
            ],
        ]);

        if (! isset($validated['file']) || ! ($validated['file'] instanceof \Illuminate\Http\UploadedFile)) {
            throw new \InvalidArgumentException('Valid file upload required');
        }

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        $user = $request->user();
        assert($user instanceof User, 'User must be authenticated');

        $attachment = $this->storageService->store($file, $secret, $user);

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
}
