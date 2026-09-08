<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Contracts\SecurityEventEmitter;
use App\SecurityEvents\SecurityEvent;
use Illuminate\Cache\RateLimiter;
use Illuminate\Log\LogManager;
use Throwable;

final readonly class BoundedSecurityEventEmitter implements SecurityEventEmitter
{
    private const LOG_CHANNEL = 'security_events';

    private const RATE_KEY_PREFIX = 'security-events:v1:';

    public function __construct(
        private LogManager $logs,
        private RateLimiter $rateLimiter,
    ) {}

    public function emit(SecurityEvent $event): void
    {
        try {
            if (! $this->withinRateBound($event)) {
                return;
            }

            $this->logs->channel(self::LOG_CHANNEL)
                ->info($event->toJson());
        } catch (Throwable) {
            // Security telemetry is secondary. Its local sink or cache must never
            // change an authentication, authorization, or password-reset decision.
        }
    }

    private function withinRateBound(SecurityEvent $event): bool
    {
        $perFingerprint = $this->boundedConfigInteger('security-events.rate_bound.per_fingerprint', 5, 1, 100);
        $global = $this->boundedConfigInteger('security-events.rate_bound.global', 300, 1, 10_000);
        $decaySeconds = $this->boundedConfigInteger('security-events.rate_bound.decay_seconds', 60, 1, 3600);

        $fingerprintKey = self::RATE_KEY_PREFIX.'fingerprint:'.$event->rateBoundFingerprint();
        $fingerprintHits = $this->rateLimiter->hit($fingerprintKey, $decaySeconds);

        if ($fingerprintHits > $perFingerprint) {
            $this->rateLimiter->decrement($fingerprintKey, $decaySeconds);

            return false;
        }

        $globalKey = self::RATE_KEY_PREFIX.'global';
        $globalHits = $this->rateLimiter->hit($globalKey, $decaySeconds);

        if ($globalHits > $global) {
            $this->rateLimiter->decrement($globalKey, $decaySeconds);
            $this->rateLimiter->decrement($fingerprintKey, $decaySeconds);

            return false;
        }

        return true;
    }

    private function boundedConfigInteger(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = config($key, $default);

        if (! is_int($value)) {
            return $default;
        }

        return max($minimum, min($maximum, $value));
    }
}
