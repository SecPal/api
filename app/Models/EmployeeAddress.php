<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A residential address for an employee (current or historical).
 *
 * @property string $id
 * @property string $employee_id
 * @property int $tenant_id
 * @property string|null $street_enc
 * @property string|null $house_number_enc
 * @property string|null $postal_code_enc
 * @property string|null $city_enc
 * @property string|null $supplement_enc
 * @property string|null $country
 * @property string|null $state
 * @property \Illuminate\Support\Carbon|null $resided_from
 * @property \Illuminate\Support\Carbon|null $resided_until
 * @property-read string|null $street
 * @property-read string|null $house_number
 * @property-read string|null $postal_code
 * @property-read string|null $city
 * @property-read string|null $supplement
 * @property-read Employee $employee
 * @property-read TenantKey $tenant
 */
class EmployeeAddress extends Model
{
    /** @use HasFactory<\Database\Factories\EmployeeAddressFactory> */
    use HasFactory, HasUuids, LogsActivity;

    /**
     * Temporary storage for GDPR changed fields (same pattern as Employee).
     *
     * @var array<int|string, string[]>
     */
    private static array $gdprChangedFields = [];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'tenant_id',
        'street',
        'street_enc',
        'house_number',
        'house_number_enc',
        'postal_code',
        'postal_code_enc',
        'city',
        'city_enc',
        'supplement',
        'supplement_enc',
        'country',
        'state',
        'resided_from',
        'resided_until',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'street_enc',
        'house_number_enc',
        'postal_code_enc',
        'city_enc',
        'supplement_enc',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'street_enc' => \App\Casts\EncryptedWithDek::class,
            'house_number_enc' => \App\Casts\EncryptedWithDek::class,
            'postal_code_enc' => \App\Casts\EncryptedWithDek::class,
            'city_enc' => \App\Casts\EncryptedWithDek::class,
            'supplement_enc' => \App\Casts\EncryptedWithDek::class,
            'resided_from' => 'date',
            'resided_until' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['employee_id', 'country', 'state', 'resided_from', 'resided_until'])
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('employee_changes');
    }

    protected function hasActuallyChanged(string $field): bool
    {
        $original = $this->getOriginal($field);
        $current = $this->getAttribute($field);

        if ($original === null && $current === null) {
            return false;
        }

        if ($original === null || $current === null) {
            return true;
        }

        return $original !== $current;
    }

    protected static function booted(): void
    {
        static::updating(function (EmployeeAddress $address) {
            $changedFields = [];
            if ($address->isDirty('street_enc') && $address->hasActuallyChanged('street')) {
                $changedFields[] = 'address_street';
            }
            if ($address->isDirty('house_number_enc') && $address->hasActuallyChanged('house_number')) {
                $changedFields[] = 'address_house_number';
            }
            if ($address->isDirty('postal_code_enc') && $address->hasActuallyChanged('postal_code')) {
                $changedFields[] = 'address_postal_code';
            }
            if ($address->isDirty('city_enc') && $address->hasActuallyChanged('city')) {
                $changedFields[] = 'address_city';
            }
            if ($address->isDirty('supplement_enc') && $address->hasActuallyChanged('supplement')) {
                $changedFields[] = 'address_supplement';
            }

            $key = (string) spl_object_id($address);
            self::$gdprChangedFields[$key] = $changedFields;
        });

        static::updated(function (EmployeeAddress $address) {
            $address->loadMissing('employee');

            $key = (string) spl_object_id($address);
            $changedFields = self::$gdprChangedFields[$key] ?? [];

            if (! empty($changedFields) && Auth::check()) {
                activity('employee_changes')
                    ->performedOn($address->employee)
                    ->causedBy(Auth::user())
                    ->withProperties([
                        'changed_fields' => $changedFields,
                        'employee_address_id' => $address->id,
                        'field_count' => count($changedFields),
                        'note' => 'Sensitive personal data changed - values not logged for GDPR compliance (Art. 5 Abs. 1 lit. c - Data Minimization)',
                    ])
                    ->log('Sensitive data changed (GDPR-compliant: no values stored)');
            }

            unset(self::$gdprChangedFields[$key]);
        });

        static::saved(function (EmployeeAddress $address) {
            unset(self::$gdprChangedFields[(string) spl_object_id($address)]);
        });
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<TenantKey, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class);
    }

    public function getStreetAttribute(): ?string
    {
        return $this->street_enc;
    }

    public function setStreetAttribute(?string $value): void
    {
        $this->street_enc = $value;
    }

    public function getHouseNumberAttribute(): ?string
    {
        return $this->house_number_enc;
    }

    public function setHouseNumberAttribute(?string $value): void
    {
        $this->house_number_enc = $value;
    }

    public function getPostalCodeAttribute(): ?string
    {
        return $this->postal_code_enc;
    }

    public function setPostalCodeAttribute(?string $value): void
    {
        $this->postal_code_enc = $value;
    }

    public function getCityAttribute(): ?string
    {
        return $this->city_enc;
    }

    public function setCityAttribute(?string $value): void
    {
        $this->city_enc = $value;
    }

    public function getSupplementAttribute(): ?string
    {
        return $this->supplement_enc;
    }

    public function setSupplementAttribute(?string $value): void
    {
        $this->supplement_enc = $value;
    }

    public function isCurrent(): bool
    {
        return $this->resided_until === null;
    }
}
