{{--
SPDX-FileCopyrightText: 2025 SecPal Contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}

<x-mail::message>
# {{ __('Reset Your Password') }}

{{ __('Hello') }} {{ $user->name }},

{{ __('You requested a password reset for your SecPal account.') }}

{{ __('Click the button below to reset your password. This link will expire in :minutes minutes.', ['minutes' => $expiresInMinutes]) }}

<x-mail::button :url="$resetUrl">
{{ __('Reset Password') }}
</x-mail::button>

**{{ __('Security Notice:') }}**
- {{ __('Never share this link with anyone') }}
- {{ __('If you didn\'t request this reset, please ignore this email') }}
- {{ __('Your password will remain unchanged until you complete the reset process') }}

{{ __('Thanks') }},<br>
{{ config('app.name') }}

---

<small>
{{ __('If you\'re having trouble clicking the ":button" button, copy and paste the URL below into your web browser:', ['button' => __('Reset Password')]) }}<br>
{{ $resetUrl }}
</small>
</x-mail::message>
