<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Support\ResidentialAddressHistorySchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();
        $schema = json_encode(ResidentialAddressHistorySchema::definition(), JSON_THROW_ON_ERROR);

        DB::table('onboarding_form_templates')
            ->whereNull('tenant_id')
            ->where('template_key', 'residential_address_history')
            ->update([
                'form_schema' => $schema,
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        $now = Carbon::now();
        $schema = json_encode($this->legacyResidentialAddressHistorySchema(), JSON_THROW_ON_ERROR);

        DB::table('onboarding_form_templates')
            ->whereNull('tenant_id')
            ->where('template_key', 'residential_address_history')
            ->update([
                'form_schema' => $schema,
                'updated_at' => $now,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyResidentialAddressHistorySchema(): array
    {
        $schema = ResidentialAddressHistorySchema::definition();
        $schema['properties']['current_address']['properties']['resided_from']['title'] = 'Living There Since';

        return $schema;
    }
};
