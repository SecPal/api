<!--
SPDX-FileCopyrightText: 2025 SecPal
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Mail System Documentation

Laravel + Mailpit Development Environment

## Overview

SecPal uses Laravel's Mail system with Mailpit for local email testing. All emails are queued for asynchronous dispatch to prevent blocking user requests.

## Development Environment

- **Service**: Mailpit (integrated with DDEV)
- **UI Access**: <http://localhost:8026>
- **SMTP Port**: 1025 (localhost)
- **Configuration**: See `.env.example`

No additional setup required - Mailpit runs automatically with DDEV.

## Configuration

```env
MAIL_MAILER=smtp
MAIL_HOST=localhost
MAIL_PORT=1025
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="noreply@secpal.app"
MAIL_FROM_NAME="${APP_NAME}"
```

## Security Rules

1. **Never include sensitive tokens in email subjects** (logs may capture subjects)
2. **Always queue emails** (never send immediately - use `Mail::queue()`)
3. **URL-encode all query parameters** (emails, tokens, etc.)
4. **Include expiry warnings** for time-sensitive links
5. **No PII in logs** (tokens, emails, phone numbers)

## Creating a Mailable

**Location**: `app/Mail/`

**Example**: `app/Mail/PasswordResetMail.php`

```php
<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public User $user,
        public string $token
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Password Reset Request', // No PII/tokens here!
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        // Build URL with proper encoding
        $resetUrl = url('/reset-password?token=' . urlencode($this->token) . '&email=' . urlencode($this->user->email));

        return new Content(
            markdown: 'emails.password-reset',
            with: [
                'user' => $this->user,
                'resetUrl' => $resetUrl,
                'expiresInMinutes' => 60, // Match controller constant
            ]
        );
    }
}
```

## Email Templates

**Location**: `resources/views/emails/`

**Example**: `resources/views/emails/password-reset.blade.php`

```blade
@component('mail::message')
# Password Reset Request

Hello {{ $user->name }},

We received a request to reset your password for your SecPal account.

@component('mail::button', ['url' => $resetUrl])
Reset Password
@endcomponent

**This link will expire in {{ $expiresInMinutes }} minutes.**

If you didn't request a password reset, please ignore this email. Your password will not be changed.

For security reasons, never share this email or link with anyone.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
```

## Dispatching Emails

### Queue (Asynchronous - REQUIRED)

```php
use App\Mail\PasswordResetMail;
use Illuminate\Support\Facades\Mail;

// In controller
Mail::to($user)->queue(new PasswordResetMail($user, $token));
```

### Immediate (NOT RECOMMENDED)

```php
// Only for critical system notifications
Mail::to($user)->send(new PasswordResetMail($user, $token));
```

## Testing

### Using Mail::fake()

```php
use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

test('password reset email is queued', function () {
    Mail::fake(); // Intercept all mail

    $user = User::factory()->create(['email_plain' => 'test@example.com']);

    // Trigger action that sends email
    $this->postJson('/api/auth/password/reset-request', [
        'email' => 'test@example.com'
    ]);

    // Assert email was queued (not sent immediately)
    Mail::assertQueued(PasswordResetMail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });
});

test('email contains valid reset URL', function () {
    Mail::fake();

    $user = User::factory()->create(['email_plain' => 'test@example.com']);

    $this->postJson('/api/auth/password/reset-request', [
        'email' => 'test@example.com'
    ]);

    Mail::assertQueued(PasswordResetMail::class, function ($mail) {
        // Access Mailable's public properties
        expect($mail->token)->toBeString()->not->toBeEmpty();
        return true;
    });
});

test('email subject contains no PII', function () {
    Mail::fake();

    $user = User::factory()->create(['email_plain' => 'test@example.com']);

    $this->postJson('/api/auth/password/reset-request', [
        'email' => 'test@example.com'
    ]);

    Mail::assertQueued(PasswordResetMail::class, function ($mail) use ($user) {
        $envelope = $mail->envelope();

        // Subject must not contain email, token, or username
        expect($envelope->subject)
            ->not->toContain($user->email)
            ->not->toContain($mail->token)
            ->not->toContain($user->name);

        return true;
    });
});
```

### Manual Testing with Mailpit

1. Start DDEV: `ddev start`
2. Open Mailpit UI: <http://localhost:8026>
3. Trigger email in your application
4. Check Mailpit UI for received email
5. Verify:
   - Subject line (no PII)
   - Links work correctly
   - Template renders properly
   - Expiry times are correct

## Queue Workers

Emails are queued to the `database` queue driver by default.

### Development

```bash
# Run queue worker
ddev exec php artisan queue:work

# Process one job and stop
ddev exec php artisan queue:work --once

# Process jobs for specific queue
ddev exec php artisan queue:work --queue=emails
```

### Production

Configure supervisor or systemd to run queue workers:

```ini
[program:secpal-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/worker.log
stopwaitsecs=3600
```

## Troubleshooting

### Emails not appearing in Mailpit

1. Check DDEV is running: `ddev status`
2. Check Mailpit is accessible: `curl http://localhost:8026`
3. Check mail config: `ddev exec php artisan config:show mail`
4. Check queue: `ddev exec php artisan queue:work --once`

### Emails sent immediately instead of queued

- Verify `Mail::queue()` is used (not `Mail::send()`)
- Check Mailable uses `Queueable` trait
- Check `QUEUE_CONNECTION` in `.env` (should be `database`)

### Queue jobs failing

```bash
# Check failed jobs
ddev exec php artisan queue:failed

# Retry failed job
ddev exec php artisan queue:retry <job-id>

# Retry all failed jobs
ddev exec php artisan queue:retry all

# Clear failed jobs
ddev exec php artisan queue:flush
```

## Related Documentation

- [Laravel Mail Documentation](https://laravel.com/docs/13.x/mail)
- [Mailpit Documentation](https://mailpit.axllent.org/)
- [Production Test Phase 2](PRODUCTION_TEST_PHASE2_EMAIL.md) - Email feature implementation report
