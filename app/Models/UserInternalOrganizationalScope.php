<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UserInternalOrganizationalScope model for RBAC integration.
 *
 * Represents access scopes for internal employees based on the security
 * service company's organizational structure. This enables hierarchical
 * permission management where access to a unit can optionally include
 * all its descendants.
 *
 * Access Levels (ordered from least to most permissive):
 * - none: No access (can be used to explicitly deny)
 * - read: View data only
 * - write: View and modify data
 * - manage: Full CRUD including scope and configuration management
 *
 * @property string $id UUID primary key
 * @property string $user_id UUID of the user
 * @property string $organizational_unit_id UUID of the organizational unit
 * @property string $access_level Enum: none, read, write, manage
 * @property bool $include_descendants Whether access extends to all descendants
 * @property int|null $min_viewable_rank Minimum leadership rank user can view (NULL = no minimum)
 * @property int|null $max_viewable_rank Maximum leadership rank user can view (NULL = all leadership plus non-leadership, 0 = non-leadership only)
 * @property int|null $min_assignable_rank Minimum leadership rank user can assign/remove (NULL = no minimum)
 * @property int|null $max_assignable_rank Maximum leadership rank user can assign/remove (NULL = all leadership plus non-leadership, 0 = non-leadership only)
 * @property bool $allow_self_access Allow user to view/edit own employee HR data (default: false for security)
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read User $user The user who has this scope
 * @property-read OrganizationalUnit $organizationalUnit The scoped organizational unit
 */
class UserInternalOrganizationalScope extends Model
{
    /** @use HasFactory<\Database\Factories\UserInternalOrganizationalScopeFactory> */
    use HasFactory, HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_internal_organizational_scopes';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'organizational_unit_id',
        'access_level',
        'include_descendants',
        'min_viewable_rank',
        'max_viewable_rank',
        'min_assignable_rank',
        'max_assignable_rank',
        'allow_self_access',
    ];

    /**
     * Default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'include_descendants' => true,
    ];

    /**
     * Access level hierarchy for comparison.
     * Higher index = more permissive.
     *
     * @var array<string, int>
     */
    public const ACCESS_LEVELS = [
        'none' => 0,
        'read' => 1,
        'write' => 2,
        'manage' => 3,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'include_descendants' => 'boolean',
            'min_viewable_rank' => 'integer',
            'max_viewable_rank' => 'integer',
            'min_assignable_rank' => 'integer',
            'max_assignable_rank' => 'integer',
            'allow_self_access' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the user who has this scope.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the organizational unit this scope applies to.
     *
     * @return BelongsTo<OrganizationalUnit, $this>
     */
    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organizational_unit_id');
    }

    /**
     * Check if this scope has the specified access level.
     */
    public function hasAccessLevel(string $level): bool
    {
        return $this->access_level === $level;
    }

    /**
     * Check if this scope has at least the specified minimum access level.
     *
     * Access levels are ordered: none < read < write < manage
     */
    public function hasMinimumAccessLevel(string $minimumLevel): bool
    {
        if (! array_key_exists($minimumLevel, self::ACCESS_LEVELS)) {
            return false;
        }

        $currentLevel = self::ACCESS_LEVELS[$this->access_level] ?? 0;
        $requiredLevel = self::ACCESS_LEVELS[$minimumLevel];

        return $currentLevel >= $requiredLevel;
    }

    /**
     * Get the numeric value of the current access level.
     */
    public function getAccessLevelValue(): int
    {
        return self::ACCESS_LEVELS[$this->access_level] ?? 0;
    }

    /**
     * Determine whether this scope can view the given employee management level.
     */
    public function canViewManagementLevel(int $managementLevel): bool
    {
        return $this->isWithinViewableManagementLevelRange(
            $managementLevel,
            $this->min_viewable_rank,
            $this->max_viewable_rank,
        );
    }

    /**
     * Determine whether this scope can assign the given employee management level.
     */
    public function canAssignManagementLevel(int $managementLevel): bool
    {
        return $this->isWithinAssignableManagementLevelRange(
            $managementLevel,
            $this->min_assignable_rank,
            $this->max_assignable_rank,
        );
    }

    private function isWithinViewableManagementLevelRange(int $managementLevel, ?int $minimumLevel, ?int $maximumLevel): bool
    {
        if ($managementLevel === 0) {
            return ($maximumLevel === null || $maximumLevel === 0)
                && ($minimumLevel === null || $minimumLevel === 0);
        }

        if ($maximumLevel === 0) {
            return false;
        }

        if ($minimumLevel !== null && $managementLevel < $minimumLevel) {
            return false;
        }

        if ($maximumLevel !== null && $managementLevel > $maximumLevel) {
            return false;
        }

        return true;
    }

    private function isWithinAssignableManagementLevelRange(int $managementLevel, ?int $minimumLevel, ?int $maximumLevel): bool
    {
        if ($managementLevel === 0) {
            return ($maximumLevel === null || $maximumLevel === 0)
                && ($minimumLevel === null || $minimumLevel === 0);
        }

        if ($maximumLevel === 0) {
            return false;
        }

        if ($minimumLevel !== null && $managementLevel < $minimumLevel) {
            return false;
        }

        if ($maximumLevel !== null && $managementLevel > $maximumLevel) {
            return false;
        }

        return true;
    }
}
