<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models\Employment;

use App\Casts\EncryptedWithDek;
use App\Exceptions\CorruptedEncryptedAttributeException;
use App\Models\TenantEncryptedModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use JsonException;
use SensitiveParameter;

/**
 * @property string $id
 * @property int $tenant_id
 * @property string $employee_id
 * @property string $employment_period_id
 * @property string $evidence_id
 * @property string|null $replaces_id
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable $recorded_at
 */
final class EmploymentTermsVersion extends TenantEncryptedModel
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'employment_terms_versions';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'employee_id', 'employment_period_id', 'evidence_id',
        'replaces_id', 'effective_from',
    ];

    /** @var list<string> */
    protected $hidden = ['conditions_enc'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'conditions_enc' => EncryptedWithDek::class,
        ];
    }

    /** @param array<string, mixed> $conditions */
    public function setConditionsPlain(#[SensitiveParameter] array $conditions): void
    {
        try {
            $payload = json_encode($conditions, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException) {
            throw new CorruptedEncryptedAttributeException('Employment conditions could not be encoded.');
        }

        try {
            $this->attributes['conditions_enc'] = app(EncryptedWithDek::class)
                ->set($this, 'conditions_enc', $payload, $this->attributes);
        } finally {
            sodium_memzero($payload);
        }
    }

    /** @return array<string, mixed> */
    public function conditions(): array
    {
        $value = $this->getAttributeValue('conditions_enc');
        $conditions = is_string($value) ? json_decode($value, true) : null;
        if (! is_array($conditions) || $conditions === []) {
            throw new CorruptedEncryptedAttributeException('Employment terms could not be decoded.');
        }

        $fields = [];
        foreach ($conditions as $field => $value) {
            if (! is_string($field)) {
                throw new CorruptedEncryptedAttributeException('Employment terms contain an invalid field.');
            }
            $fields[$field] = $value;
        }

        return $fields;
    }
}
