<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Tests for GuardBook and GuardBookReport models (Issue #233).
 *
 * These tests verify:
 * - Factory functionality and states
 * - Model relationships (object, objectArea, reports)
 * - Soft deletes
 * - XOR constraint enforcement (object_id OR object_area_id)
 * - Helper methods (isAreaSpecific, getParentObject)
 * - Report generation
 *
 * Note: Guard books are continuous event streams, not closed physical books.
 * Reports are generated on-demand for any time period.
 */

use App\Models\Customer;
use App\Models\GuardBook;
use App\Models\GuardBookReport;
use App\Models\ObjectArea;
use App\Models\SecPalObject;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Use proper TenantKey initialization as per project pattern
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    // Create a tenant for testing
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Create a customer with an object for guard book tests
    $this->customer = Customer::factory()->forTenant($this->tenant->id)->create();
    $this->object = SecPalObject::factory()
        ->forTenant($this->tenant->id)
        ->forCustomer($this->customer)
        ->create();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('GuardBook Model', function (): void {
    describe('factory', function (): void {
        it('creates a valid guard book for entire object', function (): void {
            $guardBook = GuardBook::factory()
                ->forTenant($this->tenant->id)
                ->forObject($this->object)
                ->create();

            expect($guardBook)->toBeInstanceOf(GuardBook::class)
                ->and($guardBook->id)->toBeString()
                ->and($guardBook->title)->toBeString()
                ->and($guardBook->object_id)->toBe($this->object->id)
                ->and($guardBook->object_area_id)->toBeNull()
                ->and($guardBook->is_active)->toBeTrue();
        });

        it('creates a valid guard book for specific area', function (): void {
            $area = ObjectArea::factory()
                ->forTenant($this->tenant->id)
                ->forObject($this->object)
                ->withSeparateGuardBook()
                ->create();

            $guardBook = GuardBook::factory()
                ->forTenant($this->tenant->id)
                ->forObjectArea($area)
                ->create();

            expect($guardBook)->toBeInstanceOf(GuardBook::class)
                ->and($guardBook->object_id)->toBeNull()
                ->and($guardBook->object_area_id)->toBe($area->id);
        });

        it('creates inactive guard book via factory state', function (): void {
            $guardBook = GuardBook::factory()
                ->forTenant($this->tenant->id)
                ->forObject($this->object)
                ->archived()
                ->create();

            expect($guardBook->is_active)->toBeFalse()
                ->and($guardBook->archived_at)->not->toBeNull();
        });
    });

    describe('XOR constraint enforcement', function (): void {
        it('prevents creation with both object_id AND object_area_id', function (): void {
            $area = ObjectArea::factory()
                ->forTenant($this->tenant->id)
                ->forObject($this->object)
                ->create();

            expect(fn () => GuardBook::factory()
                ->forTenant($this->tenant->id)
                ->create([
                    'object_id' => $this->object->id,
                    'object_area_id' => $area->id,
                ])
            )->toThrow(\InvalidArgumentException::class, 'GuardBook must have EITHER object_id OR object_area_id, not both');
        });

        it('prevents creation with neither object_id NOR object_area_id', function (): void {
            expect(fn () => GuardBook::factory()
                ->forTenant($this->tenant->id)
                ->create([
                    'object_id' => null,
                    'object_area_id' => null,
                ])
            )->toThrow(\InvalidArgumentException::class, 'GuardBook must have EITHER object_id OR object_area_id');
        });
    });

    describe('relationships', function (): void {
        it('belongs to an object', function (): void {
            $guardBook = GuardBook::factory()
                ->forTenant($this->tenant->id)
                ->forObject($this->object)
                ->create();

            expect($guardBook->object)->toBeInstanceOf(SecPalObject::class)
                ->and($guardBook->object->id)->toBe($this->object->id);
        });

        it('belongs to an object area', function (): void {
            $area = ObjectArea::factory()
                ->forTenant($this->tenant->id)
                ->forObject($this->object)
                ->withSeparateGuardBook()
                ->create();

            $guardBook = GuardBook::factory()
                ->forTenant($this->tenant->id)
                ->forObjectArea($area)
                ->create();

            expect($guardBook->objectArea)->toBeInstanceOf(ObjectArea::class)
                ->and($guardBook->objectArea->id)->toBe($area->id);
        });

        it('has many reports', function (): void {
            $guardBook = GuardBook::factory()
                ->forTenant($this->tenant->id)
                ->forObject($this->object)
                ->create();

            GuardBookReport::factory()
                ->forTenant($this->tenant->id)
                ->forGuardBook($guardBook)
                ->count(3)
                ->create();

            expect($guardBook->reports)->toHaveCount(3)
                ->and($guardBook->reports->first())->toBeInstanceOf(GuardBookReport::class);
        });

        it('belongs to tenant', function (): void {
            $guardBook = GuardBook::factory()
                ->forTenant($this->tenant->id)
                ->forObject($this->object)
                ->create();

            expect($guardBook->tenant)->toBeInstanceOf(TenantKey::class)
                ->and($guardBook->tenant->id)->toBe($this->tenant->id);
        });
    });

    describe('helper methods', function (): void {
        it('isAreaSpecific returns true for area-specific guard book', function (): void {
            $area = ObjectArea::factory()
                ->forTenant($this->tenant->id)
                ->forObject($this->object)
                ->create();

            $guardBook = GuardBook::factory()
                ->forTenant($this->tenant->id)
                ->forObjectArea($area)
                ->create();

            expect($guardBook->isAreaSpecific())->toBeTrue();
        });

        it('isAreaSpecific returns false for object-wide guard book', function (): void {
            $guardBook = GuardBook::factory()
                ->forTenant($this->tenant->id)
                ->forObject($this->object)
                ->create();

            expect($guardBook->isAreaSpecific())->toBeFalse();
        });

        it('getParentObject returns object for object-wide guard book', function (): void {
            $guardBook = GuardBook::factory()
                ->forTenant($this->tenant->id)
                ->forObject($this->object)
                ->create();

            expect($guardBook->getParentObject())->toBeInstanceOf(SecPalObject::class)
                ->and($guardBook->getParentObject()->id)->toBe($this->object->id);
        });

        it('getParentObject returns parent object for area-specific guard book', function (): void {
            $area = ObjectArea::factory()
                ->forTenant($this->tenant->id)
                ->forObject($this->object)
                ->create();

            $guardBook = GuardBook::factory()
                ->forTenant($this->tenant->id)
                ->forObjectArea($area)
                ->create();

            expect($guardBook->getParentObject())->toBeInstanceOf(SecPalObject::class)
                ->and($guardBook->getParentObject()->id)->toBe($this->object->id);
        });
    });

    describe('soft deletes', function (): void {
        it('uses soft deletes', function (): void {
            $guardBook = GuardBook::factory()
                ->forTenant($this->tenant->id)
                ->forObject($this->object)
                ->create();

            $guardBookId = $guardBook->id;
            $guardBook->delete();

            expect(GuardBook::find($guardBookId))->toBeNull()
                ->and(GuardBook::withTrashed()->find($guardBookId))->not->toBeNull();
        });
    });

    describe('archiving', function (): void {
        it('can be archived', function (): void {
            $guardBook = GuardBook::factory()
                ->forTenant($this->tenant->id)
                ->forObject($this->object)
                ->create();

            $guardBook->archive();

            expect($guardBook->is_active)->toBeFalse()
                ->and($guardBook->archived_at)->not->toBeNull();

            // Verify persistence to database
            $guardBook->refresh();
            expect($guardBook->is_active)->toBeFalse()
                ->and($guardBook->archived_at)->not->toBeNull();
        });

        it('can be reactivated', function (): void {
            $guardBook = GuardBook::factory()
                ->forTenant($this->tenant->id)
                ->forObject($this->object)
                ->archived()
                ->create();

            $guardBook->reactivate();

            expect($guardBook->is_active)->toBeTrue()
                ->and($guardBook->archived_at)->toBeNull();

            // Verify persistence to database
            $guardBook->refresh();
            expect($guardBook->is_active)->toBeTrue()
                ->and($guardBook->archived_at)->toBeNull();
        });
    });
});

describe('GuardBookReport Model', function (): void {
    beforeEach(function (): void {
        $this->guardBook = GuardBook::factory()
            ->forTenant($this->tenant->id)
            ->forObject($this->object)
            ->create();
    });

    describe('factory', function (): void {
        it('creates a valid guard book report', function (): void {
            $report = GuardBookReport::factory()
                ->forTenant($this->tenant->id)
                ->forGuardBook($this->guardBook)
                ->create();

            expect($report)->toBeInstanceOf(GuardBookReport::class)
                ->and($report->id)->toBeString()
                ->and($report->report_number)->toMatch('/^GB-\d{4}-\d{3}$/')
                ->and($report->title)->toBeString()
                ->and($report->guard_book_id)->toBe($this->guardBook->id)
                ->and($report->period_start)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
                ->and($report->period_end)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
        });

        it('creates report with specific period via factory state', function (): void {
            $start = now()->startOfMonth();
            $end = now()->endOfMonth();

            $report = GuardBookReport::factory()
                ->forTenant($this->tenant->id)
                ->forGuardBook($this->guardBook)
                ->forPeriod($start, $end)
                ->create();

            expect($report->period_start->toDateString())->toBe($start->toDateString())
                ->and($report->period_end->toDateString())->toBe($end->toDateString());
        });

        it('creates draft report via factory state', function (): void {
            $report = GuardBookReport::factory()
                ->forTenant($this->tenant->id)
                ->forGuardBook($this->guardBook)
                ->draft()
                ->create();

            expect($report->status)->toBe('draft');
        });

        it('creates finalized report via factory state', function (): void {
            $report = GuardBookReport::factory()
                ->forTenant($this->tenant->id)
                ->forGuardBook($this->guardBook)
                ->finalized()
                ->create();

            expect($report->status)->toBe('finalized');
        });
    });

    describe('relationships', function (): void {
        it('belongs to a guard book', function (): void {
            $report = GuardBookReport::factory()
                ->forTenant($this->tenant->id)
                ->forGuardBook($this->guardBook)
                ->create();

            expect($report->guardBook)->toBeInstanceOf(GuardBook::class)
                ->and($report->guardBook->id)->toBe($this->guardBook->id);
        });

        it('belongs to generated_by user', function (): void {
            $user = User::factory()->create();

            $report = GuardBookReport::factory()
                ->forTenant($this->tenant->id)
                ->forGuardBook($this->guardBook)
                ->generatedBy($user)
                ->create();

            expect($report->generatedBy)->toBeInstanceOf(User::class)
                ->and($report->generatedBy->id)->toBe($user->id);
        });

        it('can have null generated_by for system-generated reports', function (): void {
            $report = GuardBookReport::factory()
                ->forTenant($this->tenant->id)
                ->forGuardBook($this->guardBook)
                ->create(['generated_by_user_id' => null]);

            expect($report->generatedBy)->toBeNull();
        });
    });

    describe('filter criteria', function (): void {
        it('stores filter criteria as array', function (): void {
            $criteria = [
                'event_types' => ['incident', 'patrol'],
                'severity' => 'high',
            ];

            $report = GuardBookReport::factory()
                ->forTenant($this->tenant->id)
                ->forGuardBook($this->guardBook)
                ->withFilterCriteria($criteria)
                ->create();

            expect($report->filter_criteria)->toBeArray()
                ->and($report->filter_criteria['event_types'])->toBe(['incident', 'patrol'])
                ->and($report->filter_criteria['severity'])->toBe('high');
        });

        it('getIncludedEventTypes returns event types from filter criteria', function (): void {
            $report = GuardBookReport::factory()
                ->forTenant($this->tenant->id)
                ->forGuardBook($this->guardBook)
                ->withFilterCriteria(['event_types' => ['incident', 'patrol']])
                ->create();

            expect($report->getIncludedEventTypes())->toBe(['incident', 'patrol']);
        });

        it('getIncludedEventTypes returns empty array when no filter', function (): void {
            $report = GuardBookReport::factory()
                ->forTenant($this->tenant->id)
                ->forGuardBook($this->guardBook)
                ->create(['filter_criteria' => null]);

            expect($report->getIncludedEventTypes())->toBe([]);
        });

        it('includesEventType checks if event type is in filter', function (): void {
            $report = GuardBookReport::factory()
                ->forTenant($this->tenant->id)
                ->forGuardBook($this->guardBook)
                ->withFilterCriteria(['event_types' => ['incident', 'patrol']])
                ->create();

            expect($report->includesEventType('incident'))->toBeTrue()
                ->and($report->includesEventType('other'))->toBeFalse();
        });

        it('includesEventType returns false when filter_criteria is null', function (): void {
            $report = GuardBookReport::factory()
                ->forTenant($this->tenant->id)
                ->forGuardBook($this->guardBook)
                ->create(['filter_criteria' => null]);

            expect($report->includesEventType('incident'))->toBeFalse();
        });

        it('belongs to tenant', function (): void {
            $report = GuardBookReport::factory()
                ->forTenant($this->tenant->id)
                ->forGuardBook($this->guardBook)
                ->create();

            expect($report->tenant)->toBeInstanceOf(TenantKey::class)
                ->and($report->tenant->id)->toBe($this->tenant->id);
        });
    });

    describe('report data', function (): void {
        it('stores report_data as array (denormalized events)', function (): void {
            $reportData = [
                ['entry_id' => 'uuid-1', 'event_type' => 'incident', 'occurred_at' => now()->toIso8601String()],
                ['entry_id' => 'uuid-2', 'event_type' => 'patrol', 'occurred_at' => now()->subHour()->toIso8601String()],
            ];

            $report = GuardBookReport::factory()
                ->forTenant($this->tenant->id)
                ->forGuardBook($this->guardBook)
                ->create(['report_data' => $reportData]);

            expect($report->report_data)->toBeArray()
                ->and($report->report_data)->toHaveCount(2)
                ->and($report->report_data[0]['event_type'])->toBe('incident');
        });
    });

    describe('period label formatting', function (): void {
        it('getPeriodLabel returns formatted period', function (): void {
            $start = now()->startOfMonth();
            $end = now()->endOfMonth();

            $report = GuardBookReport::factory()
                ->forTenant($this->tenant->id)
                ->forGuardBook($this->guardBook)
                ->forPeriod($start, $end)
                ->create();

            // Should return something like "01.11.2025 - 30.11.2025" or similar
            expect($report->getPeriodLabel())->toBeString()
                ->and($report->getPeriodLabel())->not->toBeEmpty();
        });
    });

    describe('soft deletes', function (): void {
        it('uses soft deletes', function (): void {
            $report = GuardBookReport::factory()
                ->forTenant($this->tenant->id)
                ->forGuardBook($this->guardBook)
                ->create();

            $reportId = $report->id;
            $report->delete();

            expect(GuardBookReport::find($reportId))->toBeNull()
                ->and(GuardBookReport::withTrashed()->find($reportId))->not->toBeNull();
        });
    });
});
