<?php

namespace App\Services;

use App\Enums\Destination;
use App\Enums\PlanCode;
use Carbon\Carbon;
use InvalidArgumentException;

class PremiumCalculator
{
    /**
     * Calculate deterministic insurance premium.
     * Formula: base_daily_rate * travel_days * age_multiplier * destination_multiplier * coverage_multiplier
     *
     * @param string|Carbon $dateOfBirth
     * @param string|Carbon $tripStart
     * @param string|Carbon $tripEnd
     * @param string|PlanCode $planCode
     * @param string|Destination $destination
     * @param int|float $sumInsured
     * @return array{premium: float, travel_days: int, age: int, breakdown: array}
     */
    public function calculate(
        string|Carbon $dateOfBirth,
        string|Carbon $tripStart,
        string|Carbon $tripEnd,
        string|PlanCode $planCode,
        string|Destination $destination,
        int|float $sumInsured
    ): array {
        $dob = $dateOfBirth instanceof Carbon ? $dateOfBirth->copy() : Carbon::parse($dateOfBirth);
        $start = $tripStart instanceof Carbon ? $tripStart->copy() : Carbon::parse($tripStart);
        $end = $tripEnd instanceof Carbon ? $tripEnd->copy() : Carbon::parse($tripEnd);

        if ($end->lt($start)) {
            throw new InvalidArgumentException("trip_end ({$end->toDateString()}) cannot be before trip_start ({$start->toDateString()})");
        }

        // Days: Inclusive (trip_end - trip_start + 1; minimum 1)
        $travelDays = max(1, (int) $start->diffInDays($end) + 1);

        // Plan base rate
        $plan = $planCode instanceof PlanCode ? $planCode : PlanCode::tryFrom(strtoupper((string) $planCode));
        if (! $plan) {
            throw new InvalidArgumentException("Unsupported plan_code: {$planCode}");
        }
        $baseDailyRate = $plan->baseDailyRate();

        // Age multiplier on trip_start
        $age = (int) $dob->diffInYears($start);
        $ageMultiplier = $this->getAgeMultiplier($age);

        // Destination multiplier
        $dest = $destination instanceof Destination ? $destination : Destination::tryFrom(strtoupper((string) $destination));
        if (! $dest) {
            throw new InvalidArgumentException("Unsupported destination: {$destination}");
        }
        $destinationMultiplier = $dest->multiplier();

        // Coverage multiplier
        $coverageMultiplier = $this->getCoverageMultiplier((int) $sumInsured);

        // Deterministic decimal-safe calculation
        // premium = base_daily_rate * travel_days * age_multiplier * destination_multiplier * coverage_multiplier
        $raw = $baseDailyRate * $travelDays * $ageMultiplier * $destinationMultiplier * $coverageMultiplier;
        $premium = round($raw, 2);

        return [
            'premium' => $premium,
            'travel_days' => $travelDays,
            'age' => $age,
            'breakdown' => [
                'base_daily_rate' => $baseDailyRate,
                'travel_days' => $travelDays,
                'age' => $age,
                'age_multiplier' => $ageMultiplier,
                'destination_multiplier' => $destinationMultiplier,
                'coverage_multiplier' => $coverageMultiplier,
                'calculated_raw' => $raw,
            ],
        ];
    }

    public function getAgeMultiplier(int $age): float
    {
        return match (true) {
            $age <= 17 => 1.10,
            $age <= 60 => 1.00,
            $age <= 70 => 1.50,
            default => 2.00,
        };
    }

    public function getCoverageMultiplier(int $sumInsured): float
    {
        return match ($sumInsured) {
            100000 => 1.00,
            300000 => 1.25,
            500000 => 1.50,
            default => throw new InvalidArgumentException("Unsupported sum_insured: {$sumInsured}. Supported values: 100000, 300000, 500000"),
        };
    }
}
