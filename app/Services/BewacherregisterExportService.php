<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BewacherregisterExportNotReadyException;
use App\Models\Employee;
use Illuminate\Support\Facades\Storage;

class BewacherregisterExportService
{
    /**
     * @return array{disk: string, path: string, file_name: string}
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

        Storage::disk('local')->put($path, $this->generateCsv($this->buildExportData($employee, $exportedBy)));

        return [
            'disk' => 'local',
            'path' => $path,
            'file_name' => $fileName,
        ];
    }

    /**
     * @return array{disk: string, path: string, file_name: string}
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

        Storage::disk('local')->put($path, $this->generateXml($this->buildExportData($employee, $exportedBy)));

        return [
            'disk' => 'local',
            'path' => $path,
            'file_name' => $fileName,
        ];
    }

    public function downloadPath(Employee $employee, string $fileName): string
    {
        return $this->pathFor($employee, $this->sanitizeFileName($fileName));
    }

    private function assertReadyForExport(Employee $employee): void
    {
        $errors = [];

        foreach ($this->requiredFieldValues($employee) as $field => $value) {
            if ($this->isMissing($value)) {
                $errors[] = $field;
            }
        }

        if ($employee->id_document_expiry !== null && $employee->id_document_expiry->lt(today())) {
            $errors[] = 'id_document_expiry_expired';
        }

        if ($employee->requiresWorkPermit() && ! $employee->hasValidWorkAuthorization()) {
            $errors[] = 'valid_work_authorization';
        }

        if ($errors !== []) {
            throw new BewacherregisterExportNotReadyException($errors);
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
            'address_street' => $employee->address_street,
            'address_house_number' => $employee->address_house_number,
            'address_postal_code' => $employee->address_postal_code,
            'address_city' => $employee->address_city,
            'address_country' => $employee->address_country,
            'address_history' => $employee->address_history,
            'intended_activities' => $employee->intended_activities,
            'id_document_type' => $employee->id_document_type,
            'id_document_number' => $employee->id_document_number,
            'id_document_expiry' => $employee->id_document_expiry,
            'sachkunde_type' => $employee->sachkunde_type,
            'sachkunde_certificate' => $employee->sachkunde_certificate,
        ];
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
        $appName = config('app.name');
        $employerName = is_string($appName) && $appName !== '' ? $appName : 'SecPal';

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
            'address_street' => (string) $employee->address_street,
            'address_house_number' => (string) $employee->address_house_number,
            'address_postal_code' => (string) $employee->address_postal_code,
            'address_city' => (string) $employee->address_city,
            'address_country' => (string) $employee->address_country,
            'address_history' => $this->formatAddressHistory($employee->address_history ?? []),
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

    /**
     * @param  array<int, array{from?: string, to?: string, street?: string, house_number?: string, postal_code?: string, city?: string, country?: string, state?: string|null}>  $history
     */
    private function formatAddressHistory(array $history): string
    {
        return collect($history)
            ->map(fn (array $address): string => sprintf(
                '%s - %s: %s %s, %s %s, %s',
                $address['from'] ?? '',
                $address['to'] ?? '',
                $address['street'] ?? '',
                $address['house_number'] ?? '',
                $address['postal_code'] ?? '',
                $address['city'] ?? '',
                $address['country'] ?? '',
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
            $xml->addChild($key, htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8'));
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
