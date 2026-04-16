<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class)->group('unit', 'observer', 'bewachv');

beforeEach(function () {
    if (! file_exists(TenantKey::getKekPath())) {
        TenantKey::generateKek();
    }

    Mail::fake();
    Storage::fake('local');
});

test('it deletes id document copy when bwr status becomes active', function () {
    $employee = Employee::factory()->create(['bwr_status' => 'pending', 'id_document_copy_path' => 'id_documents/test.pdf']);
    Storage::put('id_documents/test.pdf', 'fake content');

    expect(Storage::exists('id_documents/test.pdf'))->toBeTrue();

    $employee->bwr_status = 'active';
    $employee->save();

    expect(Storage::exists('id_documents/test.pdf'))->toBeFalse();
    expect($employee->fresh()->id_document_copy_deleted_at)->not->toBeNull();
});

test('it queues hr mail when bwr activation auto deletes the id document copy', function () {
    $employee = Employee::factory()->create([
        'bwr_status' => 'pending',
        'id_document_copy_path' => 'id_documents/notify.pdf',
        'employee_number' => 'EMP-912',
        'email' => 'notify.employee@secpal.dev',
    ]);
    Storage::put('id_documents/notify.pdf', 'notify content');

    $employee->bwr_status = 'active';
    $employee->save();

    Mail::assertQueued(App\Mail\BwrIdDocumentAutoDeletedMail::class, function (App\Mail\BwrIdDocumentAutoDeletedMail $mail) use ($employee): bool {
        return $mail->hasTo(config('mail.hr_email', config('mail.from.address')))
            && $mail->employee->is($employee->fresh());
    });
});

test('it logs id document deletion with legal basis', function () {
    $employee = Employee::factory()->create(['bwr_status' => 'pending', 'id_document_copy_path' => 'id_documents/test_id.pdf']);
    Storage::put('id_documents/test_id.pdf', 'test');

    $employee->bwr_status = 'active';
    $employee->save();

    $activity = Activity::where('subject_id', $employee->id)
        ->where('subject_type', Employee::class)
        ->where('description', 'ID document copy automatically deleted (BWR active)')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties->get('action'))->toEqual('id_document_auto_deleted')
        ->and($activity->properties->get('legal_basis'))->toContain('GDPR');
});

test('it does not delete id document when bwr status changes to non active', function () {
    $employee = Employee::factory()->create(['bwr_status' => 'pending', 'id_document_copy_path' => 'id_documents/test.pdf']);
    Storage::put('id_documents/test.pdf', 'content');

    $employee->bwr_status = 'suspended';
    $employee->save();

    expect(Storage::exists('id_documents/test.pdf'))->toBeTrue();
    expect($employee->fresh()->id_document_copy_deleted_at)->toBeNull();
});

test('it does not fail when id document copy path is null', function () {
    $employee = Employee::factory()->create(['bwr_status' => 'pending', 'id_document_copy_path' => null]);

    $employee->bwr_status = 'active';
    $employee->save();

    expect($employee->fresh()->id_document_copy_deleted_at)->toBeNull();
    Mail::assertNothingQueued();
});

test('it does not queue hr mail when the id document file is already missing', function () {
    $employee = Employee::factory()->create([
        'bwr_status' => 'pending',
        'id_document_copy_path' => 'id_documents/missing.pdf',
    ]);

    $employee->bwr_status = 'active';
    $employee->save();

    Mail::assertNothingQueued();
});

test('it deletes work permit copy when bwr status becomes active', function () {
    $employee = Employee::factory()->withNonEuWorkPermit()->create([
        'bwr_status' => 'pending',
        'work_permit_copy_path' => 'work_permits/test.pdf',
    ]);
    Storage::put('work_permits/test.pdf', 'permit content');

    expect(Storage::exists('work_permits/test.pdf'))->toBeTrue();

    $employee->bwr_status = 'active';
    $employee->save();

    expect(Storage::exists('work_permits/test.pdf'))->toBeFalse();
    expect($employee->fresh()->work_permit_copy_deleted_at)->not->toBeNull();
});

test('it deletes work permit copy when permit becomes permanent', function () {
    $employee = Employee::factory()->withNonEuWorkPermit()->create([
        'work_permit_type' => 'temporary',
        'work_permit_expiry' => now()->addMonths(3)->toDateString(),
        'work_permit_copy_path' => 'work_permits/permanent.pdf',
    ]);
    Storage::put('work_permits/permanent.pdf', 'permit content');

    $employee->work_permit_type = 'permanent';
    $employee->work_permit_expiry = null;
    $employee->save();

    expect(Storage::exists('work_permits/permanent.pdf'))->toBeFalse();
    expect($employee->fresh()->work_permit_copy_deleted_at)->not->toBeNull();
});

test('it calculates retention period when employee is terminated', function () {
    $employee = Employee::factory()->create(['status' => Employee::STATUS_ACTIVE]);

    $employee->status = Employee::STATUS_TERMINATED;
    $employee->termination_date = '2024-06-15';
    $employee->save();

    $employee->refresh();
    expect($employee->employment_end_date->toDateString())->toEqual('2024-06-15')
        ->and($employee->retention_period_end->toDateString())->toEqual('2027-12-31');
});

test('it calculates retention period for year end termination', function () {
    $employee = Employee::factory()->create(['status' => Employee::STATUS_ACTIVE]);

    $employee->status = Employee::STATUS_TERMINATED;
    $employee->termination_date = '2024-12-31';
    $employee->save();

    expect($employee->fresh()->retention_period_end->toDateString())->toEqual('2027-12-31');
});

test('it logs retention period calculation with legal basis', function () {
    $employee = Employee::factory()->create(['status' => Employee::STATUS_ACTIVE]);

    $employee->status = Employee::STATUS_TERMINATED;
    $employee->termination_date = '2024-06-15';
    $employee->save();

    $activity = Activity::where('subject_id', $employee->id)
        ->where('subject_type', Employee::class)
        ->where('description', 'Retention period calculated (BewachV §21 - 3 years from end of calendar year)')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties->get('action'))->toEqual('retention_period_calculated')
        ->and($activity->properties->get('employment_end_date'))->toEqual('2024-06-15')
        ->and($activity->properties->get('retention_period_end'))->toEqual('2027-12-31')
        ->and($activity->properties->get('legal_basis'))->toContain('BewachV');
});

test('it does not calculate retention when termination date is null', function () {
    $employee = Employee::factory()->create([
        'status' => Employee::STATUS_ACTIVE,
        'termination_date' => null,
    ]);

    $employee->status = Employee::STATUS_TERMINATED;
    $employee->save();

    // Even without termination_date, Observer uses now() as fallback
    expect($employee->fresh()->retention_period_end)->not->toBeNull();
});

test('it handles both bwr activation and termination in same update', function () {
    Storage::put('id_documents/doc.pdf', 'test');
    $employee = Employee::factory()->create(['status' => Employee::STATUS_ACTIVE, 'bwr_status' => 'pending', 'id_document_copy_path' => 'id_documents/doc.pdf']);

    // Update BWR status and termination separately to ensure both observers fire
    $employee->bwr_status = 'active';
    $employee->save();

    $employee->status = Employee::STATUS_TERMINATED;
    $employee->termination_date = '2024-06-15';
    $employee->save();

    // Refresh from DB
    $employee = $employee->fresh();

    expect($employee->id_document_copy_deleted_at)->not->toBeNull()
        ->and($employee->retention_period_end)->not->toBeNull()
        ->and($employee->retention_period_end->toDateString())->toEqual('2027-12-31')
        ->and($employee->employment_end_date)->not->toBeNull()
        ->and($employee->employment_end_date->toDateString())->toEqual('2024-06-15');
});
