<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\TenantKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

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
            'timestamp' => now()->toIso8601String(),
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
     */
    public function ready(): JsonResponse
    {
        $checks = [];
        $allPassed = true;

        // Check 1: Database connectivity
        try {
            DB::connection()->getPdo();
            $checks['database'] = 'ok';
        } catch (\Exception $e) {
            $checks['database'] = 'error';
            $allPassed = false;
        }

        // Check 2: Tenant keys exist
        try {
            $tenantKeyCount = TenantKey::count();
            if ($tenantKeyCount > 0) {
                $checks['tenant_keys'] = 'ok';
            } else {
                $checks['tenant_keys'] = 'missing';
                $allPassed = false;
            }
        } catch (\Exception $e) {
            $checks['tenant_keys'] = 'error';
            $allPassed = false;
        }

        // Check 3: KEK file is readable
        $kekPath = TenantKey::getKekPath();
        if (File::exists($kekPath) && File::isReadable($kekPath)) {
            $checks['kek_file'] = 'ok';
        } else {
            $checks['kek_file'] = 'missing';
            $allPassed = false;
        }

        $status = $allPassed ? 'ready' : 'not_ready';
        $httpStatus = $allPassed ? 200 : 503;

        return response()->json([
            'status' => $status,
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $httpStatus);
    }
}
