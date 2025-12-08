<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QualificationsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeds 14 predefined system-wide qualifications for the security industry.
     * These qualifications have tenant_id = NULL and is_system_qualification = true.
     *
     * First Aid: Betrieblicher Ersthelfer (2y renewal) + Betriebssanitäter (3y renewal)
     */
    public function run(): void
    {
        $systemQualifications = [
            // BewachV §34a - Basic qualifications
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => null,
                'name' => '§34a Sachkundeunterrichtung (40h)',
                'description' => '40-stündige Unterrichtung nach §34a GewO. Berechtigt zur Bewachung ohne erweiterte Tätigkeiten.',
                'category' => 'bewachv_34a',
                'requires_renewal' => false,
                'renewal_period_months' => null,
                'is_mandatory' => false,
                'is_system_qualification' => true,
                'sort_order' => 10,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => null,
                'name' => '§34a Sachkundeprüfung (IHK)',
                'description' => 'IHK Sachkundeprüfung nach §34a GewO. Höhere Qualifikation für verantwortungsvolle Tätigkeiten (z.B. Veranstaltungen, Verkehrsflächen).',
                'category' => 'bewachv_34a',
                'requires_renewal' => false,
                'renewal_period_months' => null,
                'is_mandatory' => false,
                'is_system_qualification' => true,
                'sort_order' => 20,
            ],

            // IHK Education - Alternative qualifications to §34a
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => null,
                'name' => 'Servicekraft für Schutz und Sicherheit (IHK)',
                'description' => 'IHK Grundausbildung (2 Jahre). Schließt §34a-Qualifikation mit ein.',
                'category' => 'education',
                'requires_renewal' => false,
                'renewal_period_months' => null,
                'is_mandatory' => false,
                'is_system_qualification' => true,
                'sort_order' => 30,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => null,
                'name' => 'Fachkraft für Schutz und Sicherheit (IHK)',
                'description' => 'IHK Fachausbildung (3 Jahre). Schließt §34a-Qualifikation mit ein.',
                'category' => 'education',
                'requires_renewal' => false,
                'renewal_period_months' => null,
                'is_mandatory' => false,
                'is_system_qualification' => true,
                'sort_order' => 40,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => null,
                'name' => 'Geprüfte Schutz- und Sicherheitskraft (GSSK)',
                'description' => 'IHK Spezialistenausbildung. Schließt §34a-Qualifikation mit ein.',
                'category' => 'education',
                'requires_renewal' => false,
                'renewal_period_months' => null,
                'is_mandatory' => false,
                'is_system_qualification' => true,
                'sort_order' => 50,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => null,
                'name' => 'Meister für Schutz und Sicherheit (IHK)',
                'description' => 'IHK Meisterausbildung. Höchste Qualifikation, schließt §34a-Qualifikation mit ein.',
                'category' => 'education',
                'requires_renewal' => false,
                'renewal_period_months' => null,
                'is_mandatory' => false,
                'is_system_qualification' => true,
                'sort_order' => 60,
            ],

            // First Aid
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => null,
                'name' => 'Betrieblicher Ersthelfer',
                'description' => 'Betriebliche Ersthelfer-Ausbildung (9 UE). Gültigkeit: 2 Jahre (Fortbildung alle 2 Jahre).',
                'category' => 'first_aid',
                'requires_renewal' => true,
                'renewal_period_months' => 24, // 2 years
                'is_mandatory' => true, // Often required
                'is_system_qualification' => true,
                'sort_order' => 70,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => null,
                'name' => 'Betriebssanitäter',
                'description' => 'Betriebssanitäter-Ausbildung (63 UE Grundlehrgang). Gültigkeit: 3 Jahre (Fortbildung 16 UE alle 3 Jahre).',
                'category' => 'first_aid',
                'requires_renewal' => true,
                'renewal_period_months' => 36, // 3 years
                'is_mandatory' => false,
                'is_system_qualification' => true,
                'sort_order' => 80,
            ],

            // Fire Safety
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => null,
                'name' => 'Brandschutzhelfer',
                'description' => 'Ausbildung zum Brandschutzhelfer. Empfohlene Wiederholung: alle 3 Jahre.',
                'category' => 'fire_safety',
                'requires_renewal' => true,
                'renewal_period_months' => 36, // 3 years (recommended)
                'is_mandatory' => false,
                'is_system_qualification' => true,
                'sort_order' => 90,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => null,
                'name' => 'Evakuierungshelfer',
                'description' => 'Ausbildung zum Evakuierungshelfer. Empfohlene Wiederholung: alle 3 Jahre.',
                'category' => 'fire_safety',
                'requires_renewal' => true,
                'renewal_period_months' => 36,
                'is_mandatory' => false,
                'is_system_qualification' => true,
                'sort_order' => 100,
            ],

            // Safety Officer
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => null,
                'name' => 'Sicherheitsbeauftragter',
                'description' => 'Ausbildung zum Sicherheitsbeauftragten nach DGUV Vorschrift 1.',
                'category' => 'safety_officer',
                'requires_renewal' => false,
                'renewal_period_months' => null,
                'is_mandatory' => false,
                'is_system_qualification' => true,
                'sort_order' => 110,
            ],

            // Specialized
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => null,
                'name' => 'Diensthundeführer',
                'description' => 'Ausbildung zum Hundeführer im Sicherheitsdienst.',
                'category' => 'specialized',
                'requires_renewal' => false,
                'renewal_period_months' => null,
                'is_mandatory' => false,
                'is_system_qualification' => true,
                'sort_order' => 120,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => null,
                'name' => 'Waffensachkundenachweis',
                'description' => 'Sachkundenachweis für den Umgang mit Waffen nach §7 WaffG.',
                'category' => 'specialized',
                'requires_renewal' => false,
                'renewal_period_months' => null,
                'is_mandatory' => false,
                'is_system_qualification' => true,
                'sort_order' => 130,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => null,
                'name' => 'Interventionsdienst',
                'description' => 'Qualifikation für Interventionsdienste (Alarmreaktion).',
                'category' => 'specialized',
                'requires_renewal' => false,
                'renewal_period_months' => null,
                'is_mandatory' => false,
                'is_system_qualification' => true,
                'sort_order' => 140,
            ],
        ];

        // Add timestamps
        $now = now();
        foreach ($systemQualifications as &$qualification) {
            $qualification['created_at'] = $now;
            $qualification['updated_at'] = $now;
        }

        // Insert all qualifications
        DB::table('qualifications')->insert($systemQualifications);

        $this->command->info('Successfully seeded 14 system qualifications.');
    }
}
