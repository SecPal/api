<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\EnforcesTenantRouteBinding;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property int $tenant_id
 * @property string $legal_entity_id
 * @property string $customer_id
 * @property string $establishment_id
 * @property string|null $contact_name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $comments
 * @property string|null $contact_name_enc
 * @property string|null $phone_enc
 * @property string|null $email_enc
 * @property string|null $comments_enc
 * @property-write string|null $contact_name_plain
 * @property-write string|null $phone_plain
 * @property-write string|null $email_plain
 * @property-write string|null $comments_plain
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Customer $customer
 * @property-read Establishment $establishment
 */
class CustomerEstablishment extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerEstablishmentFactory> */
    use EnforcesTenantRouteBinding, HasFactory, HasUuids, SoftDeletes {
        EnforcesTenantRouteBinding::resolveRouteBindingQuery insteadof HasUuids;
        HasUuids::resolveRouteBindingQuery as resolveUuidRouteBindingQuery;
    }

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'legal_entity_id',
        'customer_id',
        'establishment_id',
        'contact_name_plain',
        'phone_plain',
        'email_plain',
        'comments_plain',
    ];

    /** @var list<string> */
    protected $hidden = [
        'legal_entity_id',
        'contact_name_enc',
        'phone_enc',
        'email_enc',
        'comments_enc',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'contact_name_enc' => \App\Casts\EncryptedWithDek::class,
            'phone_enc' => \App\Casts\EncryptedWithDek::class,
            'email_enc' => \App\Casts\EncryptedWithDek::class,
            'comments_enc' => \App\Casts\EncryptedWithDek::class,
        ];
    }

    public function getContactNameAttribute(): ?string
    {
        return $this->decryptedString('contact_name_enc');
    }

    public function setContactNameAttribute(?string $value): void
    {
        $this->setAttribute('contact_name_plain', $value);
    }

    public function setContactNamePlainAttribute(?string $value): void
    {
        $this->setAttribute('contact_name_enc', $value);
    }

    public function getPhoneAttribute(): ?string
    {
        return $this->decryptedString('phone_enc');
    }

    public function setPhoneAttribute(?string $value): void
    {
        $this->setAttribute('phone_plain', $value);
    }

    public function setPhonePlainAttribute(?string $value): void
    {
        $this->setAttribute('phone_enc', $value);
    }

    public function getEmailAttribute(): ?string
    {
        return $this->decryptedString('email_enc');
    }

    public function setEmailAttribute(?string $value): void
    {
        $this->setAttribute('email_plain', $value);
    }

    public function setEmailPlainAttribute(?string $value): void
    {
        $this->setAttribute('email_enc', $value);
    }

    public function getCommentsAttribute(): ?string
    {
        return $this->decryptedString('comments_enc');
    }

    public function setCommentsAttribute(?string $value): void
    {
        $this->setAttribute('comments_plain', $value);
    }

    public function setCommentsPlainAttribute(?string $value): void
    {
        $this->setAttribute('comments_enc', $value);
    }

    private function decryptedString(string $attribute): ?string
    {
        $value = $this->getAttributeValue($attribute);

        if ($value !== null && ! is_string($value)) {
            throw new \RuntimeException("Decrypted {$attribute} must be a string or null.");
        }

        return $value;
    }

    /** @return BelongsTo<TenantKey, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Establishment, $this> */
    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }
}
