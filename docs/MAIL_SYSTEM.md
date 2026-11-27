<!--
SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Mail System Documentation

Laravel + Mailpit Email Testing

## Overview

SecPal uses Laravel's Mail system with Mailpit for local email testing.

## Development Environment

- **Service**: Mailpit local user service on the server
- **SMTP Listener**: `127.0.0.1:1025`
- **UI Listener**: `127.0.0.1:8025` (local-only unless securely proxied)
- **Configuration**: See `.env.example`

Mailpit runs as a native local service. Keep the UI local-only by default and use an SSH tunnel or an authenticated reverse proxy if remote access is required.

## Configuration

```env
MAIL_MAILER=smtp
MAIL_HOST=localhost
MAIL_PORT=1025
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="noreply@secpal.app"
MAIL_FROM_NAME="${APP_NAME}"
```

## Identity mail

Password resets, active email verification and pending email changes dispatch
`DeliverGlobalIdentityOperation` with the operation ID after database commit.
The worker resolves the exact address and token only for the committed delivery
attempt. The application-origin link carries the operation ID and verifier in
its fragment. Submit the verifier in the request body to the corresponding
identity-operation endpoint.

Set `GLOBAL_IDENTITY_MAILER` to a confined SMTP mailer for private previews.
Identity mail excludes logging and log-based failover transports. Subjects,
application logs, failed jobs and queue payloads contain no addresses, tokens,
or full delivery URLs. Account notices queue employee and User references and
resolve the address at the final delivery boundary.

Use `Queue::fake()` to verify the operation ID on dispatch. Use a synthetic
transport to exercise ambiguous handoff, redaction and lifecycle fencing.

### Manual Testing with Mailpit

1. Confirm Mailpit is reachable on the server: `curl http://127.0.0.1:8025`
2. If you need browser access from another machine, tunnel the UI locally: `ssh -L 8025:127.0.0.1:8025 <user>@<server>`
3. Open Mailpit UI: <http://127.0.0.1:8025>
4. Trigger email in your application
5. Check Mailpit UI for received email
6. Verify:
   - Subject line (no PII)
   - Links work correctly
   - Template renders properly
   - Expiry times are correct

## Queue Workers

Emails are queued to the `database` queue driver by default.

### Development

```bash
# Run queue worker
php artisan queue:work

# Process one job and stop
php artisan queue:work --once

# Process jobs for specific queue
php artisan queue:work --queue=emails
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

1. Check Mailpit is accessible on the server: `curl http://127.0.0.1:8025`
2. Check mail config: `php artisan config:show mail`
3. Check queue: `php artisan queue:work --once`
4. If you are connecting from another machine, confirm your SSH tunnel or reverse proxy still targets `127.0.0.1:8025`

### Emails sent immediately instead of queued

- Verify `Mail::queue()` is used (not `Mail::send()`)
- Check Mailable uses `Queueable` trait
- Check `QUEUE_CONNECTION` in `.env` (should be `database`)

### Queue jobs failing

```bash
# Check failed jobs
php artisan queue:failed

# Retry failed job
php artisan queue:retry <job-id>

# Retry all failed jobs
php artisan queue:retry all

# Clear failed jobs
php artisan queue:flush
```

## Related Documentation

- [Laravel Mail Documentation](https://laravel.com/docs/13.x/mail)
- [Mailpit Documentation](https://mailpit.axllent.org/)
