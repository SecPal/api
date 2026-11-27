<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use SensitiveParameter;

final readonly class GlobalIdentityJournalInterval
{
    /** @param list<GlobalIdentityTerminalJournalEntry> $entries */
    public function __construct(
        public string $backupEpoch,
        public int $backupSequence,
        public int $checkpointSequence,
        public array $entries,
        #[SensitiveParameter] public string $authenticator,
    ) {}

    public function checkpoint(): string
    {
        $digest = hash_init('sha256');
        foreach ($this->entries as $entry) {
            hash_update($digest, $entry->canonicalBytes());
        }

        return GlobalIdentityCanonicalEncoding::encode(
            'secpal/global-identity/terminal-journal-checkpoint/v1',
            $this->backupEpoch,
            (string) $this->backupSequence,
            (string) $this->checkpointSequence,
            hash_final($digest, true),
        );
    }

    public function isContinuous(): bool
    {
        if ($this->backupSequence < 0 || $this->checkpointSequence < $this->backupSequence
            || $this->checkpointSequence - $this->backupSequence !== count($this->entries)) {
            return false;
        }
        $sequence = $this->backupSequence;
        foreach ($this->entries as $entry) {
            if ($entry->sequence !== ++$sequence) {
                return false;
            }
        }

        return true;
    }

    /** @return array{backupEpoch:string,backupSequence:int,checkpointSequence:int} */
    public function __debugInfo(): array
    {
        return [
            'backupEpoch' => $this->backupEpoch,
            'backupSequence' => $this->backupSequence,
            'checkpointSequence' => $this->checkpointSequence,
        ];
    }
}
