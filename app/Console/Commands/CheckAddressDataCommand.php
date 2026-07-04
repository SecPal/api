<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Console\Commands;

use App\Models\AddressDataImport;
use App\Services\AddressData\AddressSuggestionService;
use App\Support\AddressDataConfig;
use Illuminate\Console\Command;

class CheckAddressDataCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'addresses:check';

    /**
     * @var string
     */
    protected $description = 'Show the active OpenPLZ address import status.';

    public function handle(AddressSuggestionService $suggestions): int
    {
        $country = AddressDataConfig::string('address_data.country', 'DE');
        $active = $suggestions->activeImport($country);

        if ($active === null) {
            $this->components->warn('No activated address import is available.');

            return self::SUCCESS;
        }

        $this->line('Country: '.$active->country_code);
        $this->line('Source: '.$active->source_name);
        $this->line('URL: '.$active->source_url);
        $this->line('Rows: '.(string) $active->row_count);
        $this->line('SHA-256: '.($active->source_sha256 ?? '—'));
        $this->line('License: '.($active->license ?? '—'));
        $this->line('Attribution: '.($active->attribution ?? '—'));
        $this->line('Activated at: '.($active->activated_at?->toIso8601String() ?? '—'));

        $previous = AddressDataImport::query()
            ->where('country_code', $country)
            ->where('id', '<', $active->id)
            ->orderByDesc('id')
            ->first();

        if ($previous instanceof AddressDataImport) {
            $this->line('Previous import id: '.$previous->id.' ('.$previous->status.')');
        }

        return self::SUCCESS;
    }
}
