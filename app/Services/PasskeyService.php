<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\Serializer;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

class PasskeyService
{
    private const AUTHENTICATION_FALLBACK_SCOPE = 'passkey-authentication-fallback:v1';

    private const ANONYMOUS_AUTHENTICATION_FALLBACK_SCOPE = '[anonymous]';

    private Serializer $serializer;

    private AuthenticatorAttestationResponseValidator $attestationValidator;

    private AuthenticatorAssertionResponseValidator $assertionValidator;

    public function __construct()
    {
        $ceremonyStepManagerFactory = new CeremonyStepManagerFactory;
        $attestationStatementSupportManager = new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport,
        ]);

        $ceremonyStepManagerFactory->setAttestationStatementSupportManager($attestationStatementSupportManager);
        $ceremonyStepManagerFactory->setAllowedOrigins(
            $this->allowedOrigins(),
            $this->allowSubdomains(),
        );

        $serializerFactory = new WebauthnSerializerFactory($attestationStatementSupportManager);

        $serializer = $serializerFactory->create();

        if (! $serializer instanceof Serializer) {
            throw new \RuntimeException('The WebAuthn serializer factory did not return a concrete serializer instance.');
        }

        $this->serializer = $serializer;
        $this->attestationValidator = AuthenticatorAttestationResponseValidator::create(
            $ceremonyStepManagerFactory->creationCeremony(),
        );
        $this->assertionValidator = AuthenticatorAssertionResponseValidator::create(
            $ceremonyStepManagerFactory->requestCeremony(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAuthenticationOptions(?User $user = null, ?string $email = null): array
    {
        $timeout = $this->challengeTimeoutMs();
        $allowCredentials = $this->resolveAuthenticationAllowCredentials($user, $email);

        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            rpId: $this->relyingPartyId(),
            allowCredentials: $allowCredentials,
            userVerification: $this->userVerification(),
            timeout: $timeout,
        );

        return $this->normalizeOptions($options);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildRegistrationOptions(User $user): array
    {
        $timeout = $this->challengeTimeoutMs();

        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create($this->relyingPartyName(), $this->relyingPartyId()),
            PublicKeyCredentialUserEntity::create($user->email, $user->id, $user->name),
            random_bytes(32),
            [
                PublicKeyCredentialParameters::createPk(-7),
                PublicKeyCredentialParameters::createPk(-257),
            ],
            AuthenticatorSelectionCriteria::create(
                $this->authenticatorAttachment(),
                $this->userVerification(),
                $this->residentKey(),
            ),
            $this->attestationPreference(),
            $user->passkeyCredentials
                ->map(fn (PasskeyCredential $credential): PublicKeyCredentialDescriptor => $credential->toPublicKeyCredentialSource()->getPublicKeyCredentialDescriptor())
                ->all(),
            $timeout,
        );

        return $this->normalizeOptions($options);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCredentials(User $user): array
    {
        /** @var list<array<string, mixed>> $credentials */
        $credentials = array_values(
            $user->passkeyCredentials
                ->sortBy('created_at')
                ->map(fn (PasskeyCredential $credential): array => $this->formatCredentialSummary($credential))
                ->all(),
        );

        return $credentials;
    }

    /**
     * @param  array<string, mixed>  $storedOptions
     * @param  array<string, mixed>  $credentialPayload
     */
    public function verifyRegistration(User $user, array $storedOptions, array $credentialPayload, ?string $label = null): PasskeyCredential
    {
        $publicKeyCredential = $this->deserializeCredential($credentialPayload);

        if (! $publicKeyCredential->response instanceof AuthenticatorAttestationResponse) {
            throw AuthenticatorResponseVerificationException::create('Invalid attestation response.');
        }

        /** @var PublicKeyCredentialCreationOptions $creationOptions */
        $creationOptions = $this->serializer->denormalize(
            $storedOptions,
            PublicKeyCredentialCreationOptions::class,
        );

        $credentialSource = $this->attestationValidator->check(
            $publicKeyCredential->response,
            $creationOptions,
            $this->relyingPartyId(),
        );

        if ($credentialSource->userHandle !== $user->id) {
            throw AuthenticatorResponseVerificationException::create('Passkey user handle mismatch.');
        }

        $credentialId = Base64UrlSafe::encodeUnpadded($credentialSource->publicKeyCredentialId);

        if (PasskeyCredential::query()->where('credential_id', $credentialId)->exists()) {
            throw AuthenticatorResponseVerificationException::create('The passkey credential already exists.');
        }

        return PasskeyCredential::query()->create([
            'user_id' => $user->id,
            'credential_id' => $credentialId,
            'label' => $this->resolveLabel($label, $credentialId),
            'transports' => $credentialSource->transports,
            'authenticator_attachment' => null,
            'aaguid' => $this->normalizeAaguid($credentialSource),
            'attestation_type' => $credentialSource->attestationType,
            'credential_public_key' => Base64UrlSafe::encodeUnpadded($credentialSource->credentialPublicKey),
            'user_handle' => Base64UrlSafe::encodeUnpadded($credentialSource->userHandle),
            'counter' => $credentialSource->counter,
            'user_verified' => $credentialSource->uvInitialized ?? false,
            'backup_eligible' => $credentialSource->backupEligible ?? false,
            'backup_state' => $credentialSource->backupStatus ?? false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $storedOptions
     * @param  array<string, mixed>  $credentialPayload
     * @return array{user: User, credential: PasskeyCredential}
     */
    public function verifyAuthentication(array $storedOptions, array $credentialPayload): array
    {
        $publicKeyCredential = $this->deserializeCredential($credentialPayload);

        if (! $publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
            throw AuthenticatorResponseVerificationException::create('Invalid assertion response.');
        }

        $credentialId = Base64UrlSafe::encodeUnpadded($publicKeyCredential->rawId);

        /** @var PublicKeyCredentialRequestOptions $requestOptions */
        $requestOptions = $this->serializer->denormalize(
            $storedOptions,
            PublicKeyCredentialRequestOptions::class,
        );

        $assertionResponse = $publicKeyCredential->response;

        /** @var array{user: User, credential: PasskeyCredential} $result */
        $result = DB::transaction(function () use ($credentialId, $assertionResponse, $requestOptions): array {
            $credential = PasskeyCredential::query()
                ->where('credential_id', $credentialId)
                ->lockForUpdate()
                ->first();

            if (! $credential instanceof PasskeyCredential) {
                throw AuthenticatorResponseVerificationException::create('The passkey credential is invalid.');
            }

            $updatedSource = $this->assertionValidator->check(
                $credential->toPublicKeyCredentialSource(),
                $assertionResponse,
                $requestOptions,
                $this->relyingPartyId(),
                $assertionResponse->userHandle,
            );

            $credential->forceFill([
                'counter' => $updatedSource->counter,
                'user_verified' => $updatedSource->uvInitialized ?? $credential->user_verified,
                'backup_eligible' => $updatedSource->backupEligible ?? $credential->backup_eligible,
                'backup_state' => $updatedSource->backupStatus ?? $credential->backup_state,
                'last_used_at' => now(),
            ])->save();

            $user = $credential->user()->first();

            if (! $user instanceof User) {
                throw AuthenticatorResponseVerificationException::create('The passkey credential owner is invalid.');
            }

            return [
                'user' => $user,
                'credential' => $credential,
            ];
        });

        return $result;
    }

    /**
     * @return array{remaining_passkeys: int}
     */
    public function deleteCredential(User $user, PasskeyCredential $credential): array
    {
        $credential->delete();

        return [
            'remaining_passkeys' => $user->passkeyCredentials()->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $credentialPayload
     */
    private function deserializeCredential(array $credentialPayload): PublicKeyCredential
    {
        /** @var PublicKeyCredential $publicKeyCredential */
        $publicKeyCredential = $this->serializer->denormalize(
            $this->keysToCamelCase($credentialPayload),
            PublicKeyCredential::class,
        );

        return $publicKeyCredential;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeOptions(PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): array
    {
        /** @var array<string, mixed> $normalized */
        $normalized = $this->serializer->normalize($options);

        return $normalized;
    }

    /**
     * @return list<PublicKeyCredentialDescriptor>
     */
    private function resolveAuthenticationAllowCredentials(?User $user, ?string $email): array
    {
        $allowCredentials = $user?->passkeyCredentials
            ->map(fn (PasskeyCredential $credential): PublicKeyCredentialDescriptor => $credential->toPublicKeyCredentialSource()->getPublicKeyCredentialDescriptor())
            ->values()
            ->all() ?? [];

        if ($allowCredentials !== []) {
            return $allowCredentials;
        }

        return [
            PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                $this->buildFallbackAuthenticationCredentialId($email),
                [PublicKeyCredentialDescriptor::AUTHENTICATOR_TRANSPORT_INTERNAL],
            ),
        ];
    }

    private function buildFallbackAuthenticationCredentialId(?string $email): string
    {
        $emailScope = is_string($email) && $email !== ''
            ? mb_strtolower($email)
            : self::ANONYMOUS_AUTHENTICATION_FALLBACK_SCOPE;

        return hash_hmac(
            'sha256',
            self::AUTHENTICATION_FALLBACK_SCOPE.'|'.$emailScope,
            $this->authenticationFallbackSecret(),
            true,
        );
    }

    private function authenticationFallbackSecret(): string
    {
        $secret = config('passkeys.authentication_fallback_secret', config('app.key', ''));

        if (! is_string($secret) || $secret === '') {
            return self::AUTHENTICATION_FALLBACK_SCOPE;
        }

        if (Str::startsWith($secret, 'base64:')) {
            $decodedSecret = base64_decode(Str::after($secret, 'base64:'), true);

            if (is_string($decodedSecret) && $decodedSecret !== '') {
                return $decodedSecret;
            }
        }

        return $secret;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function formatApiPayload(array $payload): array
    {
        /** @var array<string, mixed> $formatted */
        $formatted = $this->keysToSnakeCase($payload);

        if (isset($formatted['authenticator_selection']) && is_array($formatted['authenticator_selection'])) {
            if ($this->requireResidentKey()) {
                $formatted['authenticator_selection']['require_resident_key'] = true;
            }

            // Strip null authenticator_attachment so browsers don't receive the
            // invalid enum value "null" from JSON null → DOMString coercion.
            if (array_key_exists('authenticator_attachment', $formatted['authenticator_selection'])
                && $formatted['authenticator_selection']['authenticator_attachment'] === null) {
                unset($formatted['authenticator_selection']['authenticator_attachment']);
            }
        }

        // Strip the deprecated icon field that webauthn-lib serializes as null.
        if (isset($formatted['rp']) && is_array($formatted['rp'])
            && array_key_exists('icon', $formatted['rp']) && $formatted['rp']['icon'] === null) {
            unset($formatted['rp']['icon']);
        }

        // Omit empty credential lists so browsers don't choke on zero-length
        // arrays where the field should be absent per the WebAuthn spec intent.
        if (array_key_exists('allow_credentials', $formatted) && $formatted['allow_credentials'] === []) {
            unset($formatted['allow_credentials']);
        }

        if (array_key_exists('exclude_credentials', $formatted) && $formatted['exclude_credentials'] === []) {
            unset($formatted['exclude_credentials']);
        }

        return $formatted;
    }

    /**
     * @return array<string, mixed>
     */
    public function formatCredentialSummary(PasskeyCredential $credential): array
    {
        return [
            'id' => $credential->credential_id,
            'label' => $credential->label,
            'created_at' => $credential->created_at->toIso8601String(),
            'last_used_at' => $credential->last_used_at?->toIso8601String(),
            'transports' => is_array($credential->transports) ? array_values($credential->transports) : [],
            'authenticator_attachment' => $credential->authenticator_attachment,
            'aaguid' => $credential->aaguid,
            'user_verified' => $credential->user_verified,
            'backup_eligible' => $credential->backup_eligible,
            'backup_state' => $credential->backup_state,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function keysToSnakeCase(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            $normalizedValue = is_array($value) ? $this->keysToSnakeCase($value) : $value;

            if (is_int($key)) {
                $result[$key] = $normalizedValue;

                continue;
            }

            $result[Str::snake($key)] = $normalizedValue;
        }

        return $result;
    }

    /**
     * WebAuthn property names whose acronym casing Str::camel() cannot infer.
     *
     * @var array<string, string>
     */
    private const CAMEL_CASE_OVERRIDES = [
        'client_data_json' => 'clientDataJSON',
    ];

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function keysToCamelCase(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            $normalizedValue = is_array($value) ? $this->keysToCamelCase($value) : $value;

            if (is_int($key)) {
                $result[$key] = $normalizedValue;

                continue;
            }

            $result[self::CAMEL_CASE_OVERRIDES[$key] ?? Str::camel($key)] = $normalizedValue;
        }

        return $result;
    }

    private function resolveLabel(?string $label, string $credentialId): string
    {
        $trimmed = is_string($label) ? trim($label) : '';

        if ($trimmed !== '') {
            return $trimmed;
        }

        return 'Passkey '.substr($credentialId, 0, 8);
    }

    private function normalizeAaguid(CredentialRecord $credentialSource): ?string
    {
        $value = $credentialSource->aaguid->toRfc4122();

        return $value === '00000000-0000-0000-0000-000000000000' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(): array
    {
        $origins = config('passkeys.allowed_origins', ['https://app.secpal.dev']);

        $allowedOrigins = is_array($origins)
            ? array_values(array_filter(array_map(static fn (mixed $origin): string => is_string($origin) ? trim($origin) : '', $origins)))
            : ['https://app.secpal.dev'];

        $androidOrigin = $this->androidPasskeyOrigin();

        if ($androidOrigin !== null && ! in_array($androidOrigin, $allowedOrigins, true)) {
            $allowedOrigins[] = $androidOrigin;
        }

        return $allowedOrigins;
    }

    private function androidPasskeyOrigin(): ?string
    {
        $fingerprint = config('android.signing_certificate_sha256_fingerprint');

        if (! is_string($fingerprint) || trim($fingerprint) === '') {
            return null;
        }

        $fingerprintHex = str_replace(':', '', strtoupper(trim($fingerprint)));

        if (! preg_match('/\A[0-9A-F]{64}\z/', $fingerprintHex)) {
            throw new \InvalidArgumentException('android.signing_certificate_sha256_fingerprint must be a 32-byte SHA-256 certificate fingerprint in hexadecimal form, with or without colon separators.');
        }

        $fingerprintBytes = hex2bin($fingerprintHex);

        if ($fingerprintBytes === false) {
            throw new \InvalidArgumentException('android.signing_certificate_sha256_fingerprint could not be decoded.');
        }

        return 'android:apk-key-hash:'.Base64UrlSafe::encodeUnpadded($fingerprintBytes);
    }

    private function allowSubdomains(): bool
    {
        return (bool) config('passkeys.allow_subdomains', false);
    }

    private function relyingPartyId(): string
    {
        $rpId = config('passkeys.rp_id', 'app.secpal.dev');

        return is_string($rpId) && $rpId !== '' ? $rpId : 'app.secpal.dev';
    }

    private function relyingPartyName(): string
    {
        $rpName = config('passkeys.rp_name', 'SecPal');

        return is_string($rpName) && $rpName !== '' ? $rpName : 'SecPal';
    }

    /**
     * @return positive-int
     */
    private function challengeTimeoutMs(): int
    {
        $timeout = config('passkeys.challenge_timeout_ms', 60000);

        return is_int($timeout) && $timeout > 0 ? $timeout : 60000;
    }

    private function attestationPreference(): string
    {
        $attestation = config('passkeys.attestation', 'none');

        return is_string($attestation) && $attestation !== '' ? $attestation : 'none';
    }

    private function userVerification(): string
    {
        $userVerification = config('passkeys.user_verification', 'preferred');

        return is_string($userVerification) && $userVerification !== '' ? $userVerification : 'preferred';
    }

    private function residentKey(): string
    {
        $residentKey = config('passkeys.resident_key', 'preferred');

        return is_string($residentKey) && $residentKey !== '' ? $residentKey : 'preferred';
    }

    private function requireResidentKey(): bool
    {
        return (bool) config('passkeys.require_resident_key', false);
    }

    private function authenticatorAttachment(): ?string
    {
        $attachment = config('passkeys.authenticator_attachment');

        return is_string($attachment) && $attachment !== '' ? $attachment : null;
    }
}
