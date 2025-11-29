<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CustomerUserObjectAccess model for fine-grained object-level permissions.
 *
 * This model enables object-level access control for customer users who need
 * access to specific objects without full customer hierarchy access.
 * The allowed_actions attribute provides extensible permission control.
 *
 * Typical allowed_actions:
 * - "read_guard_book": View guard book entries
 * - "read_reports": View generated reports
 * - "export_reports": Download/export reports as PDF
 * - "view_shifts": See scheduled shifts
 * - "view_incidents": View incident details
 *
 * IMPORTANT: All actions are READ-ONLY - customer users cannot modify data!
 *
 * @property string $id UUID primary key
 * @property int $tenant_id Foreign key to tenant_keys
 * @property string $user_id Foreign key to users
 * @property string $object_id Foreign key to objects
 * @property list<string> $allowed_actions Array of allowed action identifiers
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read TenantKey $tenant The tenant this access belongs to
 * @property-read User $user The user with this access
 * @property-read SecPalObject $object The object being accessed
 */
class CustomerUserObjectAccess extends Model
{
    use HasUuids;

    /**
     * Default allowed actions when none specified.
     *
     * @var list<string>
     */
    public const array DEFAULT_ALLOWED_ACTIONS = ['read_guard_book'];

    /**
     * All possible allowed actions.
     *
     * @var list<string>
     */
    public const array AVAILABLE_ACTIONS = [
        'read_guard_book',
        'read_reports',
        'export_reports',
        'view_shifts',
        'view_incidents',
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'customer_user_object_accesses';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'object_id',
        'allowed_actions',
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
            'allowed_actions' => 'array',
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
     * Get the object being accessed.
     *
     * @return BelongsTo<SecPalObject, $this>
     */
    public function object(): BelongsTo
    {
        return $this->belongsTo(SecPalObject::class, 'object_id');
    }

    /**
     * Check if a specific action is allowed.
     */
    public function canPerformAction(string $action): bool
    {
        $allowedActions = $this->allowed_actions ?? self::DEFAULT_ALLOWED_ACTIONS;

        return in_array($action, $allowedActions, true);
    }

    /**
     * Grant an additional action to this access record.
     *
     * @return $this
     */
    public function grantAction(string $action): self
    {
        if (! in_array($action, self::AVAILABLE_ACTIONS, true)) {
            throw new \InvalidArgumentException("Unknown action: {$action}");
        }

        $currentActions = $this->allowed_actions ?? [];

        if (! in_array($action, $currentActions, true)) {
            $currentActions[] = $action;
            $this->allowed_actions = $currentActions;
        }

        return $this;
    }

    /**
     * Revoke an action from this access record.
     *
     * @return $this
     */
    public function revokeAction(string $action): self
    {
        $currentActions = $this->allowed_actions ?? [];

        $this->allowed_actions = array_values(
            array_filter($currentActions, fn ($a) => $a !== $action)
        );

        return $this;
    }

    /**
     * Check if user has access to a specific object with a specific action.
     */
    public static function userCanAccessObject(User $user, SecPalObject $object, string $action): bool
    {
        $access = self::where('user_id', $user->id)
            ->where('object_id', $object->id)
            ->first();

        if ($access === null) {
            return false;
        }

        return $access->canPerformAction($action);
    }
}
