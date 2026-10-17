<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands;

use App\Models\EmployeeDocument;
use App\Models\OnboardingSubmissionFile;
use App\Services\PrivateObjectService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Explicit exact-record migration; never called by HTTP, startup or a fallback. */
class ImportPrivateObject extends Command
{
    protected $signature = 'private-objects:import {purpose} {id}';

    protected $description = 'Import one owned legacy encrypted file without deleting its source';

    public function handle(PrivateObjectService $objects): int
    {
        $purpose = $this->argument('purpose');
        $id = $this->argument('id');
        if (! in_array($purpose, ['employee-document', 'onboarding-file'], true)
            || ! is_string($id) || ! Str::isUuid($id)) {
            $this->error('An exact supported purpose and business UUID are required.');

            return self::FAILURE;
        }
        try {
            $model = $purpose === 'employee-document' ? EmployeeDocument::class : OnboardingSubmissionFile::class;
            $column = $purpose === 'employee-document' ? 'employee_document_id' : 'onboarding_submission_file_id';
            $record = $model::withTrashed()->findOrFail($id);
            if (DB::table('private_object_references')->where($column, $id)->exists()) {
                $this->info('The business record already has Object authority.');

                return self::SUCCESS;
            }
            $tenant = $record instanceof EmployeeDocument ? $record->employee?->tenant : $record->submission?->employee?->tenant;
            if ($tenant === null || ! str_starts_with($record->file_path, 'employees/')) {
                throw new \RuntimeException('Legacy ownership is unavailable');
            }
            $oldPath = $record->file_path;
            $blob = Storage::disk('local')->get($oldPath);
            if (! is_string($blob)) {
                throw new \RuntimeException('Legacy ciphertext is unavailable');
            }
            $object = $objects->stageEncrypted($tenant, $purpose, $blob);
            DB::transaction(function () use ($model, $id, $column, $oldPath, $object, $tenant, $purpose, $objects): void {
                $current = $model::withTrashed()->whereKey($id)->lockForUpdate()->firstOrFail();
                if ($current->file_path !== $oldPath
                    || DB::table('private_object_references')->where($column, $id)->exists()) {
                    throw new \RuntimeException('Legacy reference changed during import');
                }
                $current->update(['file_path' => $object['object_key']]);
                $objects->publish($object['id'], $tenant, $purpose, $id);
                if ($current->trashed()) {
                    $objects->tombstone($tenant, $purpose, $id);
                }
            });
        } catch (\Throwable) {
            // No provider errors, ciphertext, key material or SQL bindings in CLI output.
            $this->error('Private Object import failed; retained source and possible PENDING Object require read-back.');

            return self::FAILURE;
        }
        $this->info('Private Object publication committed. Legacy encrypted source retained.');

        return self::SUCCESS;
    }
}
