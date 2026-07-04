<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Employee Onboarding Token
 *
 * Single-use magic link tokens for new employee account setup.
 *
 * Security features:
 * - Tokens are hashed with bcrypt before storage (never plain text)
 * - Constant-time token comparison (prevents timing attacks)
 * - Single-use enforcement via completed_at timestamp
 * - 7-day expiry by default
 * - Audit trail (IP address, user agent, timestamps)
 *
 * Usage:
 * ```
 * // Generate token for new employee
 * $result = EmployeeOnboardingToken::generate($employee);
 * $plainToken = $result['plain']; // Send this via email (one-time access)
 * $model = $result['model']; // Stored in database (hashed)
 *
 * // Later: Validate token from magic link
 * $token = EmployeeOnboardingToken::findByPlainToken($request->token);
 * if ($token && $token->isValid()) {
 *     // Complete onboarding...
 *     $token->markAsCompleted($request->ip(), $request->userAgent());
 * }
 * ```
 *
 * @property string $id UUID
 * @property string $employee_id UUID of employee
 * @property string $token Hashed token (bcrypt)
 * @property string|null $token_lookup_hash Deterministic SHA-256 lookup hash for indexed lookups
 * @property \Illuminate\Support\Carbon $expires_at Token expiry timestamp
 * @property \Illuminate\Support\Carbon|null $completed_at Token completion timestamp
 * @property string|null $completed_from_ip IP address used for completion
 * @property string|null $completed_user_agent User agent used for completion
 * @property \Illuminate\Support\Carbon|null $invalidated_at Timestamp at which the link was burned by a failed identity proof
 * @property string|null $invalidated_from_ip IP address of the request that burned the link
 * @property string|null $invalidated_user_agent User agent of the request that burned the link
 * @property string|null $invalidation_reason Machine-readable reason (e.g. "identity_verification_failed")
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Employee $employee
 *
 * @template TFactory of \Database\Factories\EmployeeOnboardingTokenFactory
 */
class EmployeeOnboardingToken extends Model
{
    /** @use HasFactory<TFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'token',
        'token_lookup_hash',
        'expires_at',
        'completed_at',
        'completed_from_ip',
        'completed_user_agent',
        'invalidated_at',
        'invalidated_from_ip',
        'invalidated_user_agent',
        'invalidation_reason',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'invalidated_at' => 'datetime',
    ];

    /**
     * Generate new onboarding token for employee
     *
     * Creates a single-use token valid for 7 days.
     * Returns both the model (with hashed token) and the plain token.
     * The plain token is ONLY available at generation time and should
     * be sent immediately via email.
     *
     * @param  Employee  $employee  Employee to generate token for
     * @return array{model: EmployeeOnboardingToken<TFactory>, plain: string}
     */
    public static function generate(Employee $employee): array
    {
        // Generate cryptographically secure 64-character token
        $plainToken = Str::random(64);

        $token = self::create([
            'employee_id' => $employee->id,
            'token' => Hash::make($plainToken),
            'token_lookup_hash' => self::buildTokenLookupHash($plainToken),
            'expires_at' => now()->addDays(7),
        ]);

        return [
            'model' => $token,
            'plain' => $plainToken,
        ];
    }

    /**
     * Find valid token by plain text
     *
     * Searches for unexpired, uncompleted tokens and uses constant-time
     * comparison to prevent timing attacks.
     *
     * @param  string  $plainToken  Plain text token from magic link
     * @return EmployeeOnboardingToken<TFactory>|null Token model or null if not found/invalid
     */
    public static function findByPlainToken(string $plainToken): ?self
    {
        $lookupHash = self::buildTokenLookupHash($plainToken);

        /** @var EmployeeOnboardingToken<TFactory>|null $token */
        $token = self::whereNull('completed_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now())
            ->where('token_lookup_hash', $lookupHash)
            ->first();

        if ($token instanceof self && Hash::check($plainToken, $token->token)) {
            return $token;
        }

        return null;
    }

    private static function buildTokenLookupHash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Mark token as completed
     *
     * Records completion timestamp and audit trail information.
     * Token becomes invalid after this operation.
     *
     * @param  string  $ip  IP address of completion request
     * @param  string  $userAgent  User agent string (truncated to 500 chars)
     */
    public function markAsCompleted(string $ip, string $userAgent): void
    {
        $this->update([
            'completed_at' => now(),
            'completed_from_ip' => $ip,
            'completed_user_agent' => substr($userAgent, 0, 500),
        ]);
    }

    /**
     * Burn the magic link because of a failed identity proof.
     *
     * Distinct from {@see markAsCompleted()} — invalidation captures attacks /
     * mistakes that must NOT be retried with the same link. The legitimate
     * invitee has to request a fresh invitation from HR; future calls to
     * {@see findByPlainToken()} will not find the burned record, and
     * {@see isValid()} will report `false` even before natural expiry.
     *
     * @param  string  $ip  IP address of the rejecting request
     * @param  string  $userAgent  User agent string (truncated to 500 chars)
     * @param  string  $reason  Short machine-readable reason (e.g. "identity_verification_failed")
     */
    public function markAsInvalidated(string $ip, string $userAgent, string $reason): void
    {
        $this->update([
            'invalidated_at' => now(),
            'invalidated_from_ip' => $ip,
            'invalidated_user_agent' => substr($userAgent, 0, 500),
            'invalidation_reason' => substr($reason, 0, 64),
        ]);
    }

    /**
     * Check if token is valid
     *
     * Token is valid if:
     * - Not yet completed (completed_at is null)
     * - Not yet invalidated by a failed identity proof (invalidated_at is null)
     * - Not yet expired (expires_at is in the future)
     *
     * @return bool True if token can be used
     */
    public function isValid(): bool
    {
        return $this->completed_at === null
            && $this->invalidated_at === null
            && $this->expires_at > now();
    }

    /**
     * Employee relationship
     *
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
