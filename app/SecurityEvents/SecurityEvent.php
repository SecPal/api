<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\SecurityEvents;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

final readonly class SecurityEvent
{
    public const SCHEMA_VERSION = 1;

    private const CORRELATION_ATTRIBUTE = 'secpal_security_event_correlation_id';

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        private SecurityEventName $name,
        private string $occurredAt,
        private SecurityEventOutcome $outcome,
        private SecurityEventReason $reason,
        private string $sourceIp,
        private string $correlationId,
        private array $metadata,
        private ?SecurityActorReference $actorReference,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function forRequest(
        Request $request,
        SecurityEventName $name,
        SecurityEventReason $reason,
        array $metadata,
        ?SecurityActorReference $actorReference = null,
    ): self {
        if (SecurityEventDefinition::reason($name) !== $reason) {
            throw new InvalidArgumentException('Security event reason does not match its event definition.');
        }

        SecurityEventDefinition::assertMetadata($name, $metadata);

        $correlationId = $request->attributes->get(self::CORRELATION_ATTRIBUTE);

        if (! is_string($correlationId) || ! self::isUuid($correlationId)) {
            $correlationId = (string) Str::uuid();
            $request->attributes->set(self::CORRELATION_ATTRIBUTE, $correlationId);
        }

        return new self(
            $name,
            now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            SecurityEventDefinition::outcome($name),
            $reason,
            self::canonicalIp((string) $request->ip()),
            $correlationId,
            $metadata,
            $actorReference,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $requiredKeys = [
            'schema_version',
            'event_name',
            'occurred_at',
            'outcome',
            'reason',
            'source_ip',
            'correlation_id',
            'metadata',
        ];
        $allowedKeys = [...$requiredKeys, 'actor_reference'];
        $actualKeys = array_keys($payload);
        $sortedActualKeys = $actualKeys;
        sort($sortedActualKeys);
        sort($allowedKeys);

        if (array_diff($requiredKeys, $actualKeys) !== [] || array_diff($sortedActualKeys, $allowedKeys) !== []) {
            throw new InvalidArgumentException('Security event fields do not match schema version 1.');
        }

        if (($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Unknown security event schema version.');
        }

        $name = self::enumValue(SecurityEventName::class, $payload['event_name'] ?? null);
        $outcome = self::enumValue(SecurityEventOutcome::class, $payload['outcome'] ?? null);
        $reason = self::enumValue(SecurityEventReason::class, $payload['reason'] ?? null);

        if (SecurityEventDefinition::outcome($name) !== $outcome || SecurityEventDefinition::reason($name) !== $reason) {
            throw new InvalidArgumentException('Security event outcome or reason does not match its event definition.');
        }

        $occurredAt = $payload['occurred_at'] ?? null;
        if (! is_string($occurredAt) || ! self::isCanonicalTimestamp($occurredAt)) {
            throw new InvalidArgumentException('Security event timestamp is malformed.');
        }

        $sourceIp = $payload['source_ip'] ?? null;
        if (! is_string($sourceIp) || self::canonicalIp($sourceIp) !== $sourceIp) {
            throw new InvalidArgumentException('Security event source IP is malformed or non-canonical.');
        }

        $correlationId = $payload['correlation_id'] ?? null;
        if (! is_string($correlationId) || ! self::isUuid($correlationId)) {
            throw new InvalidArgumentException('Security event correlation identifier is malformed.');
        }

        $metadata = $payload['metadata'] ?? null;
        if (! is_array($metadata)) {
            throw new InvalidArgumentException('Security event metadata must be an object.');
        }

        foreach (array_keys($metadata) as $metadataKey) {
            if (! is_string($metadataKey)) {
                throw new InvalidArgumentException('Security event metadata keys must be strings.');
            }
        }

        /** @var array<string, mixed> $metadata */
        SecurityEventDefinition::assertMetadata($name, $metadata);

        $actorReference = null;
        if (array_key_exists('actor_reference', $payload)) {
            if (! is_string($payload['actor_reference'])) {
                throw new InvalidArgumentException('Security event actor reference is malformed.');
            }

            $actorReference = SecurityActorReference::fromValue($payload['actor_reference']);
        }

        return new self(
            $name,
            $occurredAt,
            $outcome,
            $reason,
            $sourceIp,
            $correlationId,
            $metadata,
            $actorReference,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'event_name' => $this->name->value,
            'occurred_at' => $this->occurredAt,
            'outcome' => $this->outcome->value,
            'reason' => $this->reason->value,
            'source_ip' => $this->sourceIp,
            'correlation_id' => $this->correlationId,
        ];

        if ($this->actorReference !== null) {
            $payload['actor_reference'] = $this->actorReference->value();
        }

        $payload['metadata'] = $this->metadata;

        return $payload;
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function rateBoundFingerprint(): string
    {
        return hash('sha256', implode('|', [
            (string) self::SCHEMA_VERSION,
            $this->name->value,
            $this->sourceIp,
            $this->actorReference?->value() ?? '-',
        ]));
    }

    private static function canonicalIp(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            throw new InvalidArgumentException('Security event source IP is invalid.');
        }

        $canonical = inet_ntop($packed);

        if ($canonical === false) {
            throw new InvalidArgumentException('Security event source IP cannot be normalized.');
        }

        return $canonical;
    }

    private static function isCanonicalTimestamp(string $value): bool
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\z/', $value) !== 1) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value);

        return $date !== false && $date->format('Y-m-d\TH:i:s.v\Z') === $value;
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $value) === 1;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private static function enumValue(string $enum, mixed $value): \BackedEnum
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Security event enum field is malformed.');
        }

        $resolved = $enum::tryFrom($value);

        if ($resolved === null) {
            throw new InvalidArgumentException('Security event enum field contains an unknown value.');
        }

        return $resolved;
    }
}
