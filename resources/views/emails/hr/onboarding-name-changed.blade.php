{{-- SPDX-FileCopyrightText: 2025 SecPal Contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}

<x-mail::message>
# {{ __('HR Alert: Name Change During Onboarding') }}

{{ __('An employee has changed their name during onboarding completion.') }}

## {{ __('Employee Information') }}

- **{{ __('Email') }}:** {{ $employee->email }}
- **{{ __('Status') }}:** {{ ucfirst($employee->status) }}
- **{{ __('Contract Start') }}:** {{ $employee->contract_start_date?->format('d.m.Y') ?? __('Not set') }}

## {{ __('Name Changes') }}

@if ($firstNameChanged)
### {{ __('First Name') }}

- **{{ __('Original') }}:** {{ $oldFirstName }}
- **{{ __('Changed to') }}:** {{ $newFirstName }}
- **{{ __('Similarity') }}:** {{ number_format($firstNameSimilarity, 1) }}%
- **{{ __('Severity') }}:**
  @if ($firstNameSeverity === 'minor')
    ✅ {{ __('Minor (likely typo correction)') }}
  @elseif ($firstNameSeverity === 'medium')
    ⚠️ {{ __('Medium (significant change - please verify)') }}
  @else
    🚨 {{ __('Major (fundamental change)') }}
  @endif
@endif

@if ($lastNameChanged)
### {{ __('Last Name') }}

- **{{ __('Original') }}:** {{ $oldLastName }}
- **{{ __('Changed to') }}:** {{ $newLastName }}
- **{{ __('Similarity') }}:** {{ number_format($lastNameSimilarity, 1) }}%
- **{{ __('Severity') }}:**
  @if ($lastNameSeverity === 'minor')
    ✅ {{ __('Minor (likely typo correction)') }}
  @elseif ($lastNameSeverity === 'medium')
    ⚠️ {{ __('Medium (significant change - please verify)') }}
  @else
    🚨 {{ __('Major (fundamental change)') }}
  @endif
@endif

## {{ __('Action Required') }}

@if ($firstNameSeverity === 'medium' || $lastNameSeverity === 'medium')
⚠️ **{{ __('Medium severity change detected. Please verify with employee and update personnel files if legitimate.') }}**
@else
✅ **{{ __('Minor change detected. Review activity log if needed.') }}**
@endif

<x-mail::button :url="url('/admin/employees/' . $employee->id)">
{{ __('View Employee Details') }}
</x-mail::button>

## {{ __('Security Notice') }}

{{ __('This change was logged in the activity log with IP address and user agent for audit purposes.') }}

{{ __('If this change appears suspicious, please contact the employee immediately to verify their identity.') }}

---

{{ __('This email was generated automatically. Please do not reply directly to this email.') }}
</x-mail::message>
