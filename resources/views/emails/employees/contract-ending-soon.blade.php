{{-- SPDX-FileCopyrightText: 2025 SecPal Contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}

<x-mail::message>
# Ihr Vertrag endet in Kürze

Hallo {{ $employee->first_name }},

wir möchten Sie daran erinnern, dass Ihr Arbeitsvertrag in Kürze endet.

**Vertragsende:** {{ $employee->termination_date->format('d.m.Y') }}

@if ($employee->last_working_day)
**Letzter Arbeitstag:** {{ $employee->last_working_day->format('d.m.Y') }}
@endif

## Möchten Sie weitermachen?

Falls Sie Interesse an einer Vertragsverlängerung haben, kontaktieren Sie bitte zeitnah Ihren Vorgesetzten oder die Personalabteilung.

## Nächste Schritte

Falls Ihr Vertrag nicht verlängert wird:
- Ihr Systemzugang wird am {{ $employee->termination_date->format('d.m.Y') }} automatisch deaktiviert
- Bitte vereinbaren Sie einen Termin zur Rückgabe von Firmeneigentum
- Wir senden Ihnen Ihr Arbeitszeugnis und weitere Unterlagen zu

Bei Fragen erreichen Sie uns unter {{ config('mail.from.address') }}.

Viele Grüße,
Ihr HR-Team

---

Diese E-Mail wurde automatisch generiert.
</x-mail::message>
