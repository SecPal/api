<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

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
 * @property \Illuminate\Support\Carbon $expires_at Token expiry timestamp
 * @property \Illuminate\Support\Carbon|null $completed_at Token completion timestamp
 * @property string|null $completed_from_ip IP address used for completion
 * @property string|null $completed_user_agent User agent used for completion
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
        'expires_at',
        'completed_at',
        'completed_from_ip',
        'completed_user_agent',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
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
        return self::whereNull('completed_at')
            ->where('expires_at', '>', now())
            ->get()
            ->first(fn ($token) => Hash::check($plainToken, $token->token));
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
     * Check if token is valid
     *
     * Token is valid if:
     * - Not yet completed (completed_at is null)
     * - Not yet expired (expires_at is in the future)
     *
     * @return bool True if token can be used
     */
    public function isValid(): bool
    {
        return $this->completed_at === null && $this->expires_at > now();
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
