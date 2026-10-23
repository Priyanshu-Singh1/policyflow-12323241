<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StateMachineTerminalIssuedTest extends TestCase
{
    use RefreshDatabase;

    public function test_attempt_to_resubmit_an_issued_policy_returns_409_conflict(): void
    {
        // 1. Intake
        $intake = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-TERMINAL-001',
            'agent_id' => 'AGENT-33',
            'customer_name' => 'Grace Terminal',
            'date_of_birth' => '1990-12-12',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-09-01',
            'trip_end' => '2026-09-03',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ]);
        $policyId = $intake->json('data.id');

        // 2. Submit to get ISSUED
        $submit = $this->postJson("/api/policy-requests/{$policyId}/submit", ['scenario' => 'SUCCESS']);
        $submit->assertStatus(200);
        $originalPolicyNumber = $submit->json('data.policy_number');

        // 3. Attempt to re-submit already issued policy
        $resubmit = $this->postJson("/api/policy-requests/{$policyId}/submit", ['scenario' => 'SUCCESS']);

        $resubmit->assertStatus(409)
            ->assertJson([
                'error' => 'INVALID_STATE_TRANSITION',
                'policy_id' => $policyId,
                'from_state' => 'ISSUED',
                'to_state' => 'SUBMITTED',
            ]);

        // Verify status and policy_number are completely unchanged
        $policy = PolicyRequest::findOrFail($policyId);
        $this->assertSame('ISSUED', $policy->status);
        $this->assertSame($originalPolicyNumber, $policy->policy_number);
    }
}
