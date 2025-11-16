<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSecretAttachmentRequest;
use App\Http\Resources\SecretAttachmentResource;
use App\Models\Secret;
use App\Models\SecretAttachment;
use App\Models\User;
use App\Services\AttachmentStorageService;
use Illuminate\Http\JsonResponse;
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
     */
    public function store(StoreSecretAttachmentRequest $request, Secret $secret): JsonResponse
    {
        Gate::authorize('create', [SecretAttachment::class, $secret]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->validated()['file'];

        $user = $request->user();
        assert($user instanceof User, 'User must be authenticated');

        $attachment = $this->storageService->store($file, $secret, $user);

        return SecretAttachmentResource::make($attachment)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * List attachments for secret.
     */
    public function index(Secret $secret): JsonResponse
    {
        Gate::authorize('viewAny', [SecretAttachment::class, $secret]);

        $attachments = $secret->attachments()->latest()->get();

        return response()->json([
            'data' => SecretAttachmentResource::collection($attachments),
        ]);
    }

    /**
     * Download attachment.
     */
    public function download(SecretAttachment $attachment): Response
    {
        Gate::authorize('view', $attachment);

        $content = $this->storageService->retrieve($attachment);
        $filename = $attachment->filename_plain;

        // Escape filename for Content-Disposition header (RFC 2231/5987)
        $safeFilename = str_replace(['"', '\\'], ['', ''], $filename ?? 'download');

        return response($content, 200, [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'attachment; filename="'.$safeFilename.'"',
            'Content-Length' => $attachment->file_size,
        ]);
    }

    /**
     * Delete attachment.
     */
    public function destroy(SecretAttachment $attachment): Response
    {
        Gate::authorize('delete', $attachment);

        $this->storageService->delete($attachment);

        return response()->noContent();
    }
}
