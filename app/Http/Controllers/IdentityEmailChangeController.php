<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\GlobalIdentityKeyException;
use App\Http\Requests\PasskeyAuthenticationVerificationRequest;
use App\Models\User;
use App\Services\GlobalIdentity\GlobalIdentityOperationType;
use App\Services\GlobalIdentity\IdentityLifecycle;
use App\Services\MfaService;
use App\Services\PasskeyService;
use App\Support\ApiTimestamp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class IdentityEmailChangeController extends Controller
{
    public function __construct(
        private IdentityLifecycle $lifecycle,
        private PasskeyService $passkeys,
        private MfaService $mfa,
    ) {}

    public function preparePasskey(Request $request): JsonResponse
    {
        $user = $this->account($request);
        $context = $this->credentialContext($request, $user);
        abort_if($context === null || ! $user->passkeyCredentials()->exists(), 403);
        $id = (string) Str::uuid();
        $expires = now()->addMinutes(5);
        $options = $this->passkeys->buildAuthenticationOptions(requireUserVerification: true);
        Cache::put($this->challengeKey($context, $id), $options, $expires);

        return response()->json([
            'challenge_id' => $id,
            'public_key' => $this->passkeys->formatApiPayload($options),
            'expires_at' => ApiTimestamp::format($expires),
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function start(Request $request): JsonResponse
    {
        /** @var array{email:string, second_factor:array{method:'totp'|'passkey', code?:string, challenge_id?:string, credential?:array<string, mixed>}} $proof */
        $proof = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'current_password' => ['required', 'string'],
            'second_factor' => ['required', 'array'],
            'second_factor.method' => ['required', 'in:totp,passkey'],
            'second_factor.code' => ['required_if:second_factor.method,totp', 'string', 'max:16'],
            'second_factor.challenge_id' => ['required_if:second_factor.method,passkey', 'uuid'],
            'second_factor.credential' => ['required_if:second_factor.method,passkey', 'array'],
        ]);
        $user = $this->account($request);
        abort_unless(Hash::check($request->string('current_password')->toString(), $user->password), 403);
        $factor = $proof['second_factor'];
        $verified = $factor['method'] === 'totp'
            ? $this->mfa->verifyEnabledTwoFactorCode($user, 'totp', $factor['code'] ?? '')
            : $this->verifyPasskey($request, $user, $factor);
        abort_unless($verified, 403);
        try {
            $this->lifecycle->request($user, GlobalIdentityOperationType::PendingEmailChange, $proof['email']);
        } catch (GlobalIdentityKeyException) {
            // Address eligibility is deliberately absent from the accepted response.
        }

        return response()->json(['message' => 'Request accepted.'], 202)->header('Cache-Control', 'no-store');
    }

    public function consume(Request $request, string $operationId): Response
    {
        $request->validate(['token' => ['required', 'string', 'max:128']]);
        try {
            if ($this->lifecycle->consume($operationId, GlobalIdentityOperationType::PendingEmailChange, $request->string('token')->toString())) {
                return response()->noContent()->header('Cache-Control', 'no-store');
            }
        } catch (GlobalIdentityKeyException) {
            // The transaction retains the former active email and credentials.
        }

        return response()->json([
            'message' => 'This operation is unavailable.',
            'code' => 'IDENTITY_OPERATION_UNAVAILABLE',
        ], 409)->header('Cache-Control', 'no-store');
    }

    /** @param array<string, mixed> $factor */
    private function verifyPasskey(Request $request, User $user, array $factor): bool
    {
        $context = $this->credentialContext($request, $user);
        if ($context === null || ! is_string($factor['challenge_id'] ?? null)) {
            return false;
        }
        $key = $this->challengeKey($context, $factor['challenge_id']);
        $options = Cache::lock($key.':consume', 10)->get(static fn () => Cache::pull($key));
        if (! is_array($options)) {
            return false;
        }
        /** @var array{credential:array<string, mixed>} $payload */
        $payload = Validator::make($factor, (new PasskeyAuthenticationVerificationRequest)->rules())->validate();
        /** @var array<string, mixed> $options */
        try {
            $this->passkeys->verifyAuthentication($options, $payload['credential'], $user->id);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function account(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function credentialContext(Request $request, User $user): ?string
    {
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $tokenId = $token->getKey();

            return is_int($tokenId) || is_string($tokenId)
                ? $user->id.':'.$user->email_version.':token:'.$tokenId
                : null;
        }

        return $request->hasSession()
            ? $user->id.':'.$user->email_version.':session:'.$request->session()->getId()
            : null;
    }

    private function challengeKey(string $context, string $id): string
    {
        return 'passkeys.email-change.'.hash('sha256', $context.'|'.$id);
    }
}
