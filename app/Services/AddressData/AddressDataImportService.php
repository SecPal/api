<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services\AddressData;

use App\Models\AddressDataImport;
use App\Models\AddressStreet;
use App\Support\AddressDataConfig;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AddressDataImportService
{
    public function __construct(
        private AddressDataDownloader $downloader,
        private AddressStreetCsvImporter $csvImporter,
        private AddressSuggestionService $suggestionService,
    ) {}

    /**
     * @param  (callable(string): void)|null  $onProgress
     * @return array{status: string, message: string, import_id?: int}
     */
    public function run(
        bool $force,
        bool $dryRun,
        ?string $sourcePath,
        bool $ifEmpty,
        bool $setupOnly,
        int $keepImports,
        ?callable $onProgress = null,
    ): array {
        $emit = static function (string $message) use ($onProgress): void {
            if ($onProgress !== null) {
                $onProgress($message);
            }
        };

        $countryCode = AddressDataConfig::string('address_data.country', 'DE');

        if ($setupOnly && ! config('address_data.import_on_setup')) {
            $emit('Skipped: ADDRESS_DATA_IMPORT_ON_SETUP is disabled.');

            return ['status' => 'skipped', 'message' => 'ADDRESS_DATA_IMPORT_ON_SETUP is disabled.'];
        }

        if ($ifEmpty && $this->hasActivatedImport($countryCode)) {
            $emit('Skipped: an activated import already exists (--if-empty).');

            return ['status' => 'skipped', 'message' => 'An activated address import already exists.'];
        }

        $sourceUrl = AddressDataConfig::string(
            'address_data.source_url',
            'https://github.com/openpotato/openplzapi.data/raw/refs/heads/main/src/de/osm/streets.updated.csv',
        );

        $emit('Resolving address data source (download or local file)…');

        try {
            $downloaded = $this->downloader->download($sourceUrl, $sourcePath, $onProgress);
        } catch (Throwable $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }

        $path = $downloaded['path'];
        $sha256 = $downloaded['sha256'];

        $active = $this->latestActivatedImport($countryCode);
        if (! $force && $active !== null && $active->source_sha256 !== null && hash_equals($active->source_sha256, $sha256)) {
            $this->cleanupTempDownload($path, $sourcePath);
            $emit('Skipped: source unchanged (SHA-256 matches active import). Use --force to re-import.');

            return ['status' => 'skipped', 'message' => 'Source unchanged (SHA-256 matches active import).'];
        }

        if ($dryRun) {
            $dummy = new AddressDataImport;
            $dummy->id = 0;
            try {
                $rows = $this->csvImporter->importFile($path, $dummy, true, $onProgress);
            } catch (Throwable $e) {
                $this->cleanupTempDownload($path, $sourcePath);

                return ['status' => 'failed', 'message' => $e->getMessage()];
            }
            $this->cleanupTempDownload($path, $sourcePath);

            return ['status' => 'dry_run', 'message' => "Validated CSV; {$rows} rows would be imported."];
        }

        $license = AddressDataConfig::string('address_data.license_spdx', 'ODbL-1.0');
        $attribution = AddressDataConfig::string('address_data.attribution', '');

        $emit('Creating import run record…');

        $import = AddressDataImport::query()->create([
            'country_code' => $countryCode,
            'source_name' => AddressDataConfig::string('address_data.source_name', 'OpenPLZ API Data'),
            'source_url' => $sourceUrl,
            'status' => AddressDataImport::STATUS_RUNNING,
            'started_at' => now(),
            'license' => $license,
            'attribution' => $attribution,
        ]);

        try {
            $rowCount = $this->csvImporter->importFile($path, $import, false, $onProgress);
        } catch (Throwable $e) {
            $import->update([
                'status' => AddressDataImport::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
            AddressStreet::query()->where('import_id', $import->id)->delete();
            $this->cleanupTempDownload($path, $sourcePath);

            return ['status' => 'failed', 'message' => $e->getMessage(), 'import_id' => $import->id];
        }

        $emit('Activating import and pruning superseded datasets…');

        try {
            DB::transaction(function () use (
                $import,
                $rowCount,
                $sha256,
                $downloaded,
                $countryCode,
                $keepImports,
            ): void {
                AddressDataImport::query()
                    ->where('country_code', $countryCode)
                    ->whereNotNull('activated_at')
                    ->update(['activated_at' => null]);

                $import->refresh();

                $import->update([
                    'status' => AddressDataImport::STATUS_SUCCEEDED,
                    'finished_at' => now(),
                    'activated_at' => now(),
                    'row_count' => $rowCount,
                    'source_sha256' => $sha256,
                    'source_etag' => $downloaded['etag'],
                    'source_last_modified' => $downloaded['last_modified'],
                    'error_message' => null,
                ]);

                $this->pruneStreetRows($countryCode, $keepImports);
            });
        } catch (Throwable $e) {
            $import->update([
                'status' => AddressDataImport::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
            AddressStreet::query()->where('import_id', $import->id)->delete();

            $this->cleanupTempDownload($path, $sourcePath);

            return ['status' => 'failed', 'message' => $e->getMessage(), 'import_id' => $import->id];
        }

        $this->cleanupTempDownload($path, $sourcePath);

        $this->suggestionService->forgetActiveImportCache($countryCode);

        return [
            'status' => 'succeeded',
            'message' => "Imported {$rowCount} rows.",
            'import_id' => $import->id,
        ];
    }

    private function hasActivatedImport(string $countryCode): bool
    {
        return AddressDataImport::query()
            ->where('country_code', $countryCode)
            ->whereNotNull('activated_at')
            ->exists();
    }

    private function latestActivatedImport(string $countryCode): ?AddressDataImport
    {
        /** @var AddressDataImport|null */
        return AddressDataImport::query()
            ->where('country_code', $countryCode)
            ->whereNotNull('activated_at')
            ->orderByDesc('id')
            ->first();
    }

    private function pruneStreetRows(string $countryCode, int $keepImports): void
    {
        $keepIds = AddressDataImport::query()
            ->where('country_code', $countryCode)
            ->where('status', AddressDataImport::STATUS_SUCCEEDED)
            ->orderByDesc('id')
            ->limit(max(1, 1 + $keepImports))
            ->pluck('id')
            ->all();

        $deleteImportIds = AddressDataImport::query()
            ->where('country_code', $countryCode)
            ->where('status', '!=', AddressDataImport::STATUS_RUNNING)
            ->whereNotIn('id', $keepIds)
            ->pluck('id')
            ->all();

        if ($deleteImportIds === []) {
            return;
        }

        AddressStreet::query()
            ->whereIn('import_id', $deleteImportIds)
            ->delete();

        AddressDataImport::query()
            ->whereIn('id', $deleteImportIds)
            ->delete();
    }

    private function cleanupTempDownload(string $path, ?string $sourcePath): void
    {
        if ($sourcePath !== null && $sourcePath !== '') {
            return;
        }

        if (is_file($path)
            && str_contains($path, DIRECTORY_SEPARATOR.'address-data'.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR)) {
            @unlink($path);
        }
    }
}
