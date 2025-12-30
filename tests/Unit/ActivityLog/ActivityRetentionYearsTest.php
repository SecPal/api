<?php

declare(strict_types=1);

namespace Tests\Unit\ActivityLog;

use App\Models\Activity;
use Tests\TestCase;

/**
 * TDD Tests for NEW getRetentionYears() method.
 *
 * Written BEFORE implementation (Test-Driven Development).
 * These tests should FAIL initially until we implement the method.
 *
 * @see https://github.com/SecPal/api/issues/441
 */
class ActivityRetentionYearsTest extends TestCase
{
    /**
     * TDD: Test 3-year retention for operational logs (BewachV §21 Abs. 4).
     *
     * Expected: FAIL (method doesn't exist yet)
     */
    public function test_returns_3_years_for_operational_logs(): void
    {
        // BewachV §21 Abs. 4: Bewachungsgewerbe-spezifische Aufzeichnungen
        // Mindestens 3 Jahre Aufbewahrung
        $this->assertSame(3, Activity::getRetentionYears('shift_management'));
        $this->assertSame(3, Activity::getRetentionYears('guard_book'));
        $this->assertSame(3, Activity::getRetentionYears('security'));
        $this->assertSame(3, Activity::getRetentionYears('authentication'));
        $this->assertSame(3, Activity::getRetentionYears('employee_changes'));
        $this->assertSame(3, Activity::getRetentionYears('rbac_changes'));
        $this->assertSame(3, Activity::getRetentionYears('scope_changes'));
        $this->assertSame(3, Activity::getRetentionYears('customer_changes'));
        $this->assertSame(3, Activity::getRetentionYears('site_management'));
        $this->assertSame(3, Activity::getRetentionYears('hr_access'));
        $this->assertSame(3, Activity::getRetentionYears('works_council_access'));
        $this->assertSame(3, Activity::getRetentionYears('sensitive_access'));
        $this->assertSame(3, Activity::getRetentionYears('guard_book_event'));
    }

    /**
     * TDD: Test 8-year retention for financial logs (HGB §257 Buchungsbelege).
     *
     * Expected: FAIL (method doesn't exist yet)
     */
    public function test_returns_8_years_for_financial_logs(): void
    {
        // HGB §257 Abs. 4: Buchungsbelege (Rechnungen, Belege)
        // Changed from 10 to 8 years in 2015
        $this->assertSame(8, Activity::getRetentionYears('invoice_generated'));
        $this->assertSame(8, Activity::getRetentionYears('payment_processed'));
        $this->assertSame(8, Activity::getRetentionYears('contract_change'));
    }

    /**
     * TDD: Test 10-year retention for archival logs (HGB §257 Jahresabschlüsse).
     *
     * Expected: FAIL (method doesn't exist yet)
     */
    public function test_returns_10_years_for_archival_logs(): void
    {
        // HGB §257 Abs. 4: Jahresabschlüsse
        // 10 years retention for annual financial statements
        $this->assertSame(10, Activity::getRetentionYears('annual_closing'));
    }

    /**
     * TDD: Test default value for unknown log types.
     *
     * Expected: FAIL (method doesn't exist yet)
     */
    public function test_returns_default_3_years_for_unknown_log_types(): void
    {
        // Unknown log types should default to minimum legal requirement (3 years)
        $this->assertSame(3, Activity::getRetentionYears('unknown_log_type'));
        $this->assertSame(3, Activity::getRetentionYears(''));
        $this->assertSame(3, Activity::getRetentionYears('new_feature_log'));
    }

    /**
     * TDD: Test that 'default' key exists and maps to 3 years.
     *
     * Expected: FAIL (method doesn't exist yet)
     */
    public function test_default_key_returns_3_years(): void
    {
        // The 'default' key should explicitly map to 3 years
        $this->assertSame(3, Activity::getRetentionYears('default'));
    }

    /**
     * TDD: Test that all retention values are valid integers.
     *
     * Expected: FAIL (method doesn't exist yet)
     */
    public function test_all_retention_years_are_valid_integers(): void
    {
        $retentionYears = Activity::getRetentionYears();

        $this->assertIsArray($retentionYears);
        $this->assertNotEmpty($retentionYears);

        foreach ($retentionYears as $logName => $years) {
            $this->assertIsString($logName, "Log name must be string: {$logName}");
            $this->assertIsInt($years, "Retention years must be integer for: {$logName}");
            $this->assertGreaterThanOrEqual(3, $years, "Retention must be >= 3 years for: {$logName}");
            $this->assertLessThanOrEqual(10, $years, "Retention must be <= 10 years for: {$logName}");
        }
    }

    /**
     * TDD: Test that retention years array has legal references in docblock.
     *
     * This test verifies that our code is legally documented.
     *
     * Expected: FAIL (property doesn't exist yet)
     */
    public function test_retention_years_property_has_legal_references(): void
    {
        $reflection = new \ReflectionClass(Activity::class);
        
        // Check if property exists
        $this->assertTrue(
            $reflection->hasProperty('retentionYears'),
            'Activity class must have $retentionYears property'
        );

        $property = $reflection->getProperty('retentionYears');
        $docComment = $property->getDocComment();

        $this->assertNotFalse($docComment, 'Property must have docblock');

        // Verify legal references
        $this->assertStringContainsString(
            'BewachV §21',
            $docComment,
            'Docblock must reference BewachV §21 for 3-year retention'
        );

        $this->assertStringContainsString(
            'HGB §257',
            $docComment,
            'Docblock must reference HGB §257 for 8/10-year retention'
        );

        $this->assertStringContainsString(
            'AO §147',
            $docComment,
            'Docblock must reference AO §147 for tax-relevant documents'
        );
    }

    /**
     * TDD: Test that getRetentionYears() method exists and is static.
     *
     * Expected: FAIL (method doesn't exist yet)
     */
    public function test_get_retention_years_method_exists_and_is_static(): void
    {
        $reflection = new \ReflectionClass(Activity::class);

        $this->assertTrue(
            $reflection->hasMethod('getRetentionYears'),
            'Activity class must have getRetentionYears() method'
        );

        $method = $reflection->getMethod('getRetentionYears');

        $this->assertTrue(
            $method->isStatic(),
            'getRetentionYears() must be static'
        );

        $this->assertTrue(
            $method->isPublic(),
            'getRetentionYears() must be public'
        );
    }

    /**
     * TDD: Test method signature for getRetentionYears().
     *
     * Expected: FAIL (method doesn't exist yet)
     */
    public function test_get_retention_years_accepts_string_parameter(): void
    {
        $reflection = new \ReflectionClass(Activity::class);
        $method = $reflection->getMethod('getRetentionYears');

        $parameters = $method->getParameters();

        // When called with parameter: getRetentionYears('log_name')
        if (count($parameters) > 0) {
            $this->assertCount(1, $parameters, 'Should accept exactly 1 parameter');
            $this->assertSame('logName', $parameters[0]->getName());
        }

        // Should also work when called without parameter to get all: getRetentionYears()
        // This will return the array
    }

    /**
     * TDD: Test that calling getRetentionYears() without parameter returns array.
     *
     * Expected: FAIL (method doesn't exist yet)
     */
    public function test_get_retention_years_without_parameter_returns_array(): void
    {
        $allRetentionYears = Activity::getRetentionYears();

        $this->assertIsArray($allRetentionYears);
        $this->assertNotEmpty($allRetentionYears);
        $this->assertArrayHasKey('default', $allRetentionYears);
        $this->assertArrayHasKey('shift_management', $allRetentionYears);
    }

    /**
     * TDD: Test that we have at least as many retention periods as old security levels.
     *
     * Expected: FAIL (method doesn't exist yet)
     */
    public function test_has_retention_period_for_all_old_security_levels(): void
    {
        $oldSecurityLevels = Activity::getSecurityLevels();
        $newRetentionYears = Activity::getRetentionYears();

        // Every old log type must have a new retention period
        foreach (array_keys($oldSecurityLevels) as $logName) {
            $this->assertArrayHasKey(
                $logName,
                $newRetentionYears,
                "Log type '{$logName}' must have retention period defined"
            );
        }
    }
}
