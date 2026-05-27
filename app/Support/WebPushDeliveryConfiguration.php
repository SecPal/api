<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Support;

use App\Support\Concerns\InteractsWithConfigValues;

final class WebPushDeliveryConfiguration
{
    use InteractsWithConfigValues;

    /**
     * @return array<int, string>
     */
    public function missingFields(): array
    {
        $missingFields = [];

        if ($this->publicKey() === null) {
            $missingFields[] = 'services.web_push.public_key';
        }

        if ($this->subject() === null) {
            $missingFields[] = 'services.web_push.subject';
        }

        if ($this->privateKey() === null) {
            $missingFields[] = 'services.web_push.private_key';
        }

        return $missingFields;
    }

    public function publicKey(): ?string
    {
        return $this->trimmedStringConfig('services.web_push.public_key');
    }

    public function subject(): ?string
    {
        return $this->trimmedStringConfig('services.web_push.subject');
    }

    public function privateKey(): ?string
    {
        return $this->trimmedStringConfig('services.web_push.private_key');
    }

    public function ttl(): int
    {
        $value = config('services.web_push.ttl');

        if (is_int($value)) {
            return $value >= 0 ? $value : 300;
        }

        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value)) {
            return (int) $value;
        }

        return 300;
    }

    public function urgency(): string
    {
        $urgency = $this->trimmedStringConfig('services.web_push.urgency');

        return in_array($urgency, ['very-low', 'low', 'normal', 'high'], true)
            ? $urgency
            : 'normal';
    }

    public function connectTimeout(): int
    {
        return $this->positiveIntegerConfig('services.web_push.connect_timeout', 5) ?? 5;
    }

    public function timeout(): int
    {
        return $this->positiveIntegerConfig('services.web_push.timeout', 20) ?? 20;
    }
}
