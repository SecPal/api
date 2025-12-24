<!--
SPDX-FileCopyrightText: 2025 SecPal Contributors
SPDX-License-Identifier: CC-BY-4.0
-->

# Queue Worker Setup Guide

**Issue #408:** Queue-based activity hash chain building requires queue workers running in production.

## Overview

SecPal uses Laravel queues for asynchronous processing of:

1. **activity-hash-chain**: Hash chain building (race-condition-free)
2. **merkle**: Merkle tree batching (hierarchical verification)
3. **opentimestamp**: OpenTimestamp submission/upgrade (blockchain anchoring)
4. **default**: General background tasks

## Queue Configuration

**phpunit.xml (Testing):**

```xml
<env name="QUEUE_CONNECTION" value="sync"/>
```

- Tests use `sync` driver (immediate execution)
- ProcessActivityHashChain uses `dispatchSync()` in test context
- No queue worker needed for tests

**config/queue.php (Production):**

```php
'default' => env('QUEUE_CONNECTION', 'database'),

'connections' => [
    'database' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'after_commit' => true, // Dispatch jobs after DB transaction commits
    ],
],
```

## Development Setup (DDEV)

**Local testing with queue worker:**

```bash
# Terminal 1: Start DDEV
ddev start

# Terminal 2: Run queue worker inside container
ddev exec php artisan queue:work --queue=activity-hash-chain,merkle,opentimestamp,default --verbose

# Terminal 3: Run application/tests
ddev exec vendor/bin/pest
```

**Monitor queue:**

```bash
# Check pending jobs
ddev exec php artisan queue:monitor database:activity-hash-chain,database:merkle

# Clear failed jobs
ddev exec php artisan queue:flush

# Retry failed jobs
ddev exec php artisan queue:retry all
```

## Production Setup

### Option 1: Supervisor (Recommended)

**Install supervisor:**

```bash
apt-get install supervisor
```

**Create /etc/supervisor/conf.d/secpal-queue-worker.conf:**

```ini
[program:secpal-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/secpal/api/artisan queue:work --queue=activity-hash-chain,merkle,opentimestamp,default --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/secpal/storage/logs/queue-worker.log
stopwaitsecs=3600
```

**Start queue worker:**

```bash
# Reload supervisor configuration
supervisorctl reread
supervisorctl update

# Start workers
supervisorctl start secpal-queue-worker:*

# Check status
supervisorctl status secpal-queue-worker:*
```

### Option 2: Systemd

**Create /etc/systemd/system/secpal-queue-worker.service:**

```ini
[Unit]
Description=SecPal Queue Worker
After=network.target postgresql.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/secpal/api
ExecStart=/usr/bin/php artisan queue:work --queue=activity-hash-chain,merkle,opentimestamp,default --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5s

[Install]
WantedBy=multi-user.target
```

**Enable and start service:**

```bash
# Reload systemd daemon
systemctl daemon-reload

# Enable auto-start on boot
systemctl enable secpal-queue-worker

# Start service
systemctl start secpal-queue-worker

# Check status
systemctl status secpal-queue-worker

# View logs
journalctl -u secpal-queue-worker -f
```

## Queue Worker Parameters

**Command anatomy:**

```bash
php artisan queue:work \
    --queue=activity-hash-chain,merkle,opentimestamp,default \  # Queue priority order
    --sleep=3 \            # Seconds to wait when queue is empty
    --tries=3 \            # Number of retry attempts
    --max-time=3600 \      # Max execution time per worker (1 hour)
    --timeout=60 \         # Max execution time per job
    --memory=256 \         # Max memory usage (MB)
    --stop-when-empty      # Stop worker when queue is empty (for testing)
```

**Queue priority:**

- `activity-hash-chain`: Highest priority (forensic integrity)
- `merkle`: Medium priority (batching can wait)
- `opentimestamp`: Low priority (blockchain submission not time-critical)
- `default`: Lowest priority (general background tasks)

## Monitoring & Maintenance

### Health Checks

**Monitor queue length:**

```bash
php artisan queue:monitor database:activity-hash-chain,database:merkle
```

**Check failed jobs:**

```bash
php artisan queue:failed
```

**Retry failed jobs:**

```bash
php artisan queue:retry all
php artisan queue:retry 42  # Retry specific job ID
```

**Clear failed jobs:**

```bash
php artisan queue:flush  # Clear all failed jobs
php artisan queue:forget 42  # Forget specific job ID
```

### Performance Tuning

**Multiple workers (high load):**

```ini
# Supervisor: numprocs=4 (4 parallel workers)
# Systemd: Create 4 separate service files
```

**Dedicated queue workers:**

```bash
# Worker 1: Critical forensic queues only
php artisan queue:work --queue=activity-hash-chain,merkle

# Worker 2: Non-critical queues
php artisan queue:work --queue=opentimestamp,default
```

**Memory management:**

```bash
# Restart worker after processing 1000 jobs (prevent memory leaks)
php artisan queue:work --max-jobs=1000

# Restart worker after 1 hour
php artisan queue:work --max-time=3600
```

### Logging

**Laravel logs:**

```bash
tail -f storage/logs/laravel.log | grep ProcessActivityHashChain
```

**Queue worker logs (Supervisor):**

```bash
tail -f storage/logs/queue-worker.log
```

**Queue worker logs (Systemd):**

```bash
journalctl -u secpal-queue-worker -f
```

## Deployment Checklist

- [ ] Queue worker process configured (supervisor/systemd)
- [ ] Queue worker auto-starts on boot (enabled)
- [ ] Log rotation configured (logrotate)
- [ ] Monitoring alerts configured (queue length, failed jobs)
- [ ] Failed job notification (email/Slack)
- [ ] Database migrations run (event_hash nullable)
- [ ] QUEUE_CONNECTION=database in .env
- [ ] Queue worker restarted after deployment

## Troubleshooting

### Queue worker not processing jobs

**Check worker status:**

```bash
# Supervisor
supervisorctl status secpal-queue-worker:*

# Systemd
systemctl status secpal-queue-worker
```

**Check database:**

```sql
-- Check pending jobs
SELECT * FROM jobs ORDER BY created_at DESC LIMIT 10;

-- Check failed jobs
SELECT * FROM failed_jobs ORDER BY failed_at DESC LIMIT 10;
```

**Restart worker:**

```bash
# Supervisor
supervisorctl restart secpal-queue-worker:*

# Systemd
systemctl restart secpal-queue-worker
```

### Jobs failing repeatedly

**Check error logs:**

```bash
# Laravel logs
tail -100 storage/logs/laravel.log | grep ERROR

# Failed jobs table
php artisan queue:failed
```

**Common issues:**

- Database connection timeout (increase --timeout)
- Memory limit exceeded (increase --memory)
- Job logic error (check job code)
- Database lock timeout (check ProcessActivityHashChain transaction logic)

### High queue latency

**Check queue length:**

```bash
php artisan queue:monitor database:activity-hash-chain
```

**Solutions:**

- Increase number of workers (numprocs=4)
- Add dedicated workers for critical queues
- Optimize job processing time (check DB queries)
- Scale horizontally (multiple servers)

## Testing Queue Worker

**Local testing:**

```bash
# Terminal 1: Start worker
ddev exec php artisan queue:work --verbose

# Terminal 2: Dispatch test job
ddev exec php artisan tinker
>>> \App\Jobs\ProcessActivityHashChain::dispatch(1, ['id' => 1, 'tenant_id' => 1]);

# Observe job execution in Terminal 1
```

**Performance testing:**

```bash
# Run performance tests (validates 134 logs/sec throughput)
ddev exec vendor/bin/pest tests/Performance/ActivityHashChainConcurrencyTest.php
```

## References

- [Laravel Queues Documentation](https://laravel.com/docs/11.x/queues)
- [Supervisor Documentation](http://supervisord.org/)
- [Systemd Service Documentation](https://www.freedesktop.org/software/systemd/man/systemd.service.html)
- Issue #408: Queue-based activity hash chain building
- Epic #385: BewachV compliance implementation
