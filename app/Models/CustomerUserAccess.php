<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CustomerUserAccess model for customer user RBAC integration.
 *
 * This model maps external customer users (Client role) to their access scopes
 * within customer hierarchies. Customer users have READ-ONLY access to their
 * assigned customers and optionally all descendants.
 *
 * Access patterns:
 * - corporate_wide + include_descendants: Access all customers in hierarchy
 * - regional + include_descendants: Access regional customer and local children
 * - local: Access only the specific local customer
 *
 * IMPORTANT: Customer users have READ-ONLY access (no create/update/delete)!
 *
 * @property string $id UUID primary key
 * @property int $tenant_id Foreign key to tenant_keys
 * @property string $user_id Foreign key to users
 * @property string $customer_id Foreign key to customers
 * @property string $access_level Enum: corporate_wide, regional, local
 * @property bool $include_descendants Whether access includes all descendant customers
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read TenantKey $tenant The tenant this access belongs to
 * @property-read User $user The user with this access
 * @property-read Customer $customer The customer being accessed
 */
class CustomerUserAccess extends Model
{
    use HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'customer_user_accesses';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'customer_id',
        'access_level',
        'include_descendants',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'include_descendants' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the tenant that owns this access record.
     *
     * @return BelongsTo<TenantKey, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /**
     * Get the user with this access.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the customer being accessed.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * Get all customers accessible to a user based on this access record.
     *
     * If include_descendants is true, returns the customer plus all its descendants.
     * Otherwise, returns only the directly assigned customer.
     *
     * @return Collection<int, Customer>
     */
    public function getAccessibleCustomers(): Collection
    {
        if (! $this->include_descendants) {
            // Return only the directly assigned customer
            return Customer::where('id', $this->customer_id)->get();
        }

        // Get assigned customer and all descendants via closure table
        $customerIds = CustomerClosure::where('ancestor_id', $this->customer_id)
            ->pluck('descendant_id');

        return Customer::whereIn('id', $customerIds)->get();
    }

    /**
     * Get all customers accessible to a specific user across all their access records.
     *
     * Aggregates all accessible customers from all CustomerUserAccess records for the user.
     *
     * @return Collection<int, Customer>
     */
    public static function getAccessibleCustomersForUser(User $user): Collection
    {
        $accesses = self::where('user_id', $user->id)->get();

        if ($accesses->isEmpty()) {
            return new Collection;
        }

        $customerIds = collect();

        foreach ($accesses as $access) {
            if ($access->include_descendants) {
                // Include assigned customer and all descendants
                $descendantIds = CustomerClosure::where('ancestor_id', $access->customer_id)
                    ->pluck('descendant_id');
                $customerIds = $customerIds->merge($descendantIds);
            } else {
                // Include only the directly assigned customer
                $customerIds->push($access->customer_id);
            }
        }

        return Customer::whereIn('id', $customerIds->unique())->get();
    }
}
