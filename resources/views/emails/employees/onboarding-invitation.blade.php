{{-- SPDX-FileCopyrightText: 2025-2026 SecPal Contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}

<x-mail::message>
# {{ __('Welcome to :app_name!', ['app_name' => config('app.name')]) }}

{{ __('Hello :first_name,', ['first_name' => $employee->first_name]) }}

{{ __('we are excited to welcome you to our team soon!') }}

{{ __('Your contract starts on **:date**.', ['date' => $employee->contract_start_date->format('d.m.Y')]) }}

{{ __('To prepare for your first day, please complete the onboarding process in our portal:') }}

<x-mail::button :url="$onboardingUrl">
{{ __('Start Onboarding') }}
</x-mail::button>

## {{ __('What to expect:') }}

### {{ __('Required steps') }}

- {{ __('Personal information, including gender and previous names') }}
- {{ __('Residential address history covering the last five years') }}
- {{ __('Nationalities plus residence and work authorization details when applicable') }}
- {{ __('Tax identification number and social security details') }}

### {{ __('Optional steps') }}

- {{ __('Bank account details for salary payment') }}
- {{ __('Emergency contacts') }}
- {{ __('Qualifications and certificates you want HR to review early') }}

### {{ __('Supporting documents') }}

- {{ __('Upload identity, residence, banking, or qualification documents where requested.') }}
- {{ __('Review and confirm your information before you submit onboarding.') }}

{{ __('**Important:** Please complete the onboarding by **:deadline** at the latest.', ['deadline' => $employee->contract_start_date->copy()->subDays(3)->format('d.m.Y')]) }}

{{ __('If you have any questions, please contact us at :email.', ['email' => config('mail.from.address')]) }}

{{ __('Best regards,') }}
{{ __('Your HR Team') }}

---

{{ __('This email was generated automatically. Please do not reply directly to this email.') }}
</x-mail::message>
