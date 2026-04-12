{{-- SPDX-FileCopyrightText: 2026 SecPal Contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}

@php
    $headline = match ($severity) {
        'expired' => __('Compliance Documents Expired'),
        'critical' => __('Compliance Documents Need Immediate Attention'),
        default => __('Compliance Documents Expire Soon'),
    };

    $intro = match ($severity) {
        'expired' => __('One or more of your required compliance documents have already expired.'),
        'critical' => __('One or more of your required compliance documents are about to expire within the next 7 days.'),
        default => __('One or more of your required compliance documents will expire within the next 30 days.'),
    };
@endphp

<x-mail::message>
# {{ $headline }}

{{ __('Hello :first_name,', ['first_name' => $employee->first_name]) }}

{{ $intro }}

<x-mail::panel>
@foreach ($documents as $document)
**{{ $document['label'] }}**
{{ __('Expiry Date') }}: {{ \Illuminate\Support\Carbon::parse($document['expiry'])->format('d.m.Y') }}
{{ __('Status') }}: {{ ucfirst($document['status']) }}

@if (! $loop->last)

@endif
@endforeach
</x-mail::panel>

## {{ __('Next Steps') }}

{{ __('Please renew the listed documents as soon as possible and inform HR once the updated evidence is available.') }}

{{ __('If you need support, please contact us at :email.', ['email' => config('mail.from.address')]) }}

{{ __('Best regards,') }}
{{ __('Your HR Team') }}

---

{{ __('This email was generated automatically.') }}
</x-mail::message>
