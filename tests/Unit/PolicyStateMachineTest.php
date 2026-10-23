<?php

namespace Tests\Unit;

use App\Enums\PolicyStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\PolicyRequest;
use App\Services\PolicyStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected PolicyStateMachine $stateMachine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateMachine = new PolicyStateMachine();
    }

    private function createSamplePolicy(string $initialStatus = 'RECEIVED'): PolicyRequest
    {
        return PolicyRequest::create([
            'client_request_id' => 'REQ-TEST-001',
            'agent_id' => 'AGT-01',
            'customer_name' => 'Alice Tester',
            'date_of_birth' => '1995-05-15',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-06-01',
            'trip_end' => '2026-06-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'travel_days' => 5,
            'premium_amount' => 60.00,
            'currency' => 'INR',
            'status' => $initialStatus,
        ]);
    }

    public function test_valid_linear_transitions(): void
    {
        $policy = $this->createSamplePolicy('RECEIVED');

        $this->assertTrue($this->stateMachine->canTransition('RECEIVED', 'VALIDATED'));
        $this->stateMachine->transition($policy, PolicyStatus::VALIDATED, 'Validation passed');
        $this->assertSame(PolicyStatus::VALIDATED->value, $policy->fresh()->status);

        $this->assertTrue($this->stateMachine->canTransition('VALIDATED', 'SUBMITTED'));
        $this->stateMachine->transition($policy, PolicyStatus::SUBMITTED, 'Submitted to insurer');
        $this->assertSame(PolicyStatus::SUBMITTED->value, $policy->fresh()->status);

        $this->assertTrue($this->stateMachine->canTransition('SUBMITTED', 'ISSUED'));
        $this->stateMachine->transition($policy, PolicyStatus::ISSUED, 'Insurer confirmed');
        $this->assertSame(PolicyStatus::ISSUED->value, $policy->fresh()->status);

        // Verify state history entries were written
        $this->assertCount(3, $policy->fresh()->stateHistories);
    }

    public function test_forbidden_regression_from_issued_to_submitted(): void
    {
        $policy = $this->createSamplePolicy('ISSUED');

        $this->assertFalse($this->stateMachine->canTransition('ISSUED', 'SUBMITTED'));

        $this->expectException(InvalidStateTransitionException::class);
        $this->stateMachine->transition($policy, PolicyStatus::SUBMITTED, 'Attempting illegal regression');
    }

    public function test_forbidden_regression_from_issued_to_failed(): void
    {
        $policy = $this->createSamplePolicy('ISSUED');

        $this->assertFalse($this->stateMachine->canTransition('ISSUED', 'FAILED'));

        $this->expectException(InvalidStateTransitionException::class);
        $this->stateMachine->transition($policy, PolicyStatus::FAILED, 'Attempting illegal failure');
    }

    public function test_failed_state_allows_safe_retry_to_submitted(): void
    {
        $policy = $this->createSamplePolicy('FAILED');

        $this->assertTrue($this->stateMachine->canTransition('FAILED', 'SUBMITTED'));
        $this->stateMachine->transition($policy, PolicyStatus::SUBMITTED, 'Retrying failed attempt');
        $this->assertSame(PolicyStatus::SUBMITTED->value, $policy->fresh()->status);
    }

    public function test_needs_reconciliation_can_transition_to_issued(): void
    {
        $policy = $this->createSamplePolicy('NEEDS_RECONCILIATION');

        $this->assertTrue($this->stateMachine->canTransition('NEEDS_RECONCILIATION', 'ISSUED'));
        $this->stateMachine->transition($policy, PolicyStatus::ISSUED, 'Reconciled successfully');
        $this->assertSame(PolicyStatus::ISSUED->value, $policy->fresh()->status);
    }
}
