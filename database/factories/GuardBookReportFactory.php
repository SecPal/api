<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Factories;

use App\Models\GuardBook;
use App\Models\GuardBookReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Factory for creating GuardBookReport model instances for testing.
 *
 * @extends Factory<GuardBookReport>
 */
class GuardBookReportFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<GuardBookReport>
     */
    protected $model = GuardBookReport::class;

    /**
     * The counter for generating unique report numbers.
     */
    private static int $reportNumberCounter = 1;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = fake()->dateTimeBetween('-3 months', '-1 month');
        $periodEnd = (clone $periodStart)->modify('+1 month');

        /** @var string $reportType */
        $reportType = fake()->randomElement([
            'Monatsbericht',
            'Wochenbericht',
            'Quartalsbericht',
        ]);

        return [
            'tenant_id' => fake()->randomNumber(5),
            'guard_book_id' => GuardBook::factory(),
            'report_number' => 'GB-'.date('Y').'-'.str_pad((string) self::$reportNumberCounter++, 3, '0', STR_PAD_LEFT),
            'title' => $reportType.' '.fake()->monthName().' '.fake()->year(),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'filter_criteria' => null,
            'total_events' => fake()->numberBetween(0, 100),
            'report_data' => [],
            'generated_by_user_id' => null,
            'generated_at' => now(),
            'status' => 'draft',
        ];
    }

    /**
     * Configure the factory for a specific tenant.
     */
    public function forTenant(int $tenantId): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * Configure the factory for a specific guard book.
     */
    public function forGuardBook(GuardBook|string $guardBook): static
    {
        $guardBookId = $guardBook instanceof GuardBook ? $guardBook->id : $guardBook;

        return $this->state(fn (array $attributes) => [
            'guard_book_id' => $guardBookId,
        ]);
    }

    /**
     * Configure the factory for a specific time period.
     */
    public function forPeriod(Carbon $start, Carbon $end): static
    {
        return $this->state(fn (array $attributes) => [
            'period_start' => $start,
            'period_end' => $end,
        ]);
    }

    /**
     * Configure the factory for a specific month.
     */
    public function forMonth(int $year, int $month): static
    {
        $startDate = Carbon::create($year, $month, 1);
        $endDate = Carbon::create($year, $month, 1);

        // Carbon::create can return null, but with valid year/month it won't
        assert($startDate !== null && $endDate !== null);

        $start = $startDate->startOfMonth();
        $end = $endDate->endOfMonth();

        return $this->forPeriod($start, $end)->state(fn (array $attributes) => [
            'title' => 'Monatsbericht '.$start->translatedFormat('F Y'),
        ]);
    }

    /**
     * Configure the factory with filter criteria.
     *
     * @param  array<string, mixed>  $criteria
     */
    public function withFilterCriteria(array $criteria): static
    {
        return $this->state(fn (array $attributes) => [
            'filter_criteria' => $criteria,
        ]);
    }

    /**
     * Configure the factory with a specific user who generated the report.
     */
    public function generatedBy(User|string $user): static
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $this->state(fn (array $attributes) => [
            'generated_by_user_id' => $userId,
        ]);
    }

    /**
     * Configure the factory for draft status.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
        ]);
    }

    /**
     * Configure the factory for finalized status.
     */
    public function finalized(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'finalized',
        ]);
    }

    /**
     * Configure the factory for submitted_to_customer status.
     */
    public function submittedToCustomer(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'submitted_to_customer',
        ]);
    }

    /**
     * Configure the factory for archived status.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'archived',
        ]);
    }

    /**
     * Configure the factory with specific report data.
     *
     * @param  array<array{entry_id: string, event_type: string, occurred_at: string}>  $data
     */
    public function withReportData(array $data): static
    {
        return $this->state(fn (array $attributes) => [
            'report_data' => $data,
            'total_events' => count($data),
        ]);
    }
}
