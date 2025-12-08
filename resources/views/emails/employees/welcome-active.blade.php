{{-- SPDX-FileCopyrightText: 2025 SecPal Contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}

<x-mail::message>
# Willkommen im Team!

Hallo {{ $employee->first_name }},

herzlich willkommen bei {{ config('app.name') }}! Wir freuen uns, dass Sie heute Ihren ersten Arbeitstag bei uns beginnen.

## Ihr erster Tag

**Position:** {{ $employee->position }}
**Organisationseinheit:** {{ $employee->organizationalUnit->name ?? 'Nicht zugeordnet' }}
**Mitarbeiternummer:** {{ $employee->employee_number }}

## Wichtige Informationen

Ihr Zugang zu unserem System wurde aktiviert. Sie können sich jetzt mit Ihrer E-Mail-Adresse ({{ $employee->email }}) anmelden.

<x-mail::button :url="config('app.frontend_url')">
Zum Portal
</x-mail::button>

## Erste Schritte

1. Machen Sie sich mit unserem Portal vertraut
2. Überprüfen Sie Ihre persönlichen Daten
3. Laden Sie ggf. noch fehlende Dokumente hoch
4. Kontaktieren Sie Ihren Vorgesetzten bei Fragen

Bei Fragen erreichen Sie uns unter {{ config('mail.from.address') }}.

Viel Erfolg und einen guten Start!
Ihr HR-Team

---

Diese E-Mail wurde automatisch generiert.
</x-mail::message>
