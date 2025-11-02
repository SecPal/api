{{--
SPDX-FileCopyrightText: 2025 SecPal Contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}

<x-mail::message>
# Reset Your Password

Hello {{ $user->name }},

You requested a password reset for your SecPal account.

Click the button below to reset your password. This link will expire in **{{ $expiresInMinutes }} minutes**.

<x-mail::button :url="$resetUrl">
Reset Password
</x-mail::button>

**Security Notice:**
- Never share this link with anyone
- If you didn't request this reset, please ignore this email
- Your password will remain unchanged until you complete the reset process

Thanks,<br>
{{ config('app.name') }}

---

<small>
If you're having trouble clicking the "Reset Password" button, copy and paste the URL below into your web browser:<br>
{{ $resetUrl }}
</small>
</x-mail::message>
