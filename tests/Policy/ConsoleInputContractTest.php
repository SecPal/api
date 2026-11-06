<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Console\Commands\DeleteExpiredEmployees;
use App\Console\Commands\ImportAddressDataCommand;
use App\Console\Commands\RebuildIndexCommand;
use App\Console\Commands\RotateDekCommand;
use App\Services\ExpiredEmployeeDeletionService;
use Database\Seeders\AddressDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

afterEach(function (): void {
    Mockery::close();
});

it('rejects ambiguous tenant arguments before accessing key records', function (string $class, mixed $tenant): void {
    $command = Mockery::mock($class)->makePartial();
    $command->shouldReceive('argument')->once()->with('tenant')->andReturn($tenant);
    $command->shouldReceive('info')->never();
    $command->shouldReceive('error')->once()->with('The tenant argument must be a single tenant ID.');

    expect($command->handle())->toBe(Command::FAILURE);
})->with([[RebuildIndexCommand::class], [RotateDekCommand::class]])->with([
    'multiple tenants' => [[1, 2]],
    'missing tenant' => [null],
    'boolean tenant' => [true],
    'fractional tenant' => [1.5],
]);

it('rejects an array tenant option before retention deletion', function (): void {
    $service = Mockery::mock(ExpiredEmployeeDeletionService::class);
    $service->shouldNotReceive('deleteExpiredEmployees');
    $command = Mockery::mock(DeleteExpiredEmployees::class.'[option,error]', [$service]);
    $command->shouldReceive('option')->once()->with('tenant')->andReturn([1, 2]);
    $command->shouldReceive('error')->once()->with('The --tenant option must be a numeric tenant ID.');

    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        expect($command->handle())->toBe(Command::FAILURE);
    } finally {
        restore_error_handler();
    }
});

it('rejects array source options without falling back to another source', function (string $method, string $option): void {
    $command = Mockery::mock(ImportAddressDataCommand::class)->makePartial();
    $command->shouldReceive('option')->once()->with($option)->andReturn(['unexpected']);
    $reader = new ReflectionMethod(ImportAddressDataCommand::class, $method);

    expect(fn () => $reader->invoke($command))->toThrow(InvalidArgumentException::class);
})->with([
    ['sourcePath', 'source'],
    ['expectedSha256', 'expected-sha256'],
]);

it('retains explicit source strings without changing their bytes', function (string $method, string $option, string $value): void {
    $command = Mockery::mock(ImportAddressDataCommand::class)->makePartial();
    $command->shouldReceive('option')->once()->with($option)->andReturn($value);
    $reader = new ReflectionMethod(ImportAddressDataCommand::class, $method);

    expect($reader->invoke($command))->toBe($value);
})->with([
    ['sourcePath', 'source', '/tmp/addresses.csv'],
    ['sourcePath', 'source', '  /tmp/addresses.csv  '],
    ['expectedSha256', 'expected-sha256', str_repeat('a', 64)],
]);

it('allows seeder progress output to be absent without a console', function (): void {
    $previousApplication = Facade::getFacadeApplication();
    $previousSchema = Schema::getFacadeRoot();
    $application = new Container;
    $application->instance('db.schema', new class
    {
        public function hasTable(string $table): bool
        {
            return false;
        }
    });
    Facade::setFacadeApplication($application);
    Schema::clearResolvedInstance('db.schema');

    try {
        expect(fn () => (new AddressDataSeeder)->run())->not->toThrow(Throwable::class);
    } finally {
        Schema::clearResolvedInstance('db.schema');
        Facade::setFacadeApplication($previousApplication);
        if ($previousSchema !== null) {
            Schema::swap($previousSchema);
        }
    }
});
