<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\TenantKey;
use App\Services\RuntimeHeartbeatService;
use App\Services\TenantKekRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    /**
     * Liveness probe - checks if application is running.
     *
     * Returns 200 OK if application process is alive.
     * Does NOT check configuration or readiness.
     * Used by Kubernetes/Docker health checks to determine if container should be restarted.
     */
    public function live(): JsonResponse
    {
        // Minimal check - app is running if we reach this point
        return response()->json([
            'status' => 'alive',
            'timestamp' => \App\Support\ApiTimestamp::format(now()),
        ], 200);
    }

    /**
     * Readiness probe - checks if application can handle requests.
     *
     * Returns 200 OK only if all systems are configured and operational.
     * Returns 503 Service Unavailable if any critical check fails.
     * Used by load balancers to determine if traffic should be routed to this instance.
     *
     * Checks:
     * - Database connectivity
     * - Tenant keys exist
     * - KEK file is readable
     * - Scheduler heartbeats are fresh
     * - Queue workers stay fresh while backlog exists
     */
    public function ready(RuntimeHeartbeatService $runtimeHeartbeatService, TenantKekRegistry $kek): JsonResponse
    {
        $allPassed = true;

        // Database connectivity
        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $allPassed = false;
        }

        // Observe key presence and the exact committed generation under one lease.
        try {
            if (! $kek->withKey(static fn (): bool => TenantKey::query()->exists())) {
                $allPassed = false;
            }
        } catch (\Throwable) {
            $allPassed = false;
        }

        // Scheduler heartbeat
        try {
            if (! $runtimeHeartbeatService->schedulerReadiness()['healthy']) {
                $allPassed = false;
            }
        } catch (\Throwable) {
            $allPassed = false;
        }

        // Queue worker heartbeats
        try {
            foreach ($runtimeHeartbeatService->queueReadiness() as $queueCheck) {
                if (! $queueCheck['healthy']) {
                    $allPassed = false;
                }
            }
        } catch (\Throwable) {
            $allPassed = false;
        }

        return response()->json([
            'status' => $allPassed ? 'ready' : 'not_ready',
            'timestamp' => \App\Support\ApiTimestamp::format(now()),
        ], $allPassed ? 200 : 503);
    }
}
