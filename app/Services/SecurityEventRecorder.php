<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Contracts\SecurityEventEmitter;
use App\SecurityEvents\SecurityActorReference;
use App\SecurityEvents\SecurityEvent;
use App\SecurityEvents\SecurityEventDefinition;
use App\SecurityEvents\SecurityEventName;
use Illuminate\Http\Request;
use Throwable;

final readonly class SecurityEventRecorder
{
    public function __construct(private SecurityEventEmitter $emitter) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        Request $request,
        SecurityEventName $name,
        array $metadata,
        ?string $actorIdentifier = null,
    ): void {
        try {
            $actorReference = $actorIdentifier === null || $actorIdentifier === ''
                ? null
                : SecurityActorReference::fromIdentifier($actorIdentifier);

            $event = SecurityEvent::forRequest(
                $request,
                $name,
                SecurityEventDefinition::reason($name),
                $metadata,
                $actorReference,
            );

            defer(function () use ($event): void {
                $this->emitter->emit($event);
            }, always: true);
        } catch (Throwable) {
            // Event construction and output are both secondary to the security decision.
        }
    }
}
