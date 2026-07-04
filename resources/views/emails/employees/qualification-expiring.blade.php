{{-- SPDX-FileCopyrightText: 2025 SecPal Contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution --}}

<x-mail::message>
# {{ __('Qualification Expiring Soon') }}

{{ __('Hello :first_name,', ['first_name' => $employee->first_name]) }}

{{ __('Your qualification **:qualification_name** expires soon.', ['qualification_name' => $qualification->qualification->name]) }}

**{{ __('Expiry Date') }}:** {{ $qualification->expiry_date->format('d.m.Y') }}

@if ($qualification->certificate_number)
**{{ __('Certificate Number') }}:** {{ $qualification->certificate_number }}
@endif

@if ($qualification->issuing_authority)
**{{ __('Issuing Authority') }}:** {{ $qualification->issuing_authority }}
@endif

## {{ __('Renewal Required') }}

{{ __('Please take care of renewing or refreshing this qualification in a timely manner.') }}

### {{ __('Next Steps') }}

1. {{ __('Schedule a refresher course') }}
2. {{ __('Complete the course and obtain a new certificate') }}
3. {{ __('Upload the new certificate to our portal') }}

{{ __('**Important:** Without a valid qualification, certain activities cannot be performed.') }}

{{ __('If you have questions about renewal or cost coverage, please contact us at :email.', ['email' => config('mail.from.address')]) }}

{{ __('Best regards,') }}
{{ __('Your HR Team') }}

---

{{ __('This email was generated automatically.') }}
</x-mail::message>
