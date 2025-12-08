{{-- SPDX-FileCopyrightText: 2025 SecPal Contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}

<x-mail::message>
# Qualifikation läuft ab

Hallo {{ $employee->first_name }},

Ihre Qualifikation **{{ $qualification->qualification->name }}** läuft in Kürze ab.

**Ablaufdatum:** {{ $qualification->expiry_date->format('d.m.Y') }}

@if ($qualification->certificate_number)
**Zertifikatsnummer:** {{ $qualification->certificate_number }}
@endif

@if ($qualification->issuing_authority)
**Ausstellende Stelle:** {{ $qualification->issuing_authority }}
@endif

## Verlängerung erforderlich

Bitte kümmern Sie sich rechtzeitig um die Verlängerung bzw. Auffrischung dieser Qualifikation.

### Nächste Schritte

1. Termin für Auffrischungskurs vereinbaren
2. Kurs absolvieren und neues Zertifikat erhalten
3. Neues Zertifikat in unserem Portal hochladen

**Wichtig:** Ohne gültige Qualifikation können bestimmte Tätigkeiten nicht ausgeübt werden.

Bei Fragen zur Verlängerung oder Kostenübernahme erreichen Sie uns unter {{ config('mail.from.address') }}.

Viele Grüße,  
Ihr HR-Team

---

Diese E-Mail wurde automatisch generiert.
</x-mail::message>
