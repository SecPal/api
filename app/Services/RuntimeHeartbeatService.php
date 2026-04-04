<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class RuntimeHeartbeatService
{
    private const string SCHEDULER_CACHE_KEY = 'runtime-heartbeats:scheduler';

    private const string DEFAULT_QUEUE_GROUP = 'default';

    private const string DEFAULT_QUEUE_CACHE_KEY = 'runtime-heartbeats:queue-worker:default';

    private const string FORENSICS_QUEUE_GROUP = 'forensics';

    private const string FORENSICS_QUEUE_CACHE_KEY = 'runtime-heartbeats:queue-worker:forensics';

    private const int SCHEDULER_STALE_AFTER_SECONDS = 180;

    private const int QUEUE_WORKER_STALE_AFTER_SECONDS = 900;

    /**
     * @var list<string>
     */
    private const array FORENSICS_QUEUES = [
        'activity-hash-chain',
        'merkle',
        'opentimestamp',
    ];

    public function recordSchedulerHeartbeat(?CarbonInterface $timestamp = null): void
    {
        $this->storeHeartbeat(self::SCHEDULER_CACHE_KEY, $timestamp ?? now());
    }

    public function recordQueueHeartbeat(?string $queueName, ?CarbonInterface $timestamp = null): void
    {
        $queueGroup = $this->queueGroupFor($queueName);

        if ($queueGroup === null) {
            return;
        }

        $this->storeHeartbeat($this->queueCacheKey($queueGroup), $timestamp ?? now());
    }

    /**
     * @return array{status: string, healthy: bool, last_heartbeat_at: ?string, stale_after_seconds: int}
     */
    public function schedulerReadiness(): array
    {
        $lastHeartbeatAt = $this->readHeartbeat(self::SCHEDULER_CACHE_KEY);

        if ($lastHeartbeatAt === null) {
            return [
                'status' => 'missing',
                'healthy' => false,
                'last_heartbeat_at' => null,
                'stale_after_seconds' => self::SCHEDULER_STALE_AFTER_SECONDS,
            ];
        }

        $isFresh = $this->isFresh($lastHeartbeatAt, self::SCHEDULER_STALE_AFTER_SECONDS);

        return [
            'status' => $isFresh ? 'ok' : 'stale',
            'healthy' => $isFresh,
            'last_heartbeat_at' => $lastHeartbeatAt->toIso8601String(),
            'stale_after_seconds' => self::SCHEDULER_STALE_AFTER_SECONDS,
        ];
    }

    /**
     * @return array<string, array{status: string, healthy: bool, last_heartbeat_at: ?string, pending_jobs: int, stale_after_seconds: int}>
     */
    public function queueReadiness(): array
    {
        return [
            'queue_default_worker' => $this->queueReadinessFor(self::DEFAULT_QUEUE_GROUP),
            'queue_forensics_worker' => $this->queueReadinessFor(self::FORENSICS_QUEUE_GROUP),
        ];
    }

    private function storeHeartbeat(string $cacheKey, CarbonInterface $timestamp): void
    {
        Cache::forever($cacheKey, $timestamp->toIso8601String());
    }

    private function queueCacheKey(string $queueGroup): string
    {
        return match ($queueGroup) {
            self::DEFAULT_QUEUE_GROUP => self::DEFAULT_QUEUE_CACHE_KEY,
            self::FORENSICS_QUEUE_GROUP => self::FORENSICS_QUEUE_CACHE_KEY,
            default => throw new \InvalidArgumentException("Unsupported queue group [{$queueGroup}]."),
        };
    }

    private function queueGroupFor(?string $queueName): ?string
    {
        $normalizedQueueName = $queueName ?: $this->defaultQueueName();

        return match (true) {
            $normalizedQueueName === $this->defaultQueueName() => self::DEFAULT_QUEUE_GROUP,
            in_array($normalizedQueueName, self::FORENSICS_QUEUES, true) => self::FORENSICS_QUEUE_GROUP,
            default => null,
        };
    }

    /**
     * @return array{status: string, healthy: bool, last_heartbeat_at: ?string, pending_jobs: int, stale_after_seconds: int}
     */
    private function queueReadinessFor(string $queueGroup): array
    {
        $pendingJobs = $this->pendingJobsFor($queueGroup);
        $lastHeartbeatAt = $this->readHeartbeat($this->queueCacheKey($queueGroup));

        if ($pendingJobs === 0) {
            return [
                'status' => $this->isFreshHeartbeat($lastHeartbeatAt) ? 'ok' : 'idle',
                'healthy' => true,
                'last_heartbeat_at' => $lastHeartbeatAt?->toIso8601String(),
                'pending_jobs' => 0,
                'stale_after_seconds' => self::QUEUE_WORKER_STALE_AFTER_SECONDS,
            ];
        }

        if ($lastHeartbeatAt === null) {
            return [
                'status' => 'missing',
                'healthy' => false,
                'last_heartbeat_at' => null,
                'pending_jobs' => $pendingJobs,
                'stale_after_seconds' => self::QUEUE_WORKER_STALE_AFTER_SECONDS,
            ];
        }

        $isFresh = $this->isFreshHeartbeat($lastHeartbeatAt);

        return [
            'status' => $isFresh ? 'ok' : 'stale',
            'healthy' => $isFresh,
            'last_heartbeat_at' => $lastHeartbeatAt->toIso8601String(),
            'pending_jobs' => $pendingJobs,
            'stale_after_seconds' => self::QUEUE_WORKER_STALE_AFTER_SECONDS,
        ];
    }

    private function pendingJobsFor(string $queueGroup): int
    {
        return DB::table($this->jobsTable())
            ->whereIn('queue', $this->queuesFor($queueGroup))
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now()->getTimestamp())
            ->count();
    }

    /**
     * @return list<string>
     */
    private function queuesFor(string $queueGroup): array
    {
        return match ($queueGroup) {
            self::DEFAULT_QUEUE_GROUP => [$this->defaultQueueName()],
            self::FORENSICS_QUEUE_GROUP => self::FORENSICS_QUEUES,
            default => throw new \InvalidArgumentException("Unsupported queue group [{$queueGroup}]."),
        };
    }

    private function defaultQueueName(): string
    {
        $queue = config('queue.connections.database.queue', 'default');

        return is_string($queue) && $queue !== '' ? $queue : 'default';
    }

    private function jobsTable(): string
    {
        $table = config('queue.connections.database.table', 'jobs');

        return is_string($table) && $table !== '' ? $table : 'jobs';
    }

    private function readHeartbeat(string $cacheKey): ?Carbon
    {
        $timestamp = Cache::get($cacheKey);

        if (! is_string($timestamp) || $timestamp === '') {
            return null;
        }

        try {
            return Carbon::parse($timestamp);
        } catch (Throwable) {
            return null;
        }
    }

    private function isFreshHeartbeat(?CarbonInterface $lastHeartbeatAt): bool
    {
        return $lastHeartbeatAt !== null
            && $this->isFresh($lastHeartbeatAt, self::QUEUE_WORKER_STALE_AFTER_SECONDS);
    }

    private function isFresh(CarbonInterface $timestamp, int $staleAfterSeconds): bool
    {
        return $timestamp->greaterThanOrEqualTo(now()->subSeconds($staleAfterSeconds));
    }
}
