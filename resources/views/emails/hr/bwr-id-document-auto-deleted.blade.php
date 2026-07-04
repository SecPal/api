{{-- SPDX-FileCopyrightText: 2026 SecPal Contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution --}}

@php($frontendUrl = rtrim((string) config('app.frontend_url', ''), '/'))

<x-mail::message>
# {{ __('ID Document Copy Deleted After BWR Approval') }}

{{ __('The stored ID document copy for the following employee was deleted automatically because BWR approval made continued storage no longer necessary.') }}

## {{ __('Employee Information') }}

- **{{ __('Name') }}:** {{ $employee->full_name }}
- **{{ __('Employee Number') }}:** {{ $employee->employee_number }}
- **{{ __('Email') }}:** {{ $employee->email ?? __('Not set') }}
- **{{ __('BWR ID') }}:** {{ $employee->bwr_id ?? __('Not set') }}
- **{{ __('BWR Status') }}:** {{ ucfirst($employee->bwr_status) }}

## {{ __('Deletion Reason') }}

{{ $deletionReason }}

**{{ __('Legal Basis') }}:** {{ $legalBasis }}

@if ($frontendUrl)
<x-mail::button :url="$frontendUrl . '/employees/' . $employee->id">
{{ __('View Employee Details') }}
</x-mail::button>
@endif

---

{{ __('This email was generated automatically. Please do not reply directly to this email.') }}
</x-mail::message>
