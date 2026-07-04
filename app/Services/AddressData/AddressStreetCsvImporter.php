<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Services\AddressData;

use App\Models\AddressDataImport;
use App\Support\AddressDataConfig;
use App\Support\AddressSearchNormalizer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class AddressStreetCsvImporter
{
    /**
     * @var list<string>
     */
    public const EXPECTED_HEADER = [
        'Name',
        'PostalCode',
        'Locality',
        'RegionalKey',
        'Borough',
        'Suburb',
    ];

    /**
     * @param  (callable(string): void)|null  $onProgress
     * @return int Number of data rows imported (excluding header)
     */
    public function importFile(string $path, AddressDataImport $import, bool $dryRun = false, ?callable $onProgress = null): int
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open CSV for reading: {$path}");
        }

        try {
            $headerLine = fgets($handle);
            if ($headerLine === false) {
                throw new InvalidArgumentException('Address CSV is empty.');
            }

            $headerLine = preg_replace('/^\xEF\xBB\xBF/', '', $headerLine) ?? $headerLine;
            $header = str_getcsv(trim($headerLine), separator: ',', enclosure: '"', escape: '');
            if ($header !== self::EXPECTED_HEADER) {
                throw new InvalidArgumentException(
                    'Unexpected CSV header. Expected: '.implode(',', self::EXPECTED_HEADER),
                );
            }

            $emit = static function (string $message) use ($onProgress): void {
                if ($onProgress !== null) {
                    $onProgress($message);
                }
            };

            $chunkTarget = AddressDataConfig::int('address_data.chunk_rows', 2000);
            $countryCode = AddressDataConfig::string('address_data.country', 'DE');
            $now = now()->toDateTimeString();
            $expectedColumnCount = count(self::EXPECTED_HEADER);

            $buffer = [];
            $total = 0;
            $rowNumber = 0;
            $nextProgressMilestone = 50_000;
            $reportedStart = false;

            $emit(
                $dryRun
                    ? 'Validating CSV rows (dry-run, no database writes)…'
                    : 'Reading CSV and inserting rows (chunk size: '.$chunkTarget.')…',
            );

            $reportAfterChunk = function (int $added) use (
                &$total,
                &$nextProgressMilestone,
                &$reportedStart,
                $dryRun,
                $emit,
            ): void {
                $total += $added;
                if (! $reportedStart && $total > 0) {
                    $emit(
                        $dryRun
                            ? 'First '.$total.' data rows validated…'
                            : 'Database writes started, '.$total.' rows so far…',
                    );
                    $reportedStart = true;
                }
                while ($total >= $nextProgressMilestone) {
                    $emit(
                        $dryRun
                            ? 'Validated '.$total.'+ data rows…'
                            : 'Inserted '.$total.'+ rows…',
                    );
                    $nextProgressMilestone += 50_000;
                }
            };

            while (($row = fgetcsv($handle, length: 0, separator: ',', enclosure: '"', escape: '')) !== false) {
                if ($this->isBlankRow($row)) {
                    continue;
                }

                $rowNumber++;

                if (count($row) !== $expectedColumnCount) {
                    $got = count($row);
                    $preview = $this->csvRowPreviewForError($row);
                    throw new InvalidArgumentException(
                        "CSV data row {$rowNumber} has wrong column count (expected {$expectedColumnCount}, got {$got}). {$preview}",
                    );
                }

                $name = (string) ($row[0] ?? '');
                $postalCodeRaw = (string) ($row[1] ?? '');
                $locality = (string) ($row[2] ?? '');
                $regionalKey = (string) ($row[3] ?? '');
                $borough = (string) ($row[4] ?? '');
                $suburb = (string) ($row[5] ?? '');

                $postalCode = $this->normalizePostalCode($postalCodeRaw);
                $nameSearch = AddressSearchNormalizer::normalize($name);
                $nameSearchAscii = AddressSearchNormalizer::normalizeAsciiFallback($name);
                $localitySearch = AddressSearchNormalizer::normalize($locality);
                $localitySearchAscii = AddressSearchNormalizer::normalizeAsciiFallback($locality);

                $buffer[] = [
                    'import_id' => $import->id,
                    'country_code' => $countryCode,
                    'name' => $name,
                    'postal_code' => $postalCode,
                    'locality' => $locality,
                    'regional_key' => $regionalKey !== '' ? $regionalKey : null,
                    'borough' => $borough !== '' ? $borough : null,
                    'suburb' => $suburb !== '' ? $suburb : null,
                    'name_search' => $nameSearch,
                    'name_search_ascii' => $nameSearchAscii,
                    'locality_search' => $localitySearch,
                    'locality_search_ascii' => $localitySearchAscii,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($buffer) >= $chunkTarget) {
                    $added = count($buffer);
                    if (! $dryRun) {
                        DB::table('address_streets')->insert($buffer);
                    }
                    $reportAfterChunk($added);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                $added = count($buffer);
                if (! $dryRun) {
                    DB::table('address_streets')->insert($buffer);
                }
                $reportAfterChunk($added);
            }

            $emit(
                'Finished processing CSV: '.$total.' data row'.($total === 1 ? '' : 's').($dryRun ? ' (dry-run).' : '.'),
            );

            return $total;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, string|null>  $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizePostalCode(string $postalCode): string
    {
        $trimmed = trim($postalCode);

        return preg_replace('/\s+/u', '', $trimmed) ?? $trimmed;
    }

    /**
     * @param  array<int, string|null>  $row
     */
    private function csvRowPreviewForError(array $row): string
    {
        $cells = [];
        foreach ($row as $cell) {
            $value = str_replace(["\r", "\n"], ' ', (string) $cell);
            $cells[] = str_contains($value, ',') ? '"'.str_replace('"', '""', $value).'"' : $value;
        }

        $joined = implode(',', $cells);
        $max = 200;
        if (mb_strlen($joined) > $max) {
            $joined = mb_substr($joined, 0, $max).'…';
        }

        return 'Row preview: '.$joined;
    }
}
