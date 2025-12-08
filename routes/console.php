<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule: Expire temporal role assignments every minute
Schedule::command('roles:expire')->everyMinute();

// Schedule: Update employee statuses based on contract dates daily at 06:00
Schedule::command('employees:update-status')->dailyAt('06:00');

// Schedule: Update qualification statuses and send expiry notifications daily at 07:00
Schedule::command('employees:update-qualifications')->dailyAt('07:00');

// Schedule: Send contract ending soon notifications daily at 08:00
Schedule::call(function () {
    $sevenDaysFromNow = \Carbon\Carbon::today()->addDays(7);

    $employees = \App\Models\Employee::where('status', \App\Models\Employee::STATUS_ACTIVE)
        ->whereDate('termination_date', '=', $sevenDaysFromNow)
        ->with('user')
        ->get();

    foreach ($employees as $employee) {
        if ($employee->user && $employee->email) {
            \Illuminate\Support\Facades\Mail::to($employee->email)
                ->queue(new \App\Mail\ContractEndingSoonMail($employee));
        }
    }

    \Illuminate\Support\Facades\Log::info('Contract ending soon notifications sent', [
        'count' => $employees->count(),
        'date' => $sevenDaysFromNow->format('Y-m-d'),
    ]);
})->dailyAt('08:00')->name('send-contract-ending-notifications');
