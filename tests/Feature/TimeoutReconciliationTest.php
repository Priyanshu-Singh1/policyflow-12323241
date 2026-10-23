<?php

namespace Tests\Feature;

use App\Models\MockProviderPolicy;
use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeoutReconciliationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Case B: Timeout after successful issuance.
     * Insurer issues a policy, but the client receives a timeout.
     * The system transitions to NEEDS_RECONCILIATION, queries the provider, and recovers the existing policy.
     */
    public function test_timeout_after_issuance_transitions_to_needs_reconciliation_and_reconciles_cleanly(): void
    {
        // 1. Create intake
        $intake = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-TIMEOUT-001',
            'agent_id' => 'AGENT-88',
            'customer_name' => 'David Timeout',
            'date_of_birth' => '1985-08-20',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-09-01',
            'trip_end' => '2026-09-05',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ]);

        $intake->assertStatus(201);
        $policyId = $intake->json('data.id');

        // 2. Submit with TIMEOUT_AFTER_ISSUE scenario
        $submit = $this->withHeader('X-Mock-Scenario', 'TIMEOUT_AFTER_ISSUE')
            ->postJson("/api/policy-requests/{$policyId}/submit");

        // Asserts 504 Gateway Timeout returned
        $submit->assertStatus(504)
            ->assertJson([
                'error' => 'PROVIDER_TIMEOUT',
                'status' => 'NEEDS_RECONCILIATION',
                'policy_id' => $policyId,
            ]);

        // Verify policy is in NEEDS_RECONCILIATION status
        $policyAfterTimeout = PolicyRequest::findOrFail($policyId);
        $this->assertSame('NEEDS_RECONCILIATION', $policyAfterTimeout->status);
        $this->assertNull($policyAfterTimeout->policy_number);
        $this->assertNotNull($policyAfterTimeout->provider_reference);

        // Verify mock provider has exactly 1 policy in its ledger
        $providerPolicies = MockProviderPolicy::where('provider_reference', $policyAfterTimeout->provider_reference)->get();
        $this->assertCount(1, $providerPolicies);
        $expectedPolicyNumber = $providerPolicies->first()->policy_number;

        // 3. Trigger reconciliation
        $reconcile = $this->postJson("/api/policy-requests/{$policyId}/reconcile");

        $reconcile->assertStatus(200)
            ->assertJson([
                'status' => 'RECONCILED_ISSUED',
                'recovered_policy_number' => $expectedPolicyNumber,
                'data' => [
                    'id' => $policyId,
                    'status' => 'ISSUED',
                    'policy_number' => $expectedPolicyNumber,
                ],
            ]);

        // Verify database state: status is now ISSUED, and policy_number matches provider's
        $finalPolicy = PolicyRequest::findOrFail($policyId);
        $this->assertSame('ISSUED', $finalPolicy->status);
        $this->assertSame($expectedPolicyNumber, $finalPolicy->policy_number);

        // Assert exactly one policy was created at the provider (no duplicate re-issue)
        $this->assertSame(1, MockProviderPolicy::count());

        // 4. Calling reconcile again is safely idempotent
        $reconcileAgain = $this->postJson("/api/policy-requests/{$policyId}/reconcile");
        $reconcileAgain->assertStatus(200)
            ->assertJson([
                'status' => 'RECONCILED',
                'data' => [
                    'id' => $policyId,
                    'status' => 'ISSUED',
                ],
            ]);
    }
}
