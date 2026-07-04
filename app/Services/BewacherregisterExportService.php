<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BewacherregisterExportNotReadyException;
use App\Models\Employee;
use App\Models\EmployeeAddress;
use App\Support\AddressHistoryLookback;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class BewacherregisterExportService
{
    /**
     * @return array{disk: string, path: string, file_name: string, file_size_bytes: int}
     */
    public function exportCsv(Employee $employee, string $exportedBy): array
    {
        $this->assertReadyForExport($employee);

        $fileName = sprintf(
            'bwr_export_%s_%s.csv',
            str_replace('/', '-', $employee->employee_number),
            now()->format('Ymd_His')
        );
        $path = $this->pathFor($employee, $fileName);

        $content = $this->generateCsv($this->buildExportData($employee, $exportedBy));

        Storage::disk('local')->put($path, $content);

        return [
            'disk' => 'local',
            'path' => $path,
            'file_name' => $fileName,
            'file_size_bytes' => strlen($content),
        ];
    }

    /**
     * @return array{disk: string, path: string, file_name: string, file_size_bytes: int}
     */
    public function exportXml(Employee $employee, string $exportedBy): array
    {
        $this->assertReadyForExport($employee);

        $fileName = sprintf(
            'bwr_export_%s_%s.xml',
            str_replace('/', '-', $employee->employee_number),
            now()->format('Ymd_His')
        );
        $path = $this->pathFor($employee, $fileName);

        $content = $this->generateXml($this->buildExportData($employee, $exportedBy));

        Storage::disk('local')->put($path, $content);

        return [
            'disk' => 'local',
            'path' => $path,
            'file_name' => $fileName,
            'file_size_bytes' => strlen($content),
        ];
    }

    public function downloadPath(Employee $employee, string $fileName): string
    {
        return $this->pathFor($employee, $this->sanitizeFileName($fileName));
    }

    private function assertReadyForExport(Employee $employee): void
    {
        $employee->loadMissing('addresses');

        $errorCodes = [];

        foreach ($this->requiredFieldValues($employee) as $field => $value) {
            if ($this->isMissing($value)) {
                $errorCodes[] = $field;
            }
        }

        if ($employee->id_document_expiry !== null && $employee->id_document_expiry->lt(today())) {
            $errorCodes[] = 'id_document_expiry_expired';
        }

        if ($employee->requiresWorkPermit() && ! $employee->hasValidWorkAuthorization()) {
            $errorCodes[] = 'valid_work_authorization';
        }

        $this->validateAddressHistoryForExport($employee, $errorCodes);

        if ($errorCodes !== []) {
            throw new BewacherregisterExportNotReadyException($errorCodes);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requiredFieldValues(Employee $employee): array
    {
        return [
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'date_of_birth' => $employee->date_of_birth,
            'gender' => $employee->gender,
            'birth_city' => $employee->birth_city,
            'birth_country' => $employee->birth_country,
            'nationalities' => $employee->nationalities,
            'intended_activities' => $employee->intended_activities,
            'id_document_type' => $employee->id_document_type,
            'id_document_number' => $employee->id_document_number,
            'id_document_expiry' => $employee->id_document_expiry,
            'sachkunde_type' => $employee->sachkunde_type,
            'sachkunde_certificate' => $employee->sachkunde_certificate,
        ];
    }

    /**
     * @param  list<string>  $errorCodes
     */
    private function validateAddressHistoryForExport(Employee $employee, array &$errorCodes): void
    {
        /** @var Collection<int, EmployeeAddress> $addresses */
        $addresses = $employee->addresses;

        $current = $addresses->filter(fn (EmployeeAddress $a): bool => $a->resided_until === null);
        if ($current->count() === 0) {
            $errorCodes[] = 'current_address_missing';

            return;
        }

        if ($current->count() > 1) {
            $errorCodes[] = 'current_address_ambiguous';

            return;
        }

        /** @var EmployeeAddress $currentRow */
        $currentRow = $current->firstOrFail();

        foreach (
            [
                'street' => 'address_street',
                'house_number' => 'address_house_number',
                'postal_code' => 'address_postal_code',
                'city' => 'address_city',
                'country' => 'address_country',
            ] as $attr => $messageKey
        ) {
            if ($this->isMissing($currentRow->getAttribute($attr))) {
                $errorCodes[] = $messageKey;
            }
        }

        $past = $addresses->filter(fn (EmployeeAddress $a): bool => $a->resided_until !== null);
        if ($currentRow->resided_from === null && $past->isNotEmpty()) {
            $errorCodes[] = 'current_address_resided_from_required';

            return;
        }

        foreach ($past as $row) {
            if ($row->resided_from === null || $row->resided_until === null) {
                $errorCodes[] = 'address_history_incomplete';

                return;
            }
        }

        $windowStart = now()->startOfDay()->copy()->subYears(AddressHistoryLookback::YEARS);
        $windowEnd = now()->startOfDay();

        /** @var array<int, array{start: Carbon, end: Carbon}> $segments */
        $segments = [];

        foreach ($addresses as $addr) {
            if ($addr->resided_until === null) {
                $from = $addr->resided_from?->copy()->startOfDay() ?? $windowStart->copy();
                $segments[] = [
                    'start' => $from,
                    'end' => $windowEnd->copy(),
                ];
            } else {
                $segments[] = [
                    'start' => $addr->resided_from?->copy()->startOfDay() ?? $windowStart->copy(),
                    'end' => $addr->resided_until->copy()->startOfDay(),
                ];
            }
        }

        usort($segments, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        // Discard segments that end entirely before the window starts; they do not
        // contribute to window coverage and would produce false-positive gaps.
        $segments = array_values(
            array_filter($segments, fn (array $seg): bool => $seg['end']->gte($windowStart)),
        );

        $mergedEnd = null;
        foreach ($segments as $seg) {
            if ($mergedEnd === null) {
                if ($seg['start']->gt($windowStart)) {
                    $errorCodes[] = 'address_history_incomplete';

                    return;
                }
                $mergedEnd = $seg['end']->copy();

                continue;
            }

            if ($seg['start']->lte($mergedEnd)) {
                $errorCodes[] = 'address_history_overlap';

                return;
            }

            if ($seg['start']->gt($mergedEnd->copy()->addDay())) {
                $errorCodes[] = 'address_history_gap';

                return;
            }

            if ($seg['end']->gt($mergedEnd)) {
                $mergedEnd = $seg['end']->copy();
            }
        }

        if ($mergedEnd === null || $mergedEnd->lt($windowEnd)) {
            $errorCodes[] = 'address_history_incomplete';
        }
    }

    private function isMissing(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        if ($value instanceof \BackedEnum) {
            return false;
        }

        if ($value instanceof \DateTimeInterface) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        return $value === null;
    }

    /**
     * @return array<string, string>
     */
    private function buildExportData(Employee $employee, string $exportedBy): array
    {
        $employee->loadMissing('addresses');

        $appName = config('app.name');
        $employerName = is_string($appName) && $appName !== '' ? $appName : 'SecPal';

        $current = $employee->addresses->first(fn (EmployeeAddress $a): bool => $a->resided_until === null) ?: null;

        return [
            'last_name' => $employee->last_name,
            'first_name' => $employee->first_name,
            'birth_name' => $employee->birth_name ?? '',
            'previous_names' => implode(', ', $employee->previous_names ?? []),
            'gender' => (string) $employee->gender,
            'date_of_birth' => (string) $employee->date_of_birth,
            'birth_city' => (string) $employee->birth_city,
            'birth_country' => (string) $employee->birth_country,
            'nationalities' => implode(', ', $employee->nationalities ?? []),
            'address_street' => $current !== null ? (string) ($current->street ?? '') : '',
            'address_house_number' => $current !== null ? (string) ($current->house_number ?? '') : '',
            'address_postal_code' => $current !== null ? (string) ($current->postal_code ?? '') : '',
            'address_city' => $current !== null ? (string) ($current->city ?? '') : '',
            'address_country' => $current !== null ? (string) ($current->country ?? '') : '',
            'address_history' => $this->formatAddressHistoryFromRelation($employee),
            'intended_activities' => implode(', ', $employee->intended_activities ?? []),
            'sachkunde_type' => (string) $employee->sachkunde_type,
            'sachkunde_certificate' => (string) $employee->sachkunde_certificate,
            'bwr_id' => (string) ($employee->bwr_id ?? ''),
            'id_document_type' => (string) $employee->id_document_type,
            'id_document_number' => (string) $employee->id_document_number,
            'id_document_expiry' => $employee->id_document_expiry?->toDateString() ?? '',
            'employer_name' => $employerName,
            'export_date' => now()->toDateString(),
            'exported_by' => $exportedBy,
        ];
    }

    private function formatAddressHistoryFromRelation(Employee $employee): string
    {
        return $employee->addresses
            ->filter(fn (EmployeeAddress $a): bool => $a->resided_until !== null)
            ->sortBy(fn (EmployeeAddress $a) => $a->resided_from?->toDateString() ?? '')
            ->map(fn (EmployeeAddress $address): string => sprintf(
                '%s - %s: %s %s, %s %s, %s',
                $address->resided_from?->toDateString() ?? '',
                $address->resided_until?->toDateString() ?? '',
                $address->street ?? '',
                $address->house_number ?? '',
                $address->postal_code ?? '',
                $address->city ?? '',
                $address->country ?? '',
            ))
            ->implode("\n");
    }

    /**
     * @param  array<string, string>  $data
     */
    private function generateCsv(array $data): string
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new \RuntimeException('Failed to open temporary CSV stream.');
        }

        fputcsv($stream, array_keys($data), ';');
        fputcsv($stream, array_values($data), ';');

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if (! is_string($csv)) {
            throw new \RuntimeException('Failed to generate CSV export content.');
        }

        return $csv;
    }

    /**
     * @param  array<string, string>  $data
     */
    private function generateXml(array $data): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><bewacherregisterExport/>');

        foreach ($data as $key => $value) {
            $xml->addChild($key, $value);
        }

        $renderedXml = $xml->asXML();

        if (! is_string($renderedXml)) {
            throw new \RuntimeException('Failed to generate XML export content.');
        }

        return $renderedXml;
    }

    private function pathFor(Employee $employee, string $fileName): string
    {
        return 'bwr_exports/'.$employee->id.'/'.$fileName;
    }

    private function sanitizeFileName(string $fileName): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '_', $fileName);

        return is_string($sanitized) && $sanitized !== '' ? $sanitized : 'bwr_export.csv';
    }
}
