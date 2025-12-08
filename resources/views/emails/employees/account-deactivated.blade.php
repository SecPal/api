{{-- SPDX-FileCopyrightText: 2025 SecPal Contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}

<x-mail::message>
# Konto deaktiviert

Hallo {{ $employee->first_name }},

Ihr Benutzerkonto wurde deaktiviert.

@if ($employee->termination_date)
**Letzter Arbeitstag:** {{ $employee->termination_date->format('d.m.Y') }}
@endif

@if ($employee->last_working_day)
**Letzter Anwesenheitstag:** {{ $employee->last_working_day->format('d.m.Y') }}
@endif

## Was bedeutet das?

- Sie können sich nicht mehr am System anmelden
- Alle aktiven Sitzungen wurden beendet
- Ihr Zugang zu Firmendaten wurde gesperrt

## Wichtige Hinweise

Bitte denken Sie daran:
- Rückgabe aller Firmeneigentums (Schlüssel, Ausweise, Geräte)
- Vernichtung vertraulicher Unterlagen
- Einhaltung der Verschwiegenheitspflicht

Bei Fragen zur Vertragsbeendigung oder zu offenen Fragen erreichen Sie uns unter {{ config('mail.from.address') }}.

Wir danken Ihnen für Ihre Mitarbeit und wünschen Ihnen alles Gute für die Zukunft.

Ihr HR-Team

---

Diese E-Mail wurde automatisch generiert.
</x-mail::message>
