<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Mail\OnboardingInvitationMail;
use App\Models\Employee;
use App\Models\EmployeeOnboardingToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EmployeeOnboardingInvitationService
{
    public function send(Employee $employee): Employee
    {
        $employee->loadMissing('user');

        $employee->updateQuietly([
            'onboarding_invitation_status' => Employee::INVITATION_STATUS_FAILED,
            'onboarding_invitation_requested_at' => now(),
            'onboarding_invitation_mail_sent_at' => null,
            'onboarding_invitation_mail_failed_at' => null,
            'onboarding_invitation_failure_reason' => null,
        ]);

        if ($employee->status !== Employee::STATUS_PRE_CONTRACT) {
            return $this->markFailed($employee, 'Onboarding invitations are only available for pre-contract employees.');
        }

        $user = $employee->user;
        if ($user === null) {
            return $this->markFailed($employee, 'Employee invitation requires a provisioned user account.');
        }

        $email = $employee->email;
        if (! is_string($email) || $email === '') {
            return $this->markFailed($employee, 'Employee invitation requires a valid employee email address.');
        }

        $tokenCreatedAt = null;

        try {
            $tokenData = EmployeeOnboardingToken::generate($employee);
            $tokenCreatedAt = now();

            $employee->updateQuietly([
                'onboarding_invitation_status' => Employee::INVITATION_STATUS_CREATED_NOT_SENT,
                'onboarding_invitation_token_created_at' => $tokenCreatedAt,
            ]);

            Mail::to($email)->send(new OnboardingInvitationMail($employee, $user, $tokenData['plain']));
        } catch (Throwable $throwable) {
            Log::error('Employee onboarding invitation delivery failed', [
                'employee_id' => $employee->id,
                'user_id' => $user->id,
                'token_created' => $tokenCreatedAt !== null,
                'error' => $throwable->getMessage(),
            ]);

            if ($tokenCreatedAt instanceof Carbon) {
                return $this->markCreatedNotSent($employee, $throwable->getMessage());
            }

            return $this->markFailed($employee, $throwable->getMessage());
        }

        $employee->updateQuietly([
            'onboarding_invitation_status' => Employee::INVITATION_STATUS_SENT,
            'onboarding_invitation_mail_sent_at' => now(),
            'onboarding_invitation_mail_failed_at' => null,
            'onboarding_invitation_failure_reason' => null,
        ]);

        Log::info('Employee onboarding invitation sent', [
            'employee_id' => $employee->id,
            'user_id' => $user->id,
        ]);

        /** @var Employee $freshEmployee */
        $freshEmployee = $employee->fresh();

        return $freshEmployee;
    }

    private function markFailed(Employee $employee, string $reason): Employee
    {
        $employee->updateQuietly([
            'onboarding_invitation_status' => Employee::INVITATION_STATUS_FAILED,
            'onboarding_invitation_mail_sent_at' => null,
            'onboarding_invitation_mail_failed_at' => now(),
            'onboarding_invitation_failure_reason' => $reason,
        ]);

        /** @var Employee $freshEmployee */
        $freshEmployee = $employee->fresh();

        return $freshEmployee;
    }

    private function markCreatedNotSent(Employee $employee, string $reason): Employee
    {
        $employee->updateQuietly([
            'onboarding_invitation_status' => Employee::INVITATION_STATUS_CREATED_NOT_SENT,
            'onboarding_invitation_mail_sent_at' => null,
            'onboarding_invitation_mail_failed_at' => now(),
            'onboarding_invitation_failure_reason' => $reason,
        ]);

        /** @var Employee $freshEmployee */
        $freshEmployee = $employee->fresh();

        return $freshEmployee;
    }
}
