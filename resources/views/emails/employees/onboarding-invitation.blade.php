{{-- SPDX-FileCopyrightText: 2025 SecPal Contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}

<x-mail::message>
# Willkommen bei {{ config('app.name') }}!

Hallo {{ $employee->first_name }},

wir freuen uns, Sie bald in unserem Team begrüßen zu dürfen!

Ihr Vertragsbeginn ist am **{{ $employee->contract_start_date->format('d.m.Y') }}**.

Um Ihren ersten Arbeitstag vorzubereiten, bitten wir Sie, das Onboarding in unserem Portal abzuschließen:

<x-mail::button :url="$resetUrl">
Onboarding starten
</x-mail::button>

## Was Sie erwartet:

- Personalfragebogen ausfüllen
- Bankverbindung hinterlegen
- Notfallkontakt angeben
- Arbeitsvertrag digital unterschreiben
- Zertifikate hochladen (falls vorhanden)

**Wichtig:** Bitte schließen Sie das Onboarding bis spätestens **{{ $employee->contract_start_date->subDays(3)->format('d.m.Y') }}** ab.

Bei Fragen erreichen Sie uns unter {{ config('mail.from.address') }}.

Viele Grüße,
Ihr HR-Team

---

Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht direkt auf diese E-Mail.
</x-mail::message>
