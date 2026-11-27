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
use RuntimeException;
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
        try {
            $employee = Employee::query()->where('user_id', $this->userId)
                ->whereHas('user', fn ($query) => $query->where('email_version', $this->emailVersion))
                ->find($this->employeeId);
            if ($employee === null) {
                return;
            }

            $email->withEmail($this->userId, $this->emailVersion, true, function (#[SensitiveParameter] string $recipient) use ($employee, $delivery): void {
                $delivery->mailer()->sendNow(
                    ($this->activated ? new WelcomeActiveMail($employee) : new AccountDeactivatedMail($employee))->to($recipient),
                );
            });
        } catch (Throwable) {
            throw new RuntimeException('Employee account notice delivery failed.');
        }
    }
}
