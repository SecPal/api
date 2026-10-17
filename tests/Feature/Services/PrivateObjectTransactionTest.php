<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\TenantKey;
use App\Services\PrivateObjectService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

trait PrivateObjectCommitDatabase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = [];

    protected function beforeTruncatingDatabase(): void
    {
        $connection = $this->app['db']->connection();
        $schema = $connection->selectOne('select current_schema() as name')->name;
        // TestCase authenticates the selected test database/schema. CI explicitly
        // uses public in its disposable PostgreSQL service; local runs use the
        // workspace schema. Never truncate a different schema.
        $this->tablesToTruncate = array_column(
            $connection->getSchemaBuilder()->getTables($schema), 'schema_qualified_name',
        ) ?: [$schema.'.__no_test_tables__'];
    }
}

uses(PrivateObjectCommitDatabase::class)->group('serial');

beforeEach(function (): void {
    fakePrivateObjects();
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $this->tenant = TenantKey::factory()->create();
    $this->employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->objects = app(PrivateObjectService::class);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function committedPrivateObject($test): array
{
    $reservation = $test->objects->stage($test->tenant, 'employee-document', 'native committed object');
    $document = EmployeeDocument::factory()->create([
        'employee_id' => $test->employee->id, 'file_path' => $reservation['object_key'],
    ]);
    DB::transaction(fn () => $test->objects->publish(
        $reservation['id'], $test->tenant, 'employee-document', $document->id,
    ));

    return [$reservation, $document];
}

test('requires a real caller transaction and rejects an incomplete commit through PostgreSQL', function (): void {
    $reservation = $this->objects->stage($this->tenant, 'employee-document', 'uncommitted');
    $document = EmployeeDocument::factory()->create([
        'employee_id' => $this->employee->id, 'file_path' => $reservation['object_key'],
    ]);
    expect(fn () => $this->objects->publish(
        $reservation['id'], $this->tenant, 'employee-document', $document->id,
    ))->toThrow(LogicException::class);
    expect(fn () => DB::transaction(fn () => DB::table('private_objects')
        ->where('id', $reservation['id'])->update(['state' => 'READY'])))
        ->toThrow(PDOException::class);
    $this->assertDatabaseHas('private_objects', ['id' => $reservation['id'], 'state' => 'PENDING']);
});

test('database rejects finalized metadata mutation reference replacement and retention bypass', function (): void {
    [$reservation, $document] = committedPrivateObject($this);
    $other = EmployeeDocument::factory()->create(['employee_id' => $this->employee->id]);
    foreach ([
        fn () => DB::table('private_objects')->where('id', $reservation['id'])->update(['storage_sha256' => str_repeat('a', 64)]),
        fn () => DB::table('private_object_references')->where('object_id', $reservation['id'])->update(['employee_document_id' => $other->id]),
        fn () => DB::table('private_object_references')->where('object_id', $reservation['id'])->delete(),
        fn () => DB::table('private_objects')->where('id', $reservation['id'])->delete(),
        fn () => DB::table('object_replication_intents')->where('object_id', $reservation['id'])->delete(),
    ] as $mutation) {
        expect(fn () => DB::transaction($mutation))->toThrow(PDOException::class);
    }
    expect($this->objects->read($this->tenant, 'employee-document', $document->id))->toBe('native committed object');
});

/** @return array<string, mixed> */
function privateObjectChildInputs(string $operation): array
{
    return [
        'operation' => $operation, 'database' => DB::connection()->getConfig(),
        'objects' => config('private_objects'),
        'disk' => ['driver' => 'local', 'root' => rtrim(Illuminate\Support\Facades\Storage::disk('private_objects')->path(''), '/'), 'throw' => true],
        'kek_path' => getTestKekPath(),
    ];
}

/** @return array{0: resource, 1: array<int, resource>} */
function startPrivateObjectChild(array $input): array
{
    $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/private-object-process.php')],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path());
    if (! is_resource($process)) {
        throw new RuntimeException('Native application child unavailable');
    }
    fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);

    return [$process, $pipes];
}

function finishPrivateObjectChild(array $child): string
{
    [$process, $pipes] = $child;
    stream_set_timeout($pipes[1], 20);
    $result = stream_get_contents($pipes[1], 4096);
    fclose($pipes[1]);
    // Never publish stderr or credential-bearing fixture inputs.
    $stderr = stream_get_contents($pipes[2], 4096);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($stderr !== '' || ! in_array($status, [0, 1], true)) {
        throw new RuntimeException('Native application child failed outside its contract');
    }

    $document = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
    if ($document['result'] === 'rejected') {
        fwrite(STDERR, json_encode(['native_child_rejection' => $document['diagnostic'] ?? null]).PHP_EOL);
    }

    return $document['result'];
}

test('two native Laravel publishers cannot attach the same Object to different business owners', function (): void {
    $reservation = $this->objects->stage($this->tenant, 'employee-document', 'concurrent');
    $documents = EmployeeDocument::factory()->count(2)->create([
        'employee_id' => $this->employee->id, 'file_path' => $reservation['object_key'],
    ]);
    $barrier = tempnam(sys_get_temp_dir(), 'object-race-');
    unlink($barrier);
    $base = privateObjectChildInputs('publish') + [
        'object_id' => $reservation['id'], 'tenant_id' => $this->tenant->id, 'barrier' => $barrier,
    ];
    try {
        $first = startPrivateObjectChild($base + ['participant' => 1, 'business_id' => $documents[0]->id]);
        $second = startPrivateObjectChild($base + ['participant' => 2, 'business_id' => $documents[1]->id]);
        $results = [finishPrivateObjectChild($first), finishPrivateObjectChild($second)];
        sort($results);
        expect($results)->toBe(['accepted', 'rejected']);
        expect(DB::table('private_object_references')->where('object_id', $reservation['id'])->count())->toBe(1);
        expect(DB::table('object_replication_intents')->where('object_id', $reservation['id'])->count())->toBe(1);
    } finally {
        @unlink($barrier.'.1');
        @unlink($barrier.'.2');
    }
});

test('real Laravel Worker sees committed publication and no job escapes outer rollback', function (): void {
    config(['queue.default' => 'database', 'queue.connections.database.after_commit' => true]);
    Illuminate\Support\Facades\Schema::create('private_object_worker_results', function ($table): void {
        $table->uuid('document_id')->primary();
    });
    [$reservation, $document] = committedPrivateObject($this);
    DB::beginTransaction();
    dispatch(new Tests\Support\PrivateObjectReadJob(
        $this->tenant->id, $document->id, hash('sha256', 'native committed object'),
    ))->onQueue('private-object-test')->afterCommit();
    expect(DB::table('jobs')->count())->toBe(0);
    DB::rollBack();
    expect(DB::table('jobs')->count())->toBe(0);
    DB::transaction(function () use ($document): void {
        dispatch(new Tests\Support\PrivateObjectReadJob(
            $this->tenant->id, $document->id, hash('sha256', 'native committed object'),
        ))->onQueue('private-object-test')->afterCommit();
        expect(DB::table('jobs')->count())->toBe(0);
    });
    expect(DB::table('jobs')->count())->toBe(1);
    $child = startPrivateObjectChild(privateObjectChildInputs('worker'));
    expect(finishPrivateObjectChild($child))->toBe('accepted');
    expect(DB::table('jobs')->count())->toBe(0);
    expect(DB::table('failed_jobs')->count())->toBe(0);
    $this->assertDatabaseHas('private_object_worker_results', ['document_id' => $document->id]);
    Illuminate\Support\Facades\Schema::drop('private_object_worker_results');
});

test('expired employee business cleanup retains immutable Object tombstones and ciphertext', function (): void {
    [$reservation, $document] = committedPrivateObject($this);
    $submission = App\Models\OnboardingFormSubmission::factory()->create(['employee_id' => $this->employee->id]);
    $onboardingObject = $this->objects->stage($this->tenant, 'onboarding-file', 'retained onboarding ciphertext');
    $onboardingFile = App\Models\OnboardingSubmissionFile::create([
        'tenant_id' => $this->tenant->id, 'onboarding_form_submission_id' => $submission->id,
        'uploaded_by' => $document->uploaded_by, 'document_type' => 'contract',
        'file_path' => $onboardingObject['object_key'], 'file_name' => 'synthetic.pdf',
        'mime_type' => 'application/pdf', 'file_size' => 30,
    ]);
    DB::transaction(fn () => $this->objects->publish($onboardingObject['id'], $this->tenant, 'onboarding-file', $onboardingFile->id));
    $this->employee->forceFill([
        'status' => Employee::STATUS_TERMINATED,
        'employment_end_date' => today()->subYears(4),
        'retention_period_end' => today()->subDay(),
    ])->saveQuietly();
    $result = app(App\Services\ExpiredEmployeeDeletionService::class)->deleteExpiredEmployees($this->tenant->id);
    expect($result['deleted'])->toBe(1)->and($result['files_deleted'])->toBe(0);
    expect(Employee::withTrashed()->find($this->employee->id))->toBeNull();
    expect(EmployeeDocument::withTrashed()->find($document->id))->toBeNull();
    expect(App\Models\OnboardingSubmissionFile::withTrashed()->find($onboardingFile->id))->toBeNull();
    $this->assertDatabaseHas('private_objects', ['id' => $onboardingObject['id'], 'state' => 'TOMBSTONED']);
    Illuminate\Support\Facades\Storage::disk('private_objects')->assertExists($onboardingObject['object_key']);

    $this->assertDatabaseHas('private_objects', ['id' => $reservation['id'], 'state' => 'TOMBSTONED']);
    $this->assertDatabaseHas('private_object_references', ['object_id' => $reservation['id'], 'employee_document_id' => $document->id]);
    Illuminate\Support\Facades\Storage::disk('private_objects')->assertExists($reservation['object_key']);
    expect(fn () => $this->objects->read($this->tenant, 'employee-document', $document->id))->toThrow(RuntimeException::class);
});

test('a claimed onboarding tenant cannot replace its actual employee tenant at publication', function (): void {
    $otherTenant = TenantKey::factory()->create();
    $otherEmployee = Employee::factory()->create(['tenant_id' => $otherTenant->id]);
    $submission = App\Models\OnboardingFormSubmission::factory()->create(['employee_id' => $otherEmployee->id]);
    $object = $this->objects->stage($this->tenant, 'onboarding-file', 'tenant-bound bytes');
    $file = App\Models\OnboardingSubmissionFile::create([
        'tenant_id' => $this->tenant->id, 'onboarding_form_submission_id' => $submission->id,
        'uploaded_by' => App\Models\User::factory()->create()->id,
        'document_type' => 'contract', 'file_path' => $object['object_key'],
        'file_name' => 'synthetic.pdf', 'mime_type' => 'application/pdf', 'file_size' => 18,
    ]);
    expect(fn () => DB::transaction(fn () => $this->objects->publish(
        $object['id'], $this->tenant, 'onboarding-file', $file->id,
    )))->toThrow(RuntimeException::class);
    expect(fn () => DB::transaction(fn () => DB::table('private_object_references')->insert([
        'object_id' => $object['id'], 'onboarding_submission_file_id' => $file->id,
    ])))->toThrow(PDOException::class);
});
