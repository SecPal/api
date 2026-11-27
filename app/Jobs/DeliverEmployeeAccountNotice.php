<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\AccountDeactivatedMail;
use App\Mail\WelcomeActiveMail;
use App\Models\Employee;
use App\Services\GlobalIdentity\GlobalEmailRepository;
use App\Services\GlobalIdentity\IdentityMailDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;
use Throwable;

final class DeliverEmployeeAccountNotice implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $employeeId,
        public readonly string $userId,
        public readonly int $emailVersion,
        public readonly bool $activated,
    ) {
        $this->afterCommit();
    }

    public function handle(GlobalEmailRepository $email, IdentityMailDelivery $delivery): void
    {
        $employee = Employee::query()->where('user_id', $this->userId)->find($this->employeeId);
        if ($employee === null) {
            return;
        }
        try {
            $email->withEmail($this->userId, $this->emailVersion, true, function (#[SensitiveParameter] string $recipient) use ($employee, $delivery): void {
                $delivery->mailer()->sendNow(
                    ($this->activated ? new WelcomeActiveMail($employee) : new AccountDeactivatedMail($employee))->to($recipient),
                );
            });
        } catch (Throwable) {
            Log::warning('Employee account notification failed.', ['employee_id' => $this->employeeId, 'user_id' => $this->userId]);
        }
    }
}
