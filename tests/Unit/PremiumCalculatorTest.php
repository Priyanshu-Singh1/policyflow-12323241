<?php

namespace Tests\Unit;

use App\Enums\Destination;
use App\Enums\PlanCode;
use App\Services\PremiumCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PremiumCalculatorTest extends TestCase
{
    protected PremiumCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PremiumCalculator();
    }

    /**
     * Test the exact reference example from the assignment specification.
     * travel_days = 5, age = 28 (multiplier = 1.00), plan = PLUS (20),
     * destination = INTERNATIONAL (1.80), coverage = 300,000 (1.25)
     * Premium = 20 * 5 * 1.00 * 1.80 * 1.25 = ₹225.00
     */
    public function test_calculates_exact_reference_example_from_specification(): void
    {
        $result = $this->calculator->calculate(
            dateOfBirth: '1998-04-10',
            tripStart: '2026-11-10',
            tripEnd: '2026-11-14',
            planCode: PlanCode::PLUS,
            destination: Destination::INTERNATIONAL,
            sumInsured: 300000
        );

        $this->assertSame(5, $result['travel_days']);
        $this->assertSame(28, $result['age']);
        $this->assertSame(225.00, $result['premium']);
        $this->assertSame(20.0, $result['breakdown']['base_daily_rate']);
        $this->assertSame(1.00, $result['breakdown']['age_multiplier']);
        $this->assertSame(1.80, $result['breakdown']['destination_multiplier']);
        $this->assertSame(1.25, $result['breakdown']['coverage_multiplier']);
    }

    /**
     * Test all age tiers:
     * 0-17 -> 1.10
     * 18-60 -> 1.00
     * 61-70 -> 1.50
     * 71+ -> 2.00
     */
    public function test_age_bracket_multipliers(): void
    {
        $this->assertSame(1.10, $this->calculator->getAgeMultiplier(0));
        $this->assertSame(1.10, $this->calculator->getAgeMultiplier(17));
        $this->assertSame(1.00, $this->calculator->getAgeMultiplier(18));
        $this->assertSame(1.00, $this->calculator->getAgeMultiplier(60));
        $this->assertSame(1.50, $this->calculator->getAgeMultiplier(61));
        $this->assertSame(1.50, $this->calculator->getAgeMultiplier(70));
        $this->assertSame(2.00, $this->calculator->getAgeMultiplier(71));
        $this->assertSame(2.00, $this->calculator->getAgeMultiplier(85));
    }

    /**
     * Test all plan base rates:
     * BASIC = 12, PLUS = 20, PREMIUM = 30
     */
    public function test_plan_base_rates(): void
    {
        $this->assertSame(12.0, PlanCode::BASIC->baseDailyRate());
        $this->assertSame(20.0, PlanCode::PLUS->baseDailyRate());
        $this->assertSame(30.0, PlanCode::PREMIUM->baseDailyRate());
    }

    /**
     * Test destination multipliers:
     * DOMESTIC = 1.00, INTERNATIONAL = 1.80
     */
    public function test_destination_multipliers(): void
    {
        $this->assertSame(1.00, Destination::DOMESTIC->multiplier());
        $this->assertSame(1.80, Destination::INTERNATIONAL->multiplier());
    }

    /**
     * Test coverage multipliers:
     * 100,000 = 1.00, 300,000 = 1.25, 500,000 = 1.50
     */
    public function test_coverage_multipliers(): void
    {
        $this->assertSame(1.00, $this->calculator->getCoverageMultiplier(100000));
        $this->assertSame(1.25, $this->calculator->getCoverageMultiplier(300000));
        $this->assertSame(1.50, $this->calculator->getCoverageMultiplier(500000));
    }

    /**
     * Test single-day trip (same-day start and end = 1 day minimum inclusive).
     */
    public function test_single_day_trip_is_inclusive(): void
    {
        $result = $this->calculator->calculate(
            dateOfBirth: '2000-01-01',
            tripStart: '2026-05-01',
            tripEnd: '2026-05-01',
            planCode: PlanCode::BASIC,
            destination: Destination::DOMESTIC,
            sumInsured: 100000
        );

        $this->assertSame(1, $result['travel_days']);
        // 12 * 1 * 1.00 * 1.00 * 1.00 = 12.00
        $this->assertSame(12.00, $result['premium']);
    }

    /**
     * Test invalid date range throws InvalidArgumentException.
     */
    public function test_rejects_trip_end_before_trip_start(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate(
            dateOfBirth: '2000-01-01',
            tripStart: '2026-05-10',
            tripEnd: '2026-05-05',
            planCode: PlanCode::BASIC,
            destination: Destination::DOMESTIC,
            sumInsured: 100000
        );
    }

    /**
     * Test unsupported sum insured throws InvalidArgumentException.
     */
    public function test_rejects_unsupported_sum_insured(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate(
            dateOfBirth: '2000-01-01',
            tripStart: '2026-05-01',
            tripEnd: '2026-05-05',
            planCode: PlanCode::BASIC,
            destination: Destination::DOMESTIC,
            sumInsured: 250000
        );
    }
}
